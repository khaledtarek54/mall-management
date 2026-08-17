<?php

namespace App\Support;

use App\Jobs\SyncDocumentToLedger;
use App\Models\CreditNote;
use App\Models\Custody;
use App\Models\CustodyTransaction;
use App\Models\DepositApplication;
use App\Models\DepositTransaction;
use App\Models\DepreciationEntry;
use App\Models\Disbursement;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\Invoice;
use App\Models\InvoiceWriteOff;
use App\Models\MarketingSpend;
use App\Models\OwnerStatementRun;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\SlaPenalty;
use App\Models\StockMovement;
use App\Models\StraightLineRentAdjustment;
use App\Models\TenantCreditApplication;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
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
 * SlaPenalty's postings (2026-07-16).
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
        Invoice::class => 'issue_date',
        Payment::class => 'payment_date',
        CreditNote::class => 'issue_date',
        VendorBill::class => 'bill_date',
        VendorBillPayment::class => 'payment_date',
        Expense::class => 'expense_date',
        Payroll::class => 'period_month',
        DepositTransaction::class => 'transaction_date',
        MarketingSpend::class => 'spent_on',
        StockMovement::class => 'moved_on',
        FixedAsset::class => 'acquisition_date',
        DepreciationEntry::class => 'period_month',
        FixedAssetDisposal::class => 'disposed_on',
        EmployeeAdvance::class => 'advance_date',
        EmployeeAdvanceRepayment::class => 'repaid_on',
        Custody::class => 'custody_date',
        CustodyTransaction::class => 'transaction_date',
        // The owner-statement accrual is dated at finalise; a draft isn't posted at all.
        OwnerStatementRun::class => 'posting_date',
        // The owner payout posts on the day it was paid; scheduled/approved don't post.
        Disbursement::class => 'paid_on',
        // Mirrors SlaPenaltyJournalizer's `applied_at ?? created_at`. It only posts
        // once APPLIED, and applying always stamps applied_at, so the fallback never decides
        // the period of a real entry.
        SlaPenalty::class => 'applied_at',
        // Applied at application time (an open period), never the source receipt's date — that
        // decoupling is what lets an old overpayment settle a current invoice without stranding the GL.
        TenantCreditApplication::class => 'entry_date',
        // Same decoupling, same reason: a deposit taken three years ago must be able to settle a
        // current invoice without stranding its entry in a closed period.
        DepositApplication::class => 'entry_date',
        // Dated at the END of the month being recognised, never today: a recognition entry belongs
        // in the period it recognises or the P&L for that month is wrong.
        StraightLineRentAdjustment::class => 'entry_date',
        InvoiceWriteOff::class => 'entry_date',
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
            // (restore must re-post); SlaPenalty does not.
            if (in_array(SoftDeletes::class, class_uses_recursive($source), true)) {
                $source::restored($dispatch);
            }
        }
    }
}
