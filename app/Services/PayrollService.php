<?php

namespace App\Services;

use App\Models\EmployeeAdvance;
use App\Models\Payroll;
use App\Support\PostingDate;
use App\Support\ReversalReason;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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
            throw new \DomainException(__('admin.refusals.payroll_deductions_exceed_gross'));
        }

        // Approval is the moment this run becomes GL-postable, dated at its period_month
        // (PayrollJournalizer). So the period must be checked HERE, not only when the run
        // was drafted — a run can sit in draft across a month-end close, and approving it
        // then would relieve every employee advance in it and mark salaries paid while
        // Dr Salaries / Cr Cash silently failed in the best-effort sync job.
        //
        // Approving is also irreversible in practice: cancel() voids the entry, but the
        // advance installments this run consumed have already counted.
        PostingDate::assertOpen($payroll->period_month, 'period_month');

        // **SERIALISE THE TWO APPROVALS THAT COULD PAY ONE PERSON TWICE.**
        //
        // `Payroll::saving` carries the double-pay guard — no employee may be on two APPROVED runs
        // for one month at one property — and it is a plain read with nothing serialising the
        // writers. Two runs approved concurrently each see the other still `draft`, both pass, and
        // the employee is paid twice: salaries posted twice, and every advance installment in both
        // runs relieved twice.
        //
        // There is no contended ROW to lock — the two runs are different rows and the guard is about
        // the SET — so it is a cache lock, the same mechanism and the same reasoning as the monthly
        // billing and assessment runs. Keyed on the property and the month, because that is exactly
        // the scope of the guard's own query; a portfolio-wide key would serialise malls that cannot
        // clash.
        //
        // Taken OUTSIDE the transaction, deliberately. Acquiring it inside would leave our
        // consistent-read snapshot already fixed from before the other approval committed, so the
        // guard would still be answered from a state it had waited past — the F-09 shape this
        // codebase has been bitten by twice.
        $key = sprintf(
            'payroll:approve:%s:%s',
            $payroll->asset_id ?? 'none',
            $payroll->period_month?->format('Y-m') ?? 'none',
        );

        // **A REFUSAL, NOT A 500 AFTER A TEN-SECOND HANG.** `block()` throws
        // `LockTimeoutException`, which `bootstrap/app.php` does not render — it only turns a
        // `DomainException` into a toast — so the operator waited ten seconds and got the error page
        // with their form state gone. The realistic trigger is the holder's PHP process dying
        // mid-approval: the lock then lives to its 30s TTL while the wait is 10s, so the next
        // operator is guaranteed it.
        //
        // Every sibling degrades: `MonthlyBillingService` uses `->get()` and logs a skip,
        // `AllocatesDocumentNumber` catches and falls back. This one has to REFUSE rather than skip
        // — an approval that silently did not happen is worse than one that says so — so the timeout
        // is re-thrown as the refusal it actually is.
        try {
            return Cache::lock($key, 30)->block(10, fn (): Payroll => $this->approveUnderLock($payroll));
        } catch (LockTimeoutException) {
            throw new \DomainException(__('admin.refusals.payroll_approval_in_progress'));
        }
    }

    /** The approval itself, with the same-month clash and the advance balances already serialised. */
    private function approveUnderLock(Payroll $payroll): Payroll
    {
        return DB::transaction(function () use ($payroll) {
            // **THE TRANSACTION'S FIRST STATEMENT, AND IT DOES TWO JOBS.**
            //
            // (1) Idempotence becomes authoritative. The `draft` check in `approve()` is outside the
            // lock, so two requests on the SAME run — a double-click, or two operators on one record
            // — both read `draft`, both take the same key, serialise, and the second one re-approved:
            // measured, no refusal, `approved_at` and `approved_by_user_id` overwritten by the later
            // actor, a second activity row. `Payroll::saving`'s clash guard cannot catch it, because
            // `whereKeyNot($payroll->getKey())` excludes the run from its own query. Worse, when the
            // run's installment exceeds what is left after its own first approval, the second click
            // is refused with "this would over-repay the advance" on a run that approved cleanly.
            //
            // (2) It makes every read below fresh. InnoDB opens a transaction's consistent-read view
            // at its first CONSISTENT read, so a LOCKING read first means no view is open while we
            // wait for the advance locks — and everything read afterwards sees what the other
            // transaction committed. `RecordAdvanceRepaymentService` and `SettleCustodyService` are
            // safe for exactly this reason and by statement order alone, which nothing pins; here it
            // is stated.
            $locked = Payroll::whereKey($payroll->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft') {
                return $locked;
            }

            $payroll = $locked;

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
                // **The LOCKING twin.** `lockForUpdate()` above serialises the writers; it does not
                // make this guard see them. `outstanding()` issues plain reads against the
                // repayments and the approved payroll lines, which under REPEATABLE READ answer from
                // the snapshot taken before this transaction waited — so two runs each deducting
                // within the pre-approval outstanding both passed and together over-repaid the loan.
                $outstanding = $advance->outstandingForUpdate();

                if (round((float) $total, 2) > $outstanding + 0.001) {
                    throw new \DomainException(__('admin.payroll_lines.errors.advance_over_repay', [
                        'outstanding' => number_format($outstanding, 2),
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
    public function cancel(Payroll $payroll, ?string $reason = null): Payroll
    {
        if ($payroll->status === 'cancelled') {
            return $payroll;
        }

        return DB::transaction(function () use ($payroll, $reason) {
            // `payrolls` has no `notes` column — the audit row is the whole record.
            $payroll->status = 'cancelled';
            $payroll->save();

            ReversalReason::record($payroll, 'cancelled', $reason);

            return $payroll->refresh();
        });
    }
}
