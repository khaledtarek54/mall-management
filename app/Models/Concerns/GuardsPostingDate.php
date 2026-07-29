<?php

namespace App\Models\Concerns;

use App\Support\PostingDate;

/**
 * Refuses a save that would date this document's journal entry into a CLOSED period.
 *
 * WHY ON THE MODEL. The project rule is "guard in the service" — a DatePicker's minDate is
 * UX, and console/API/seeder paths never see it. But several GL sources have no create/update
 * service at all: their Filament resource writes the model directly (Expense, MarketingSpend,
 * DepositTransaction, FixedAsset). For those the model's own save IS the single choke point
 * every path shares, so the guard belongs here. Documents that DO have a service keep guarding
 * there, where the refusal can be raised before any related work begins.
 *
 * WHAT IT PREVENTS. Every one of these columns is a journalizer's `entry_date`
 * (LedgerRealtimeSync::SOURCE_DATE_COLUMNS). Dated into a closed period, the failure is silent
 * and one-directional: the row commits and the operator is told it worked, while the journal
 * entry is refused inside the best-effort SyncDocumentToLedger job, which logs rather than
 * retries. Business state moves; the books don't.
 *
 * DIRTY-ONLY, deliberately. Re-checking on every save would freeze any document whose period
 * has since closed — you could not correct a typo in an expense's description months later.
 * The rule being enforced is "nobody MOVES an entry into a sealed period", not "old records
 * are read-only", which is a different rule with a different owner (the immutability guards).
 *
 * A MISSING period stays legal — only a CLOSED one is refused. See PostingDate.
 */
trait GuardsPostingDate
{
    /**
     * The column holding the date this document's GL entry is dated from. Must match this
     * model's entry in LedgerRealtimeSync::SOURCE_DATE_COLUMNS — PostingDateGuardConformanceTest
     * fails if they disagree, so the guard can never drift from what the ledger actually uses.
     */
    abstract public static function postingDateColumn(): string;

    public static function bootGuardsPostingDate(): void
    {
        static::saving(function (self $model) {
            $column = static::postingDateColumn();

            if ($model->isDirty($column) && filled($model->{$column})) {
                PostingDate::assertOpen($model->{$column}, $column);
            }
        });
    }
}
