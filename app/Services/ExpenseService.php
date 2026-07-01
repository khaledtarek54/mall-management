<?php

namespace App\Services;

use App\Models\Expense;
use Illuminate\Support\Facades\DB;

/**
 * Direct-expense lifecycle. Kept in a single-action service (not the Filament page)
 * so the rules are reusable + testable, mirroring VendorBillService. Recognition on
 * the GL rides accounting:sync-ledger (LedgerPoster::sync voids a cancelled one).
 */
class ExpenseService
{
    /** Cancel a recorded expense (idempotent). The next ledger sweep voids its entry. */
    public function cancel(Expense $expense): Expense
    {
        if ($expense->status === 'cancelled') {
            return $expense;
        }

        return DB::transaction(function () use ($expense) {
            $expense->status = 'cancelled';
            $expense->save();

            return $expense->refresh();
        });
    }
}
