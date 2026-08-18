<?php

namespace App\Support;

use App\Models\CreditNote;
use App\Models\DepositApplication;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantCreditApplication;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * One chronological list of everything that moved a tenant's receivable, with a running balance.
 *
 * **The gap this closes.** "What does this tenant owe, and how did it get there?" is a daily
 * question, and the only document that answered it was a PDF you had to download — the Statement of
 * Account. On screen the two halves sat in separate tabs: invoices in one, payments in another,
 * with nothing netting them and no order between them. An operator on a collections call had to
 * hold both in their head.
 *
 * Yardi answers it with a tenant ledger, and this is that: charge and credit interleaved by date,
 * each line naming its own document.
 *
 * **Nothing here is stored.** Every row is derived from the documents themselves, and the closing
 * balance is asserted to equal the sum of open invoice balances — the same figure the statement, the
 * AR report and `billing:reconcile` produce. A stored running balance would be a second truth about
 * money that already has one, and the first thing anyone would notice is that it disagreed.
 *
 * ## What counts as a movement
 *
 * A charge is an ISSUED invoice; the four settlement channels are what reduce it. Cancelled,
 * credited and written-off invoices are excluded — they claim nothing, so a ledger that listed them
 * would show a debt the tenant does not have. A DRAFT invoice is excluded for the harder reason:
 * it is not a document yet, and the tenant has never seen it.
 */
class TenantLedger
{
    /**
     * @param  array<int>|null  $visibleAssetIds  restrict to these properties (admin surface)
     * @return Collection<int, array{date: CarbonInterface, type: string, reference: string, description: string, charge: float, credit: float, balance: float, url: ?string}>
     */
    public static function for(Tenant $tenant, ?array $visibleAssetIds = null): Collection
    {
        $rows = collect();

        $invoices = Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotIn('status', ['draft', 'cancelled', 'credited', 'written_off'])
            ->when($visibleAssetIds !== null, fn ($q) => $q->whereIn('asset_id', $visibleAssetIds))
            ->get(['id', 'number', 'issue_date', 'total', 'period_start', 'period_end']);

        foreach ($invoices as $invoice) {
            $rows->push([
                'date' => $invoice->issue_date,
                'type' => 'invoice',
                'reference' => $invoice->number,
                'description' => $invoice->periodLabel(),
                'charge' => round((float) $invoice->total, 2),
                'credit' => 0.0,
                'model' => $invoice,
            ]);
        }

        $invoiceIds = $invoices->pluck('id');

        // Channel 1 — cash. Allocated, not the payment's face value: a single payment can settle
        // several tenants' worth of nothing, but it can settle several INVOICES, and only the part
        // landing on this tenant's invoices belongs on this ledger.
        Payment::query()
            ->whereIn('payments.status', Payment::RECEIVED_STATUSES)
            ->whereHas('invoices', fn ($q) => $q->whereIn('invoices.id', $invoiceIds))
            ->with(['invoices' => fn ($q) => $q->whereIn('invoices.id', $invoiceIds)])
            ->get()
            ->each(function (Payment $payment) use ($rows) {
                $allocated = round((float) $payment->invoices->sum(fn ($i) => (float) $i->pivot->allocated_amount), 2);

                if ($allocated <= 0) {
                    return;
                }

                $rows->push([
                    'date' => $payment->payment_date,
                    'type' => 'payment',
                    'reference' => $payment->reference ?? '',
                    'description' => __('admin.enums.method.'.$payment->method, [], app()->getLocale()),
                    'charge' => 0.0,
                    'credit' => $allocated,
                    'model' => $payment,
                ]);
            });

        // Channel 2 — credit notes, at what was APPLIED. An unapplied note is money owed back but
        // not yet netted, so it has not moved the receivable.
        CreditNote::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['issued', 'partially_paid', 'applied'])
            ->where('applied_amount', '>', 0)
            ->when($visibleAssetIds !== null, fn ($q) => $q->whereIn('asset_id', $visibleAssetIds))
            ->get()
            ->each(fn (CreditNote $note) => $rows->push([
                'date' => $note->issue_date,
                'type' => 'credit_note',
                'reference' => $note->number ?? '',
                'description' => $note->reason ? __('admin.enums.credit_note_reason.'.$note->reason, [], app()->getLocale()) : '',
                'charge' => 0.0,
                'credit' => round((float) $note->applied_amount, 2),
                'model' => $note,
            ]));

        // Channels 3 and 4 — tenant credit spent, and a deposit netted at move-out. Both settle an
        // invoice without any cash moving, which is exactly why a ledger that omitted them would
        // stop tying out to the invoices it lists.
        TenantCreditApplication::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->get()
            ->each(fn ($application) => $rows->push([
                'date' => $application->applied_at ?? $application->created_at,
                'type' => 'tenant_credit',
                'reference' => '',
                'description' => __('admin.ledger.from_tenant_credit'),
                'charge' => 0.0,
                'credit' => round((float) $application->amount, 2),
                'model' => null,
            ]));

        DepositApplication::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->get()
            ->each(fn ($application) => $rows->push([
                'date' => $application->entry_date ?? $application->created_at,
                'type' => 'deposit',
                'reference' => '',
                'description' => __('admin.ledger.from_deposit'),
                'charge' => 0.0,
                'credit' => round((float) $application->amount, 2),
                'model' => null,
            ]));

        // Oldest first, because a running balance only reads in one direction. Ties break on the
        // charge: an invoice raised and settled the same day must show the debt before the payment,
        // or the balance dips negative on the way to the same answer.
        $balance = 0.0;

        return $rows
            ->sortBy([
                fn (array $a, array $b) => ($a['date'] ?? null) <=> ($b['date'] ?? null),
                fn (array $a, array $b) => $b['charge'] <=> $a['charge'],
            ])
            ->values()
            ->map(function (array $row) use (&$balance) {
                $balance = round($balance + $row['charge'] - $row['credit'], 2);
                $row['balance'] = $balance;

                return $row;
            });
    }

    /** The closing balance — what the tenant owes, from the ledger's own arithmetic. */
    public static function closingBalance(Tenant $tenant, ?array $visibleAssetIds = null): float
    {
        return (float) (self::for($tenant, $visibleAssetIds)->last()['balance'] ?? 0.0);
    }
}
