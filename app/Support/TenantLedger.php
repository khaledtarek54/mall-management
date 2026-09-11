<?php

namespace App\Support;

use App\Models\CreditNote;
use App\Models\CreditNoteApplication;
use App\Models\DepositApplication;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceWriteOff;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Tenant;
use App\Models\TenantCreditApplication;
use Carbon\CarbonImmutable;
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
 * Yardi answers it with a tenant ledger, and this is that: debit and credit interleaved by date,
 * each line naming its own document.
 *
 * **Since 2026-09-11 the Statement of Account PDF is this ledger printed** ({@see statement()}),
 * with a balance brought forward — the client's كشف حساب: *date · reference · description · debit ·
 * credit · balance*. Before that the PDF was a different document (open invoices, credits,
 * payments and "other settlements" as separate tables), so the screen and the paper disagreed in
 * shape and could disagree in figure; now they are one derivation.
 *
 * **Nothing here is stored.** Every row is derived from the documents themselves, and the closing
 * balance is asserted to equal what the invoices say is still COLLECTABLE — the same figure the
 * statement headline, the AR report and `billing:reconcile` produce. A stored running balance would
 * be a second truth about money that already has one, and the first thing anyone would notice is
 * that it disagreed.
 *
 * ## What counts as a movement
 *
 * A debit is an ISSUED invoice, **one row per line** (Yardi's charge-code grain: rent, service
 * charge and a late fee on one invoice are three lines a tenant reads separately, each worded by
 * {@see LineNarrative} in the reader's language). An invoice with no lines — an
 * imported one — falls back to a single row at its total. The four settlement channels are what
 * reduce it, plus a WRITE-OFF: a forgiven slice is not a settlement (it never reaches
 * `paid_amount`) but it is money the tenant is no longer asked for, and a ledger that closed above
 * the collectable figure was a ledger nobody could reconcile to the statement beside it.
 *
 * Cancelled invoices are excluded — they claim nothing. A DRAFT is excluded for the harder reason:
 * it is not a document yet, and the tenant has never seen it. A `written_off` or legacy `credited`
 * invoice is INCLUDED, with the write-off or the credit note that relieved it beside it: the tenant
 * was asked for that money and then not, and a statement that lost the charge kept the relief —
 * the credit-note row is keyed on the tenant, not the invoice — so the balance dipped by exactly
 * the relief with no debit above it to explain it.
 */
class TenantLedger
{
    /**
     * Every movement with a running balance, oldest first — the on-screen ledger tab.
     *
     * @param  array<int>|null  $visibleAssetIds  restrict to these properties (admin surface)
     * @return Collection<int, array{date: CarbonInterface, type: string, reference: string, description: string, debit: float, credit: float, balance: float, model: mixed}>
     */
    public static function for(Tenant $tenant, ?array $visibleAssetIds = null): Collection
    {
        return self::withRunningBalance(self::sorted(self::rows($tenant, $visibleAssetIds)), 0.0);
    }

    /**
     * The ledger over a WINDOW, with the balance brought forward — what the Statement of Account
     * prints.
     *
     * Rows dated before `$since` fold into `opening`; rows in the window carry a running balance
     * that starts there, so the last row's balance is what the tenant owes at `$upTo` — the same
     * arithmetic a bank statement shows, and the same rule `LedgerReportService::accountLedger()`
     * applies to a GL account. `$upTo` null means everything (an invoice issued in advance is a
     * first-class state on a statement — see `TenantStatementPdfService`), which is why the upper
     * bound is optional and the lower one is not.
     *
     * @param  array<int>|null  $visibleAssetIds
     * @return array{opening: float, rows: Collection<int, array<string, mixed>>, closing: float}
     */
    public static function statement(Tenant $tenant, ?array $visibleAssetIds, CarbonInterface $since, ?CarbonInterface $upTo = null): array
    {
        $since = CarbonImmutable::instance($since)->startOfDay();
        $all = self::sorted(self::rows($tenant, $visibleAssetIds, $upTo));

        [$before, $within] = $all->partition(fn (array $row): bool => $row['date'] !== null && $row['date']->lt($since));

        $opening = round((float) $before->sum('debit') - (float) $before->sum('credit'), 2);
        $rows = self::withRunningBalance($within->values(), $opening);

        return [
            'opening' => $opening,
            'rows' => $rows,
            'closing' => (float) ($rows->last()['balance'] ?? $opening),
        ];
    }

    /** The closing balance — what the tenant owes, from the ledger's own arithmetic. */
    public static function closingBalance(Tenant $tenant, ?array $visibleAssetIds = null): float
    {
        return (float) (self::for($tenant, $visibleAssetIds)->last()['balance'] ?? 0.0);
    }

    /**
     * The movements themselves, unordered and unbalanced.
     *
     * @param  array<int>|null  $visibleAssetIds
     * @param  CarbonInterface|null  $upTo  drop anything dated after this day (a bounded statement)
     * @return Collection<int, array<string, mixed>>
     */
    protected static function rows(Tenant $tenant, ?array $visibleAssetIds = null, ?CarbonInterface $upTo = null): Collection
    {
        $rows = collect();
        $upTo = $upTo ? CarbonImmutable::instance($upTo)->endOfDay() : null;

        $invoices = Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->when($visibleAssetIds !== null, fn ($q) => $q->whereIn('asset_id', $visibleAssetIds))
            ->with(['items' => fn ($q) => $q->orderBy('id')])
            ->withCount('writeOffs')
            ->get()
            // A `written_off` or `credited` invoice is listed WITH the movement that relieved it.
            // One that carries none — an imported row whose relief was never recorded here — would
            // print as a debit nobody ever relieved, so it stays out as it always did: it claims
            // nothing, and there is nothing to show beside it. `credit_applied_amount` is the
            // stored channel figure `recomputeTotals()` reads, so it is the relief's own record.
            ->reject(fn (Invoice $invoice): bool => ($invoice->status === 'written_off' && (int) $invoice->write_offs_count === 0)
                || ($invoice->status === 'credited' && (float) $invoice->credit_applied_amount <= 0))
            ->values();

        $sequence = 0;

        foreach ($invoices as $invoice) {
            // One row per LINE, worded for the reader. An invoice that carries no lines (imported)
            // is one row at its total, described by its period — what every row was until
            // 2026-09-11.
            $lines = $invoice->items->isNotEmpty()
                ? $invoice->items->map(fn (InvoiceItem $item): array => [
                    'description' => $item->narrative(),
                    'debit' => round((float) $item->total, 2),
                ])
                : collect([['description' => $invoice->periodLabel(), 'debit' => round((float) $invoice->total, 2)]]);

            // The lines must sum to the document, or the ledger closes off the invoice's own
            // figure. They do on every invoice the system raised (`total` is Σ items at 2dp); an
            // imported header can disagree with its lines, and the difference is one more row
            // under the period rather than a balance nobody can reconcile.
            $residual = round((float) $invoice->total - (float) $lines->sum('debit'), 2);
            if (abs($residual) >= 0.01) {
                $lines->push(['description' => $invoice->periodLabel(), 'debit' => $residual]);
            }

            foreach ($lines as $line) {
                $rows->push([
                    'date' => $invoice->issue_date,
                    'type' => 'invoice',
                    'reference' => $invoice->number,
                    'description' => $line['description'],
                    'debit' => $line['debit'],
                    'credit' => 0.0,
                    'model' => $invoice,
                    'sequence' => $sequence++,
                ]);
            }
        }

        $invoiceIds = $invoices->pluck('id');
        $numbers = $invoices->pluck('number', 'id');

        // Channel 1 — cash. Allocated, not the payment's face value: a single payment can settle
        // several tenants' worth of nothing, but it can settle several INVOICES, and only the part
        // landing on this tenant's invoices belongs on this ledger. ONE row per receipt, naming the
        // invoices it settled and the rail it arrived on — a receipt that settles three invoices
        // is one piece of paper, and three rows carrying one receipt number read as three receipts.
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
                    'description' => __('admin.ledger.payment_description', [
                        'method' => PaymentMethod::labelFor($payment->method),
                        'invoices' => $payment->invoices->pluck('number')->filter()->implode(', '),
                    ]),
                    'debit' => 0.0,
                    'credit' => $allocated,
                    'model' => $payment,
                ]);
            });

        // Channel 2 — credit notes, at what was APPLIED, one row per APPLICATION. The record of
        // an application is `credit_note_applications` — a note raised with no `invoice_id` (every
        // negative CAM true-up, and any note the operator applies to a different invoice than the
        // one it names) is applied there and nowhere else, so keying on `credit_notes.invoice_id`
        // dropped exactly those and the ledger closed above the collectable figure by their
        // amount (found by the review of this change). An unapplied note is money owed back but
        // not yet netted, so it has not moved the receivable and has no row.
        //
        // Reconciled to the INVOICE's own `credit_applied_amount` — the figure `recomputeTotals()`
        // settles with — so an imported invoice carrying that figure and no application rows still
        // shows its relief, as one row, and the closing balance ties out by construction.
        $applications = CreditNoteApplication::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->with('creditNote')
            ->get()
            ->groupBy('invoice_id');

        foreach ($invoices as $invoice) {
            $itemised = 0.0;

            foreach ($applications->get($invoice->id, collect()) as $application) {
                $note = $application->creditNote;
                $itemised += (float) $application->amount;

                $rows->push([
                    'date' => $application->applied_at ?? $note?->issue_date ?? $application->created_at,
                    'type' => 'credit_note',
                    'reference' => $note?->number ?? '',
                    'description' => __('admin.ledger.credit_note_description', [
                        'reason' => $note?->reason ? Translate::orHumanized('admin.enums.credit_note_reason.'.$note->reason, $note->reason) : '—',
                        'invoice' => $invoice->number,
                    ]),
                    'debit' => 0.0,
                    'credit' => round((float) $application->amount, 2),
                    'model' => $note,
                ]);
            }

            $stored = round((float) $invoice->credit_applied_amount - $itemised, 2);
            if ($stored >= 0.01) {
                // The legacy shape: relief recorded on the invoice with no application row behind
                // it. The note that names this invoice, if one does, gives the row its number.
                $legacy = CreditNote::query()->where('invoice_id', $invoice->id)->where('applied_amount', '>', 0)->first();

                $rows->push([
                    'date' => $legacy?->issue_date ?? $invoice->issue_date,
                    'type' => 'credit_note',
                    'reference' => $legacy?->number ?? '',
                    'description' => __('admin.ledger.credit_note_description', [
                        'reason' => $legacy?->reason ? Translate::orHumanized('admin.enums.credit_note_reason.'.$legacy->reason, $legacy->reason) : '—',
                        'invoice' => $invoice->number,
                    ]),
                    'debit' => 0.0,
                    'credit' => $stored,
                    'model' => $legacy,
                ]);
            }
        }

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
                'description' => __('admin.ledger.from_tenant_credit').' — '.($numbers[$application->invoice_id] ?? '—'),
                'debit' => 0.0,
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
                'description' => __('admin.ledger.from_deposit').' — '.($numbers[$application->invoice_id] ?? '—'),
                'debit' => 0.0,
                'credit' => round((float) $application->amount, 2),
                'model' => null,
            ]));

        // Not a channel — a WRITE-OFF is forgiveness, not settlement, and `paid_amount` never sees
        // it. But the tenant is no longer asked for it, and every collections read in the system
        // uses `collectableBalance()` (balance net of write-offs); a ledger that stopped at the raw
        // balance closed ABOVE the statement headline printed beside it, by exactly the forgiven
        // slice. The relation soft-deletes, so a reversed write-off drops out on its own.
        InvoiceWriteOff::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->get()
            ->each(fn (InvoiceWriteOff $writeOff) => $rows->push([
                'date' => $writeOff->entry_date ?? $writeOff->created_at,
                'type' => 'write_off',
                'reference' => '',
                'description' => __('admin.ledger.written_off').' — '.($numbers[$writeOff->invoice_id] ?? '—'),
                'debit' => 0.0,
                'credit' => round((float) $writeOff->amount, 2),
                'model' => null,
            ]));

        return $upTo === null
            ? $rows
            : $rows->filter(fn (array $row): bool => $row['date'] === null || ! $row['date']->gt($upTo))->values();
    }

    /**
     * Oldest first, because a running balance only reads in one direction. Ties break on the
     * debit: an invoice raised and settled the same day must show the debt before the payment,
     * or the balance dips negative on the way to the same answer.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    protected static function sorted(Collection $rows): Collection
    {
        // Then by reference and by the line's own order, so one invoice's lines stay together —
        // at line grain a debit-first tie-break alone interleaved two same-day invoices by amount
        // (A, B, A — found by the review of this change).
        return $rows
            ->sortBy([
                fn (array $a, array $b) => ($a['date'] ?? null) <=> ($b['date'] ?? null),
                fn (array $a, array $b) => ($b['debit'] > 0 ? 1 : 0) <=> ($a['debit'] > 0 ? 1 : 0),
                fn (array $a, array $b) => strcmp((string) $a['reference'], (string) $b['reference']),
                fn (array $a, array $b) => ($a['sequence'] ?? 0) <=> ($b['sequence'] ?? 0),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    protected static function withRunningBalance(Collection $rows, float $opening): Collection
    {
        $balance = $opening;

        return $rows->map(function (array $row) use (&$balance) {
            $balance = round($balance + $row['debit'] - $row['credit'], 2);
            $row['balance'] = $balance;

            return $row;
        });
    }
}
