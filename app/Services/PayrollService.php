<?php

namespace App\Services;

use App\Models\Payroll;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Payroll-run lifecycle: draft → approved (GL-postable) → (cancelled). Recognition
 * on the GL rides accounting:sync-ledger (PayrollJournalizer). Kept in a service so
 * the rules are reusable + testable, mirroring VendorBillService/ExpenseService.
 */
class PayrollService
{
    /** Move a draft run to `approved` (idempotent). Once approved it is GL-postable. */
    public function approve(Payroll $payroll): Payroll
    {
        if ($payroll->status !== 'draft') {
            return $payroll;
        }

        // Reject a malformed run at the gate: deductions exceeding gross (net < 0)
        // would otherwise approve fine but be silently skipped by the journalizer.
        if ((float) $payroll->net_paid < 0) {
            throw new \DomainException('Payroll deductions exceed gross salaries; fix the amounts before approving.');
        }

        return DB::transaction(function () use ($payroll) {
            $payroll->status = 'approved';
            $payroll->approved_by_user_id = Auth::id();
            $payroll->approved_at = now();
            $payroll->save();

            return $payroll->refresh();
        });
    }

    /** Cancel a run (idempotent). The next ledger sweep voids its entry. */
    public function cancel(Payroll $payroll): Payroll
    {
        if ($payroll->status === 'cancelled') {
            return $payroll;
        }

        return DB::transaction(function () use ($payroll) {
            $payroll->status = 'cancelled';
            $payroll->save();

            return $payroll->refresh();
        });
    }
}
