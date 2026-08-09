<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Lease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LeaseTerminationService
{
    /**
     * Terminate an active lease early.
     *
     * - Marks lease status = 'terminated', stores termination date + reason
     * - Unit status is recomputed by LeaseObserver — falls to 'reserved' if
     *   another draft/pending lease exists on the unit, else 'vacant'
     * - Deactivates the lease's recurring charges (is_active = false)
     * - Optionally cancels open invoices (status = 'cancelled', balance = 0)
     * - Credits back the unearned part of any invoice billed past the termination date (MF-02)
     *
     * @param array{termination_date:string|\DateTimeInterface|null, reason:string|null, cancel_open_invoices?:bool, credit_unearned?:bool} $data
     */
    public function terminate(Lease $lease, array $data): Lease
    {
        if (! in_array($lease->status, ['active', 'pending_approval'], true)) {
            throw new InvalidArgumentException("Lease #{$lease->id} is '{$lease->status}'; only active leases can be terminated.");
        }

        $terminationDate = isset($data['termination_date']) && $data['termination_date']
            ? CarbonImmutable::parse($data['termination_date'])
            : CarbonImmutable::now()->startOfDay();

        $reason = trim((string) ($data['reason'] ?? ''));
        $cancelOpenInvoices = (bool) ($data['cancel_open_invoices'] ?? false);
        $creditUnearned = (bool) ($data['credit_unearned'] ?? true);

        return DB::transaction(function () use ($lease, $terminationDate, $reason, $cancelOpenInvoices, $creditUnearned) {
            // 1. Lease itself
            $existingNotes = $lease->notes ? rtrim($lease->notes) . "\n\n" : '';
            $stamp = $terminationDate->format('Y-m-d');
            $reasonLine = $reason !== '' ? "Terminated on {$stamp}: {$reason}" : "Terminated on {$stamp}.";
            $lease->update([
                'status' => 'terminated',
                'expiry_date' => $terminationDate,
                'notes' => $existingNotes . $reasonLine,
            ]);

            // 2. Unit status is recomputed by LeaseObserver from step 1.

            // 3. Deactivate charges (so monthly billing won't generate further invoices)
            Charge::where('lease_id', $lease->id)->update([
                'is_active' => false,
                'end_date' => $terminationDate,
            ]);

            // 4. Cancel open invoices if requested — but only fully unpaid
            // ones. A partially-paid invoice that we silently cancelled would
            // orphan the tenant's paid_amount (they'd have paid into a
            // record that no longer claims any balance). Operators who want
            // to void a partially-paid invoice must issue a credit note for
            // the paid portion explicitly — that keeps the AR ledger honest.
            if ($cancelOpenInvoices) {
                Invoice::where('lease_id', $lease->id)
                    ->whereIn('status', ['draft', 'issued', 'partially_paid', 'overdue'])
                    ->where('balance', '>', 0)
                    ->where('paid_amount', '=', 0)
                    // Never silently cancel an invoice already filed with the tax authority
                    // (eta_status = 'valid') — every other cancel path (VoidInvoiceService,
                    // EditInvoice, InvoicesTable) refuses it; the operator must handle a
                    // reported invoice through the proper ETA flow. Nulls stay cancellable.
                    ->where(fn ($q) => $q->whereNull('eta_status')->orWhere('eta_status', '!=', 'valid'))
                    ->each(function (Invoice $invoice) {
                        $invoice->update([
                            'status' => 'cancelled',
                            'balance' => 0,
                        ]);
                    });
            }

            // 5. Credit back what was billed in advance and will never be earned (story MF-02).
            //
            // Rent bills on the 1st, so a tenant leaving on the 18th has already been invoiced for
            // the whole month. Trailing proration cannot fix an invoice that already exists — only
            // a credit note can — and until now raising it was a manual act somebody had to
            // remember. The credit uses the same month-fraction rule the invoice used, so it is the
            // exact complement rather than an independent day-count.
            //
            // Opt-OUT rather than opt-in: giving back money the tenant does not owe is the correct
            // default. The flag exists because the note posts on the termination date, and a CLOSED
            // period refuses it — an operator who has to terminate today and credit later needs a
            // way through that does not involve reopening the books.
            $credits = [];

            if ($creditUnearned) {
                $credits = app(CreditUnearnedBillingService::class)->forTermination($lease->fresh(), $terminationDate);
            }

            $fresh = $lease->fresh();
            // Surfaced on the model rather than returned, so the existing `terminate(): Lease`
            // contract and its callers are untouched while the UI can still say what it did.
            $fresh->setAttribute('termination_credit_notes', collect($credits));

            return $fresh;
        });
    }
}
