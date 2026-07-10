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
