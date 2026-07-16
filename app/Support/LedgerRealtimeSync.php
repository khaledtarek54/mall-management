<?php

namespace App\Support;

use App\Jobs\SyncDocumentToLedger;
use App\Services\Accounting\LedgerPoster;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Wires near-real-time general-ledger posting: every posting source dispatches a queued
 * SyncDocumentToLedger job (afterCommit) whenever it is saved / deleted / restored, so
 * its journal entry reconciles within seconds instead of waiting for the daily
 * `accounting:sync-ledger` sweep. Called from AppServiceProvider::boot when
 * `config('accounting.realtime_ledger_sync')` is on.
 *
 * Kept as a static helper (not inline in the provider) so the wiring is unit-testable.
 *
 * The source list itself lives on {@see LedgerPoster::JOURNALIZERS} — this class derives it
 * rather than re-declaring it, because the hand-copied version drifted and stranded
 * MaintenancePenalty's postings (2026-07-16).
 */
class LedgerRealtimeSync
{
    /**
     * Each source → the date column that becomes its journal entry's `entry_date` (i.e. the
     * period a fresh post would land in). MUST match each journalizer's entry_date. Used by
     * the close gate to find documents DATED in a period being closed — including ones never
     * posted yet — so the close can't strand their future post, and by SyncLedgerCommand to
     * open the fiscal years its documents span.
     *
     * Genuinely per-model data, so it can't be derived from the registry — but
     * GlRegistryConformanceTest asserts its keys are exactly LedgerPoster::sources(), so a
     * new journalizer cannot ship without its date column.
     */
    public const SOURCE_DATE_COLUMNS = [
        \App\Models\Invoice::class => 'issue_date',
        \App\Models\Payment::class => 'payment_date',
        \App\Models\CreditNote::class => 'issue_date',
        \App\Models\VendorBill::class => 'bill_date',
        \App\Models\VendorBillPayment::class => 'payment_date',
        \App\Models\Expense::class => 'expense_date',
        \App\Models\Payroll::class => 'period_month',
        \App\Models\DepositTransaction::class => 'transaction_date',
        \App\Models\MarketingSpend::class => 'spent_on',
        \App\Models\StockMovement::class => 'moved_on',
        \App\Models\FixedAsset::class => 'acquisition_date',
        \App\Models\DepreciationEntry::class => 'period_month',
        \App\Models\FixedAssetDisposal::class => 'disposed_on',
        \App\Models\EmployeeAdvance::class => 'advance_date',
        \App\Models\EmployeeAdvanceRepayment::class => 'repaid_on',
        \App\Models\Custody::class => 'custody_date',
        \App\Models\CustodyTransaction::class => 'transaction_date',
        // Mirrors MaintenancePenaltyJournalizer's `applied_at ?? created_at`. It only posts
        // once APPLIED, and applying always stamps applied_at, so the fallback never decides
        // the period of a real entry.
        \App\Models\MaintenancePenalty::class => 'applied_at',
    ];

    public static function register(): void
    {
        $dispatch = function ($model): void {
            SyncDocumentToLedger::dispatch($model::class, $model->getKey())->afterCommit();
        };

        foreach (LedgerPoster::sources() as $source) {
            $source::saved($dispatch);
            $source::deleted($dispatch);

            // `restored` is declared by the SoftDeletes trait, so registering it on a
            // hard-deleting source is a BadMethodCallException. Most sources soft-delete
            // (restore must re-post); MaintenancePenalty does not.
            if (in_array(SoftDeletes::class, class_uses_recursive($source), true)) {
                $source::restored($dispatch);
            }
        }
    }
}
