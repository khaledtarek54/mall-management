<?php

namespace App\Services;

use App\Models\EmployeeAdvance;
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
            // Lock-safe advance re-check (Phase 4b): approving this run makes its advance
            // installments COUNT against each advance's outstanding. Two draft runs could each
            // deduct within the (pre-approval) outstanding, but together over-repay. So under a
            // lock — and while this run is STILL a draft (its own deduction not yet counted) —
            // verify Σ(this run's installment per advance) ≤ that advance's current outstanding.
            $byAdvance = $payroll->lines()
                ->whereNotNull('employee_advance_id')
                ->where('advance_deduction', '>', 0)
                ->selectRaw('employee_advance_id, SUM(advance_deduction) as total')
                ->groupBy('employee_advance_id')
                ->pluck('total', 'employee_advance_id');

            foreach ($byAdvance as $advanceId => $total) {
                /** @var EmployeeAdvance|null $advance */
                $advance = EmployeeAdvance::withTrashed()->whereKey($advanceId)->lockForUpdate()->first();
                // A trashed/absent advance can't be repaid — the loan it belonged to is gone.
                if ($advance === null || $advance->trashed()) {
                    throw new \DomainException(__('admin.payroll_lines.errors.advance_gone'));
                }
                if (round((float) $total, 2) > $advance->outstanding() + 0.001) {
                    throw new \DomainException(__('admin.payroll_lines.errors.advance_over_repay', [
                        'outstanding' => number_format($advance->outstanding(), 2),
                    ]));
                }
            }

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
