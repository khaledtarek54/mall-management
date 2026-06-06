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
     *
     * @param array{termination_date:string|\DateTimeInterface|null, reason:string|null, cancel_open_invoices?:bool} $data
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

        return DB::transaction(function () use ($lease, $terminationDate, $reason, $cancelOpenInvoices) {
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
                    ->each(function (Invoice $invoice) {
                        $invoice->update([
                            'status' => 'cancelled',
                            'balance' => 0,
                        ]);
                    });
            }

            return $lease->fresh();
        });
    }
}
