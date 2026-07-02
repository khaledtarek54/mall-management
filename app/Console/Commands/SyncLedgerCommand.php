<?php

namespace App\Console\Commands;

use App\Models\CreditNote;
use App\Models\DepositTransaction;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerReportService;
use App\Services\Accounting\LedgerPoster;
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

    public function handle(LedgerPoster $poster, FiscalCalendar $calendar, LedgerReportService $reports, AccountResolver $accounts): int
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

        $this->newLine();
        $this->table(['result', 'count'], collect($counts)->map(fn ($v, $k) => [$k, $v])->values()->all());

        $this->tieOut($reports, $accounts);

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
     * Informational tie-out: GL receivables should equal outstanding invoice
     * balances minus tenant credits the system hasn't applied yet.
     */
    private function tieOut(LedgerReportService $reports, AccountResolver $accounts): void
    {
        // Resolve AR through the mapping layer (never a hard-coded code) so the
        // tie-out follows the same account the journalizers post to.
        try {
            $ar = $accounts->account('accounts_receivable');
        } catch (\DomainException) {
            return;
        }

        $glAr = round($reports->accountLedger($ar)['closing'], 2);
        // Net AR excludes cancelled AND credited invoices (the money model keeps both
        // on the books but out of net receivables — credited ones are reversed by
        // their credit notes on the GL side, so excluding them keeps both sides symmetric).
        $invoiceBalances = round((float) Invoice::whereNotIn('status', ['cancelled', 'credited'])->sum('balance'), 2);
        $outstandingCredits = round((float) CreditNote::whereIn('status', ['issued', 'applied'])->sum('balance'), 2);
        $expected = round($invoiceBalances - $outstandingCredits, 2);
        $delta = round($glAr - $expected, 2);

        $this->newLine();
        $this->line('GL receivables (دفتر الأستاذ):     '.number_format($glAr, 2));
        $this->line('Invoice balances − open credits:  '.number_format($expected, 2));

        if (abs($delta) < 0.01) {
            $this->info('✓ GL ties to AR.');
        } else {
            $this->warn('⚠ GL ↔ AR delta: '.number_format($delta, 2).' — run billing:reconcile to investigate.');
        }

        // Accounts Payable tie-out: GL payables should equal outstanding vendor-bill balances.
        try {
            $ap = $accounts->account('accounts_payable');
        } catch (\DomainException) {
            return;
        }

        $glAp = round($reports->accountLedger($ap)['closing'], 2);
        $billBalances = round((float) VendorBill::whereNotIn('status', ['cancelled', 'draft'])->sum('balance'), 2);
        $apDelta = round($glAp - $billBalances, 2);

        $this->newLine();
        $this->line('GL payables (دفتر الأستاذ):       '.number_format($glAp, 2));
        $this->line('Vendor-bill balances:             '.number_format($billBalances, 2));

        if (abs($apDelta) < 0.01) {
            $this->info('✓ GL ties to AP.');
        } else {
            $this->warn('⚠ GL ↔ AP delta: '.number_format($apDelta, 2).' — investigate.');
        }
    }
}
