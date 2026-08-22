<?php

namespace App\Services;

use App\Models\Custody;
use App\Models\Employee;
use App\Support\PostingDate;

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

        // `custody_date` is the grant entry's GL entry_date (CustodyJournalizer), so a
        // closed period must be refused here.
        //
        // The F-93 fix guarded the money going OUT of a custody (SettleCustodyService) and
        // left the money going IN unguarded — the same silent divergence on the sibling
        // half of the same document: the custody row commits, the custodian is on the hook
        // for it, and Dr Custodies / Cr Cash never posts. The settlement would then be
        // refused too (it may not predate its grant), so the عهدة is stuck: recorded,
        // unbacked in the books, and unsettleable.
        $custodyDate = PostingDate::assertOpen($data['custody_date'] ?? null, 'custody_date')->toDateString();

        return $employee->custodies()->create([
            'asset_id' => $employee->asset_id, // denormalised — the books dimension
            'reference' => $data['reference'] ?? null,
            'amount' => $amount,
            'custody_date' => $custodyDate,
            // Not clamped — `custodies.paid_from` is a registered value set, so a value outside
            // it is REFUSED rather than silently turned into cash and posted to the wrong account.
            'paid_from' => $data['paid_from'] ?? 'cash',
            'purpose' => $data['purpose'] ?? null,
            'created_by_user_id' => auth()->id(),
        ]);
    }
}
