<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\PaymentMethod;
use App\Support\PostingDate;

/**
 * Grants an advance/loan to an employee (module 24, Phase 2). Denormalises the
 * property from the employee (so the advance's GL dimension survives the employee
 * being archived) and rejects a grant to a terminated employee.
 */
class GrantEmployeeAdvanceService
{
    /**
     * @param  array{type?:string, amount:mixed, advance_date:mixed, paid_from?:string, notes?:?string}  $data
     */
    public function grant(Employee $employee, array $data): EmployeeAdvance
    {
        abort_unless($employee->status === 'active', 422);

        // Server-side amount guard (symmetric with the repayment service) — never
        // persist a zero/negative advance that would be inert + un-repayable.
        $amount = round((float) ($data['amount'] ?? 0), 2);
        abort_unless($amount > 0, 422);

        // `advance_date` is the grant entry's GL entry_date (EmployeeAdvanceJournalizer).
        // The F-89 fix guarded the repayment (RecordAdvanceRepaymentService) and left the
        // grant unguarded — so the employee's outstanding balance could exist with no
        // Dr Employee Advances / Cr Cash behind it, and the repayments that later relieve
        // that receivable would credit an account the grant never debited.
        // NOT `assertOpen` — that says nothing about the FUTURE, and this date is money leaving the
        // business. Measured at HEAD 2026-09-04:
        //   PostingDate::assertOpen('2027-03-04', 'advance_date')      → ACCEPTED, returns the date
        //   PostingDate::assertNotFuture('2027-03-04', 'advance_date') → refused
        // So an advance could be granted into a month that has not happened: the cash has not left
        // the till, the employee is already carrying the balance the payroll deduction reads, and
        // the period the entry lands in will later close around it — at which point the correction
        // is refused too, because `SealedPeriod` will not restate a document posted into a sealed
        // month. `RecordAdvanceRepaymentService` — the settlement half of this same document — has
        // called `assertNotFuture` since F-93; only the grant half was left open.
        $advanceDate = PostingDate::assertNotFuture($data['advance_date'] ?? null, 'advance_date')->toDateString();

        return $employee->advances()->create([
            'asset_id' => $employee->asset_id, // denormalised — the books dimension
            'type' => ($data['type'] ?? 'advance') === 'loan' ? 'loan' : 'advance',
            'amount' => $amount,
            'advance_date' => $advanceDate,
            // NOT clamped. `employee_advances.paid_from` is a catalogue-widened rail column, so
            // `=== 'bank' ? 'bank' : 'cash'` would turn an InstaPay advance into a CASH one — a
            // wrong account rather than a refusal, under a success toast. `ValueSets` refuses what
            // the catalogue does not carry. Same fix as `RecordAdvanceRepaymentService`.
            'paid_from' => $data['paid_from'] ?? PaymentMethod::FLOOR_CASH_ROLE,
            'notes' => $data['notes'] ?? null,
            'created_by_user_id' => auth()->id(),
        ]);
    }
}
