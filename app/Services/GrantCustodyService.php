<?php

namespace App\Services;

use App\Models\Custody;
use App\Models\Employee;

/**
 * Grants a custody (عهدة — module 25) to an employee custodian. Denormalises the
 * property from the employee (so the GL dimension survives the employee's archival)
 * and rejects a grant to a terminated employee or a non-positive amount.
 */
class GrantCustodyService
{
    /**
     * @param  array{reference?:?string, amount:mixed, custody_date:mixed, paid_from?:string, purpose?:?string}  $data
     */
    public function grant(Employee $employee, array $data): Custody
    {
        abort_unless($employee->status === 'active', 422);

        $amount = round((float) ($data['amount'] ?? 0), 2);
        abort_unless($amount > 0, 422);

        return $employee->custodies()->create([
            'asset_id' => $employee->asset_id, // denormalised — the books dimension
            'reference' => $data['reference'] ?? null,
            'amount' => $amount,
            'custody_date' => $data['custody_date'],
            'paid_from' => ($data['paid_from'] ?? 'cash') === 'bank' ? 'bank' : 'cash',
            'purpose' => $data['purpose'] ?? null,
            'created_by_user_id' => auth()->id(),
        ]);
    }
}
