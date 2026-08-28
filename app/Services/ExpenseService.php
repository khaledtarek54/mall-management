<?php

namespace App\Services;

use App\Models\Expense;
use App\Support\ReversalReason;
use Illuminate\Support\Facades\DB;

/**
 * Direct-expense lifecycle. Kept in a single-action service (not the Filament page)
 * so the rules are reusable + testable, mirroring VendorBillService. Recognition on
 * the GL rides accounting:sync-ledger (LedgerPoster::sync voids a cancelled one).
 */
class ExpenseService
{
    /** Cancel a recorded expense (idempotent). The next ledger sweep voids its entry. */
    public function cancel(Expense $expense, ?string $reason = null): Expense
    {
        if ($expense->status === 'cancelled') {
            return $expense;
        }

        return DB::transaction(function () use ($expense, $reason) {
            $expense->status = 'cancelled';
            $expense->save();

            // The trail only: `expenses` carries no `notes` column, so there is nowhere on the
            // document itself to stamp. That makes the audit row the sole record rather than the
            // durable one — which is the argument for it, not against it.
            ReversalReason::record($expense, 'cancelled', $reason);

            return $expense->refresh();
        });
    }
}
