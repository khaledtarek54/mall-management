<?php

namespace App\Support;

use App\Jobs\SyncDocumentToLedger;

/**
 * Wires near-real-time general-ledger posting: every posting source dispatches a queued
 * SyncDocumentToLedger job (afterCommit) whenever it is saved / deleted / restored, so
 * its journal entry reconciles within seconds instead of waiting for the daily
 * `accounting:sync-ledger` sweep. Called from AppServiceProvider::boot when
 * `config('accounting.realtime_ledger_sync')` is on.
 *
 * Kept as a static helper (not inline in the provider) so the wiring is unit-testable.
 */
class LedgerRealtimeSync
{
    /** The posting sources — MUST mirror LedgerPoster::journalizerFor (keep in sync). */
    public const SOURCES = [
        \App\Models\Invoice::class,
        \App\Models\Payment::class,
        \App\Models\CreditNote::class,
        \App\Models\VendorBill::class,
        \App\Models\VendorBillPayment::class,
        \App\Models\Expense::class,
        \App\Models\Payroll::class,
        \App\Models\DepositTransaction::class,
        \App\Models\MarketingSpend::class,
        \App\Models\StockMovement::class,
        \App\Models\FixedAsset::class,
        \App\Models\DepreciationEntry::class,
        \App\Models\FixedAssetDisposal::class,
        \App\Models\EmployeeAdvance::class,
        \App\Models\EmployeeAdvanceRepayment::class,
        \App\Models\Custody::class,
        \App\Models\CustodyTransaction::class,
    ];

    /**
     * Each source → the date column that becomes its journal entry's `entry_date` (i.e. the
     * period a fresh post would land in). MUST match each journalizer's entry_date. Used by
     * the close gate to find documents DATED in a period being closed — including ones never
     * posted yet — so the close can't strand their future post. Mirrors SyncLedgerCommand's
     * per-source date columns.
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
    ];

    public static function register(): void
    {
        $dispatch = function ($model): void {
            SyncDocumentToLedger::dispatch($model::class, $model->getKey())->afterCommit();
        };

        foreach (self::SOURCES as $source) {
            $source::saved($dispatch);
            $source::deleted($dispatch);
            $source::restored($dispatch); // every source soft-deletes; restore re-posts
        }
    }
}
