<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Credit back the part of an already-issued invoice a terminating lease will never earn
 * (story MF-02, scenario S8).
 *
 * **The gap this closes.** Rent is billed in advance on the 1st. A tenant terminating on the 18th
 * has already been invoiced for the whole month, and trailing proration cannot help — that invoice
 * exists, it is in AR, it may be paid, and it is very possibly filed with the tax authority. The
 * only correct instrument is a credit note, and until now raising it was a manual act somebody had
 * to remember: S8's "the fix is a manual credit note".
 *
 * **The split uses the same rule the invoice used.** `MonthlyBillingService::monthsCovered()` is
 * shared, so the credit is the exact complement of what was billed rather than an independent
 * day-count that would drift from it by a day or two on every quarter-billed lease.
 *
 * **It credits, it never deletes.** A partially-paid or ETA-filed invoice is untouchable by design
 * (`DeletionPolicy`, and the existing refusal in `LeaseTerminationService`); a credit note leaves
 * both documents standing and an auditor able to follow the money. The note is issued and then
 * applied to its own invoice: what fits reduces the balance, and any excess — the normal case for
 * an invoice already paid — stays as tenant credit to refund or offset, which is exactly what the
 * final account then settles (MF-03).
 */
class CreditUnearnedBillingService
{
    /**
     * @return array<int, CreditNote> one note per over-billed invoice (empty when nothing is owed back)
     */
    public function forTermination(Lease $lease, CarbonImmutable $terminationDate): array
    {
        $terminationDate = $terminationDate->startOfDay();

        $invoices = Invoice::query()
            ->where('lease_id', $lease->id)
            // Cancelled and written-off invoices claim nothing, so there is nothing to give back.
            ->whereIn('status', ['draft', 'issued', 'partially_paid', 'overdue', 'paid'])
            ->whereNotNull('period_start')
            ->whereNotNull('period_end')
            // Only invoices that reach BEYOND the termination date have an unearned part.
            ->whereDate('period_end', '>', $terminationDate->toDateString())
            ->whereDate('period_start', '<=', $terminationDate->toDateString())
            ->with('items')
            ->get();

        $notes = [];

        foreach ($invoices as $invoice) {
            $note = $this->creditUnearnedPortion($lease, $invoice, $terminationDate);

            if ($note !== null) {
                $notes[] = $note;
            }
        }

        return $notes;
    }

    private function creditUnearnedPortion(Lease $lease, Invoice $invoice, CarbonImmutable $terminationDate): ?CreditNote
    {
        $periodStart = CarbonImmutable::instance($invoice->period_start)->startOfDay();
        $periodEnd = CarbonImmutable::instance($invoice->period_end)->startOfDay();

        $cycleMonths = ($periodEnd->year - $periodStart->year) * 12
            + ($periodEnd->month - $periodStart->month) + 1;

        $billed = MonthlyBillingService::monthsCovered($periodStart->startOfMonth(), $cycleMonths, $periodStart, $periodEnd);
        $earned = MonthlyBillingService::monthsCovered($periodStart->startOfMonth(), $cycleMonths, $periodStart, $terminationDate);

        if ($billed <= 0) {
            return null;
        }

        $unearnedRatio = 1 - ($earned / $billed);

        if ($unearnedRatio <= 0) {
            return null;
        }

        $subtotal = 0.0;
        $vat = 0.0;

        /** @var InvoiceItem $item */
        foreach ($invoice->items as $item) {
            // ONE-OFF lines are not time-apportioned and must not be clawed back: a CAM true-up, a
            // percentage-rent overage, a utility recharge or a fine is earned in full for something
            // that already happened. Crediting them pro-rata would refund the tenant for water they
            // used and damage they caused.
            if (! $this->isTimeApportioned($item)) {
                continue;
            }

            $subtotal += (float) $item->amount * $unearnedRatio;
            $vat += (float) $item->vat_amount * $unearnedRatio;
        }

        $subtotal = round($subtotal, 2);
        $vat = round($vat, 2);
        $total = round($subtotal + $vat, 2);

        if ($total <= 0) {
            return null;
        }

        return DB::transaction(function () use ($lease, $invoice, $terminationDate, $subtotal, $vat, $total, $periodEnd) {
            $note = CreditNote::create([
                'tenant_id' => $lease->tenant_id,
                'lease_id' => $lease->id,
                'invoice_id' => $invoice->id,
                'status' => 'draft',
                // The date the tenancy ended is the date the revenue stops being earned, so it is
                // the date the reversal belongs in the books. A closed period refuses here, via
                // CreditNoteService::issue() — correctly, and loudly, rather than committing the
                // termination and losing the credit inside a best-effort job.
                'issue_date' => $terminationDate,
                'reason' => 'adjustment',
                'reason_notes' => __('admin.credit_notes.unearned_on_termination', [
                    'invoice' => $invoice->number,
                    'date' => $terminationDate->format('d/m/Y'),
                    'through' => $periodEnd->format('d/m/Y'),
                ]),
                'subtotal' => $subtotal,
                'vat_amount' => $vat,
                'total' => $total,
                'applied_amount' => 0,
                'balance' => $total,
                'currency' => $invoice->currency ?? 'EGP',
            ]);

            $service = app(CreditNoteService::class);
            $service->issue($note);

            // Apply what the invoice can absorb. An unpaid invoice simply owes less; a PAID one
            // absorbs nothing and the whole note stays as tenant credit — which is the honest
            // outcome, because the money is genuinely with the landlord and has to come back.
            $service->applyToInvoice($note->fresh(), $invoice->fresh());

            return $note->fresh();
        });
    }

    /**
     * Is this line rent-like — earned across the period rather than for a discrete event?
     *
     * Read from the CHARGE where there is one, because the charge knows its own frequency. Lines
     * with no charge behind them are the one-offs the billing engine attaches directly (a CAM
     * recovery, a percentage-rent overage), and those are never time-apportioned.
     */
    private function isTimeApportioned(InvoiceItem $item): bool
    {
        if (blank($item->charge_id)) {
            return false;
        }

        /** @var \App\Models\Charge|null $charge */
        $charge = $item->charge;

        return $charge?->frequency === 'monthly';
    }
}
