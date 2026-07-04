<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAdvance;

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

        return $employee->advances()->create([
            'asset_id' => $employee->asset_id, // denormalised — the books dimension
            'type' => ($data['type'] ?? 'advance') === 'loan' ? 'loan' : 'advance',
            'amount' => $amount,
            'advance_date' => $data['advance_date'],
            'paid_from' => ($data['paid_from'] ?? 'cash') === 'bank' ? 'bank' : 'cash',
            'notes' => $data['notes'] ?? null,
            'created_by_user_id' => auth()->id(),
        ]);
    }
}
