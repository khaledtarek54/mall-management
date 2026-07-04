<?php

namespace App\Services;

use App\Models\Custody;
use App\Models\CustodyTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Records a settlement against a custody (module 25) — an `expense` (with a category)
 * or a `return` of unspent cash (cash|bank). Lock-safe: the custody is locked and its
 * outstanding re-computed inside the transaction, so concurrent settlements can never
 * over-settle (spend more than was placed in custody).
 */
class SettleCustodyService
{
    /**
     * @param  array{type?:string, amount:mixed, transaction_date:mixed, category?:?string, method?:string, notes?:?string}  $data
     */
    public function settle(Custody $custody, array $data): CustodyTransaction
    {
        return DB::transaction(function () use ($custody, $data) {
            /** @var Custody $locked */
            $locked = Custody::whereKey($custody->getKey())->lockForUpdate()->firstOrFail();

            $amount = round((float) ($data['amount'] ?? 0), 2);
            abort_unless($amount > 0, 422);
            // Re-check outstanding UNDER the lock — no over-settlement under a race.
            abort_unless($amount <= $locked->outstanding() + 0.001, 422);

            $type = ($data['type'] ?? 'expense') === 'return' ? 'return' : 'expense';

            return $locked->transactions()->create([
                'asset_id' => $locked->asset_id, // denormalised — the books dimension
                'type' => $type,
                'amount' => $amount,
                'transaction_date' => $data['transaction_date'],
                // Category only for expenses; method (cash|bank) only for returns.
                'category' => $type === 'expense' ? ($data['category'] ?? 'other') : null,
                'method' => $type === 'return' ? (($data['method'] ?? 'cash') === 'bank' ? 'bank' : 'cash') : null,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => auth()->id(),
            ]);
        });
    }
}
