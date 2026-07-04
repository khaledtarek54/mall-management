<?php

namespace App\Services;

use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use Illuminate\Support\Facades\DB;

/**
 * Records a repayment against an employee advance/loan (module 24, Phase 2).
 * Lock-safe: the advance is locked and its outstanding re-computed inside the
 * transaction, so concurrent repayments can never over-repay (drive the receivable
 * negative). Denormalises the property from the advance for the GL dimension.
 */
class RecordAdvanceRepaymentService
{
    /**
     * @param  array{amount:mixed, repaid_on:mixed, method?:string, notes?:?string}  $data
     */
    public function record(EmployeeAdvance $advance, array $data): EmployeeAdvanceRepayment
    {
        return DB::transaction(function () use ($advance, $data) {
            /** @var EmployeeAdvance $locked */
            $locked = EmployeeAdvance::whereKey($advance->getKey())->lockForUpdate()->firstOrFail();

            $amount = round((float) ($data['amount'] ?? 0), 2);
            abort_unless($amount > 0, 422);
            // Re-check outstanding UNDER the lock — no over-repayment under a race.
            abort_unless($amount <= $locked->outstanding() + 0.001, 422);

            return $locked->repayments()->create([
                'asset_id' => $locked->asset_id, // denormalised — the books dimension
                'amount' => $amount,
                'repaid_on' => $data['repaid_on'],
                'method' => ($data['method'] ?? 'cash') === 'bank' ? 'bank' : 'cash',
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => auth()->id(),
            ]);
        });
    }
}
