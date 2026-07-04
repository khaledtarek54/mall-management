<?php

namespace App\Console\Commands;

use App\Models\CreditNote;
use App\Models\DepositTransaction;
use App\Models\DepreciationEntry;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\Invoice;
use App\Models\MarketingSpend;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\StockMovement;
use App\Models\SystemSetting;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Reconciliation\BooksReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Reconcile the general ledger to the business documents — posts journal entries
 * for invoices, payments, and credit notes (idempotent, self-healing via
 * LedgerPoster::sync). Run once with --all to backfill history; runs on a schedule
 * (recent window) to keep the books current without entangling with the live
 * recomputeTotals/saveQuietly machinery.
 */
class SyncLedgerCommand extends Command
{
    protected $signature = 'accounting:sync-ledger
        {--all : Backfill every document (full history) instead of just the recent window}
        {--since= : Only sync documents updated on/after this date (YYYY-MM-DD)}';

    protected $description = 'Post / reconcile general-ledger entries for invoices, payments, and credit notes (idempotent).';

    public function handle(LedgerPoster $poster, FiscalCalendar $calendar, BooksReconciliationService $recon): int
    {
        $since = $this->resolveSince();

        $this->ensureFiscalYears($calendar);

        $counts = ['posted' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0];

        $this->line($since ? "Syncing documents updated since {$since->toDateString()}..." : 'Backfilling ALL documents...');

        $this->syncModel(Invoice::query(), 'updated_at', $since, $poster, $counts);
        $this->syncModel(Payment::query(), 'updated_at', $since, $poster, $counts);
        $this->syncModel(CreditNote::query(), 'updated_at', $since, $poster, $counts);
        $this->syncModel(VendorBill::query(), 'updated_at', $since, $poster, $counts);
        $this->syncModel(VendorBillPayment::query(), 'updated_at', $since, $poster, $counts);
        $this->syncModel(Expense::query(), 'updated_at', $since, $poster, $counts);
        $this->syncModel(Payroll::query(), 'updated_at', $since, $poster, $counts);
        $this->syncModel(DepositTransaction::query(), 'updated_at', $since, $poster, $counts);
        $this->syncModel(MarketingSpend::query(), 'updated_at', $since, $poster, $counts);
        $this->syncModel(StockMovement::query()->with('warehouse'), 'updated_at', $since, $poster, $counts);
        $this->syncModel(FixedAsset::query(), 'updated_at', $since, $poster, $counts);
        $this->syncModel(DepreciationEntry::query()->with('fixedAsset'), 'updated_at', $since, $poster, $counts);
        $this->syncModel(FixedAssetDisposal::query()->with('fixedAsset'), 'updated_at', $since, $poster, $counts);
        $this->syncModel(EmployeeAdvance::query(), 'updated_at', $since, $poster, $counts);
        $this->syncModel(EmployeeAdvanceRepayment::query(), 'updated_at', $since, $poster, $counts);

        $this->newLine();
        $this->table(['result', 'count'], collect($counts)->map(fn ($v, $k) => [$k, $v])->values()->all());

        $this->tieOut($recon);

        // Record when the sweep last ran so the accounting screens can show a
        // trustworthy "Ledger last synced" indicator (survives cache clears).
        SystemSetting::put('ledger_last_synced_at', now()->toIso8601String());

        // The scheduled (windowed) run is best-effort and idempotent — a single
        // un-postable legacy doc shouldn't red-flag the nightly task forever.
        // An explicit operator backfill (--all / --since) surfaces failures via a
        // non-zero exit so they don't go unnoticed.
        $operatorRun = $this->option('all') || $this->option('since');

        return ($counts['failed'] > 0 && $operatorRun) ? self::FAILURE : self::SUCCESS;
    }

    private function resolveSince(): ?Carbon
    {
        if ($this->option('all')) {
            return null;
        }
        if ($this->option('since')) {
            return Carbon::parse($this->option('since'))->startOfDay();
        }

        // Default scheduled window: a 2-day overlap (idempotent, so overlap is safe).
        return now()->subDays(2)->startOfDay();
    }

    /** Open every fiscal year spanned by the documents so postings have an open period. */
    private function ensureFiscalYears(FiscalCalendar $calendar): void
    {
        $dates = array_filter([
            Invoice::min('issue_date'), Invoice::max('issue_date'),
            Payment::min('payment_date'), Payment::max('payment_date'),
            CreditNote::min('issue_date'), CreditNote::max('issue_date'),
            VendorBill::min('bill_date'), VendorBill::max('bill_date'),
            VendorBillPayment::min('payment_date'), VendorBillPayment::max('payment_date'),
            Expense::min('expense_date'), Expense::max('expense_date'),
            Payroll::min('period_month'), Payroll::max('period_month'),
            DepositTransaction::min('transaction_date'), DepositTransaction::max('transaction_date'),
            MarketingSpend::min('spent_on'), MarketingSpend::max('spent_on'),
            StockMovement::min('moved_on'), StockMovement::max('moved_on'),
            FixedAsset::min('acquisition_date'), FixedAsset::max('acquisition_date'),
            DepreciationEntry::min('period_month'), DepreciationEntry::max('period_month'),
            FixedAssetDisposal::min('disposed_on'), FixedAssetDisposal::max('disposed_on'),
            EmployeeAdvance::min('advance_date'), EmployeeAdvance::max('advance_date'),
            EmployeeAdvanceRepayment::min('repaid_on'), EmployeeAdvanceRepayment::max('repaid_on'),
        ]);

        $years = collect($dates)->map(fn ($d) => (int) Carbon::parse($d)->year);
        $min = $years->min() ?? (int) now()->year;
        $max = max($years->max() ?? (int) now()->year, (int) now()->year);

        for ($year = $min; $year <= $max; $year++) {
            $calendar->ensureYear($year);
        }
    }

    private function syncModel($query, string $tsColumn, ?Carbon $since, LedgerPoster $poster, array &$counts): void
    {
        // Include soft-deleted documents so their posted entry gets voided (a deleted
        // document has no ledger effect). Soft-delete bumps updated_at, so the windowed
        // run picks up freshly-deleted docs too; --all self-heals any older orphans.
        if (method_exists($query->getModel(), 'trashed')) {
            $query->withTrashed();
        }

        $query->when($since, fn ($q) => $q->where($tsColumn, '>=', $since))
            ->chunkById(200, function ($models) use ($poster, &$counts) {
                foreach ($models as $model) {
                    $this->syncOne($poster, $model, $counts);
                }
            });
    }

    private function syncOne(LedgerPoster $poster, Model $model, array &$counts): void
    {
        try {
            $entry = $poster->sync($model);
            if ($entry === null) {
                $counts['skipped']++;
            } elseif ($entry->wasRecentlyCreated) {
                $counts['posted']++;
            } else {
                $counts['unchanged']++;
            }
        } catch (\Throwable $e) {
            $counts['failed']++;
            Log::warning('Ledger sync failed for '.$model::class.' #'.$model->getKey().': '.$e->getMessage());
            $this->warn('  ✗ '.class_basename($model).' #'.$model->getKey().': '.$e->getMessage());
        }
    }

    /**
     * Informational tie-out printout, using the SAME computation the reconcile
     * harness asserts (BooksReconciliationService::glTieOut) so the two never drift.
     */
    private function tieOut(BooksReconciliationService $recon): void
    {
        $gl = $recon->glTieOut();
        if (! ($gl['configured'] ?? false)) {
            return; // GL not configured/populated yet — nothing to tie out.
        }

        $this->newLine();
        $this->line('GL receivables (دفتر الأستاذ):     '.number_format($gl['ar']['gl'], 2));
        $this->line('Invoice balances − open credits:  '.number_format($gl['ar']['expected'], 2));
        if (abs($gl['ar']['delta']) < 0.01) {
            $this->info('✓ GL ties to AR.');
        } else {
            $this->warn('⚠ GL ↔ AR delta: '.number_format($gl['ar']['delta'], 2).' — run billing:reconcile to investigate.');
        }

        $this->newLine();
        $this->line('GL payables (دفتر الأستاذ):       '.number_format($gl['ap']['gl'], 2));
        $this->line('Vendor-bill balances:             '.number_format($gl['ap']['expected'], 2));
        if (abs($gl['ap']['delta']) < 0.01) {
            $this->info('✓ GL ties to AP.');
        } else {
            $this->warn('⚠ GL ↔ AP delta: '.number_format($gl['ap']['delta'], 2).' — investigate.');
        }
    }
}
