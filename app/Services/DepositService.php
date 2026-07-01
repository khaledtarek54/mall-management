<?php

namespace App\Services;

use App\Models\DepositTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Security-deposit transaction lifecycle. Recorded immediately; cancellable. The
 * GL posting rides accounting:sync-ledger (DepositTransactionJournalizer). Kept in
 * a service so the rule is reusable + testable, mirroring ExpenseService.
 */
class DepositService
{
    /** Cancel a deposit transaction (idempotent). The next ledger sweep voids its entry. */
    public function cancel(DepositTransaction $deposit): DepositTransaction
    {
        if ($deposit->status === 'cancelled') {
            return $deposit;
        }

        return DB::transaction(function () use ($deposit) {
            $deposit->status = 'cancelled';
            $deposit->save();

            return $deposit->refresh();
        });
    }
}
