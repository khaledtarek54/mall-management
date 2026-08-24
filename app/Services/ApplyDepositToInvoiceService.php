<?php

namespace App\Services;

use App\Models\DepositApplication;
use App\Models\Invoice;
use App\Models\Lease;
use App\Support\PostingDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Net a security deposit against a tenant's unpaid invoice (story MF-03, scenario S8).
 *
 * Yardi's move-out disposition nets the deposit against arrears and damages on one document:
 * *540,000 − 120,000 unpaid − 35,000 damages = 385,000 refunded.* Atriom could report that position
 * but not act on it — the operator settled the invoices by some other route and hoped the two acts
 * agreed.
 *
 * **It is a fourth channel into `Invoice::recomputeTotals()`**, whose invariant is
 * `paid_amount = captured payments + credit applied + tenant credit applied + deposit applied`.
 * That is the most protected rule in this codebase, so this follows the established shape exactly:
 * its own document, its own journalizer (Dr Deposits Held / Cr AR), soft-delete as the reversal, and
 * `recomputeTotals()` as the only thing that ever writes `paid_amount`.
 *
 * **Never posts to income.** A `forfeit` credits Misc Income because the landlord KEEPS the money;
 * an application settles a receivable whose revenue was already recognised when the invoice was
 * raised. Crediting income here would recognise the same rent twice.
 */
class ApplyDepositToInvoiceService
{
    public function __construct(private MoveOutStatementService $statements) {}

    /**
     * Apply up to `$requested` of the lease's held deposit to one invoice.
     *
     * Capped at the smaller of the invoice's balance and the deposit actually held, so it can
     * neither over-settle an invoice nor spend a deposit that is not there.
     *
     * @return float the amount actually applied (0.00 = nothing to do)
     */
    public function apply(Lease $lease, Invoice $invoice, ?float $requested = null, ?CarbonImmutable $on = null): float
    {
        if ((int) $invoice->lease_id !== (int) $lease->id) {
            throw new InvalidArgumentException('That invoice belongs to a different lease.');
        }

        // Cancelled and written-off invoices claim no AR, so there is nothing for a deposit to
        // settle — and applying to one would credit AR that was already relieved.
        if (in_array($invoice->status, ['cancelled', 'written_off'], true)) {
            throw new InvalidArgumentException('A cancelled or written-off invoice has no balance to settle.');
        }

        $on = ($on ?? CarbonImmutable::now())->startOfDay();

        // `$on` IS operator-typed, whatever the registry used to claim. `SettleMoveOutService`
        // passes the `settlement_date` off an unconstrained DatePicker on the Lease resource, so
        // a settlement can be back-dated into a closed March: the arrears net off the deposit, AR
        // closes, "Saved ✓" — and the GL post is silently refused inside the best-effort sync job,
        // leaving a tie-out gap the size of the settlement.
        //
        // A MISSING period is fine (PostingDate::assertOpen allows it); only a CLOSED one is
        // refused. Guarded here rather than in the caller because this is the service that stamps
        // the date onto the row that becomes the entry.
        PostingDate::assertOpen($on, __('admin.lease_events.effective'));

        return DB::transaction(function () use ($lease, $invoice, $requested, $on): float {
            // Lock the invoice and re-read inside the transaction: two concurrent settlements would
            // otherwise each see the full balance and together over-apply the deposit.
            /** @var Invoice $locked */
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            $held = $this->statements->depositHeld($lease);
            $amount = round(min(
                (float) $locked->balance,
                $held,
                $requested !== null ? $requested : PHP_FLOAT_MAX,
            ), 2);

            if ($amount <= 0) {
                return 0.0;
            }

            $application = DepositApplication::create([
                'lease_id' => $lease->id,
                'tenant_id' => $lease->tenant_id,
                'invoice_id' => $locked->id,
                // The INVOICE's own column, not `lease->unit->asset_id`. Every sibling settlement
                // document was moved off that chain after the credit-note fail-open (2026-08-18):
                // it answers null for an agreement that holds no unit, and `deposit_applications
                // .asset_id` is nullable, so a null would be stored SILENTLY and the journalizer —
                // which files BOTH legs from this column — would debit `deposits_held` under no
                // property while the AR it settles was raised under one.
                'asset_id' => $locked->asset_id,
                'amount' => $amount,
                // Stamped now, never the receipt's date — see PostingDateGuards for why.
                'entry_date' => $on->toDateString(),
                'created_by' => auth()->id(),
                'notes' => __('admin.move_out.application_notes', ['invoice' => $locked->number]),
            ]);

            // The single source of truth re-derives paid_amount and balance; nothing here writes
            // either directly.
            $locked->recomputeTotals();

            return (float) $application->amount;
        });
    }

    /**
     * Reverse an application — the AR re-opens and the deposit balance returns.
     *
     * Soft-delete, exactly as `TenantCreditApplication` reverses: `LedgerPoster::sync` sees a
     * trashed source, voids its entry, and the next recompute drops the amount from `paid_amount`.
     */
    public function reverse(DepositApplication $application): void
    {
        DB::transaction(function () use ($application): void {
            $invoice = $application->invoice;

            $application->delete();

            $invoice?->fresh()->recomputeTotals();
        });
    }

    /**
     * Settle as much of a lease's open AR as the deposit covers, oldest invoice first.
     *
     * Oldest-first because that is how every other allocation in this system works and how a tenant
     * expects arrears to clear; leaving the operator to choose would make two move-outs with the
     * same numbers settle differently.
     *
     * @return array{applied: float, invoices: int}
     */
    public function settleOpenAr(Lease $lease, ?CarbonImmutable $on = null): array
    {
        $applied = 0.0;
        $count = 0;

        $open = Invoice::query()
            ->where('lease_id', $lease->id)
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->where('balance', '>', 0)
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        foreach ($open as $invoice) {
            // Re-checked per invoice: the deposit shrinks as it is spent, and the last invoice in
            // the list may only be partly covered.
            if ($this->statements->depositHeld($lease) <= 0) {
                break;
            }

            $amount = $this->apply($lease, $invoice, null, $on);

            if ($amount > 0) {
                $applied += $amount;
                $count++;
            }
        }

        return ['applied' => round($applied, 2), 'invoices' => $count];
    }
}
