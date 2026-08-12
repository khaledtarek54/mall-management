<?php

namespace App\Console\Commands;

use App\Models\DepreciationEntry;
use App\Models\FixedAssetDisposal;
use App\Models\MaintenancePenalty;
use App\Models\StockMovement;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\BooksDriftDetectedNotification;
use App\Notifications\LedgerSyncFailedNotification;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Support\LedgerRealtimeSync;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

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
        {--since= : Only sync documents updated on/after this date (YYYY-MM-DD)}
        {--scheduled : Best-effort run — never exit non-zero on per-document failures (for the scheduler, not a human operator)}';

    protected $description = 'Post / reconcile general-ledger entries for invoices, payments, and credit notes (idempotent).';

    /**
     * Per-source eager loads — only for sources whose journalizer walks a relation to build
     * its payload, so the sweep doesn't N+1 across a full backfill. Optional by design: a
     * source absent from this map is swept without eager loading, never skipped.
     *
     * @var array<class-string<Model>, array<int, string>>
     */
    private const EAGER = [
        StockMovement::class => ['warehouse'],
        DepreciationEntry::class => ['fixedAsset'],
        FixedAssetDisposal::class => ['fixedAsset'],
        MaintenancePenalty::class => ['bill', 'workOrder'],
    ];

    public function handle(LedgerPoster $poster, FiscalCalendar $calendar, BooksReconciliationService $recon): int
    {
        $since = $this->resolveSince();

        $this->ensureFiscalYears($calendar);

        $counts = ['posted' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0];

        $this->line($since ? "Syncing documents updated since {$since->toDateString()}..." : 'Backfilling ALL documents...');

        // Derived from the registry, never re-listed: a journalizer registered in
        // LedgerPoster::JOURNALIZERS is swept here automatically. Hand-listing this is what
        // stranded MaintenancePenalty's entries (2026-07-16) — the sweep couldn't self-heal
        // a source it had never heard of.
        foreach (LedgerPoster::sources() as $source) {
            $query = $source::query();
            if ($relations = self::EAGER[$source] ?? null) {
                $query->with($relations);
            }
            $this->syncModel($query, 'updated_at', $since, $poster, $counts);
        }

        $this->newLine();
        $this->table(['result', 'count'], collect($counts)->map(fn ($v, $k) => [$k, $v])->values()->all());

        $this->tieOut($recon);

        // Record when the sweep last ran so the accounting screens can show a
        // trustworthy "Ledger last synced" indicator (survives cache clears).
        SystemSetting::put('ledger_last_synced_at', now()->toIso8601String());

        // Surface any un-postable documents (the closed-period reversal trap et al.)
        // loudly — a bell alert to the GL managers + a failure count the report pages
        // show — instead of only a log line. Best-effort exit-0 keeps the cron from
        // going perpetually red, but the failure is never silent.
        $this->recordAndAlertFailures($counts['failed']);

        // The scheduled (windowed) run is best-effort and idempotent — a single
        // un-postable legacy doc shouldn't red-flag the nightly task forever.
        // An explicit operator backfill (--all / --since) surfaces failures via a
        // non-zero exit so they don't go unnoticed — UNLESS --scheduled marks it as
        // an automated self-healing sweep (e.g. the weekly full backstop), which stays
        // best-effort so a legacy doc can't turn the cron perpetually red.
        $operatorRun = ($this->option('all') || $this->option('since')) && ! $this->option('scheduled');

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

    /**
     * Open every fiscal year spanned by the documents so postings have an open period.
     * Walks the same per-source date columns the close gate uses, so a source can never be
     * swept into a year this didn't open.
     */
    private function ensureFiscalYears(FiscalCalendar $calendar): void
    {
        $dates = [];
        foreach (LedgerRealtimeSync::SOURCE_DATE_COLUMNS as $class => $column) {
            $dates[] = $class::min($column);
            $dates[] = $class::max($column);
        }

        $years = collect(array_filter($dates))->map(fn ($d) => (int) Carbon::parse($d)->year);
        $min = $years->min() ?? (int) now()->year;
        $max = max($years->max() ?? (int) now()->year, (int) now()->year);

        for ($year = $min; $year <= $max; $year++) {
            $calendar->ensureYear($year);
        }
    }

    private function syncModel(Builder $query, string $tsColumn, ?Carbon $since, LedgerPoster $poster, array &$counts): void
    {
        // Include soft-deleted documents so their posted entry gets voided (a deleted
        // document has no ledger effect). Soft-delete bumps updated_at, so the windowed
        // run picks up freshly-deleted docs too; --all self-heals any older orphans.
        if (method_exists($query->getModel(), 'trashed')) {
            // withTrashed() comes from the SoftDeletes global scope, not the base Builder — the
            // guard above proves it's there, but PHPStan can't follow a trait-provided macro.
            $query->withTrashed(); // @phpstan-ignore method.notFound
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
     * Stamp the failure count (shown as a warning on the report pages via PostsToLedger)
     * and alert the GL managers when it newly appears or changes — so an un-postable
     * document (typically the closed-period reversal trap) is never silent. De-duped
     * against the previous count so a persistent failure alerts once, not every run.
     */
    private function recordAndAlertFailures(int $failed): void
    {
        $previous = (int) (SystemSetting::get('ledger_last_sync_failures') ?? 0);

        // A windowed run only visits the recent 2-day window, so it must NOT clear a
        // warning for an OLD un-postable doc — the closed-period trap doc is by definition
        // outside the window (its period closed in the past; it hasn't been touched). Only
        // a full-scope run (--all, incl. the weekly backstop) has the scope to lower the
        // count to 0. A windowed run may still RAISE it when it finds a fresh failure.
        // (Fails safe: over-warn rather than false-clear.)
        $fullScope = (bool) $this->option('all');
        if ($failed === 0 && ! $fullScope) {
            return; // keep the existing stamp; don't clear what this run couldn't re-verify
        }

        SystemSetting::put('ledger_last_sync_failures', (string) $failed);

        if ($failed === 0 || $failed === $previous) {
            return;
        }

        // Alert whoever manages the books (super_admin + accounting). All best-effort:
        // guard the spatie permission lookup AND the send so a notification hiccup can
        // never fail the (deliberately exit-0) sweep it is merely reporting on.
        try {
            $recipients = User::query()->permission('journal_entries.post')->get();
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new LedgerSyncFailedNotification($failed));
            }
        } catch (\Throwable $e) {
            Log::warning('Ledger sync-failure alert could not be sent: '.$e->getMessage());
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

        $this->recordAndAlertDrift($gl);
    }

    /**
     * Persist the tie-out and alert when the books START drifting.
     *
     * **Until 2026-08-12 this printed to the console and stopped there.** The sweep runs on cron, so
     * a `warn()` goes to whatever the scheduler does with stdout — nowhere, on this deployment. The
     * two keys that WERE persisted are both about documents that threw, and this class of bug throws
     * nothing: `recordAndAlertFailures()` returns early on `$failed === 0`, which is exactly the
     * state a drifting-but-erroring-free ledger is in. So the one number that says "the books no
     * longer agree with themselves" was the one number nobody could see.
     *
     * Unlike the failures counter this is safe to clear on ANY run: `glTieOut()` sums the whole
     * ledger against the whole sub-ledger, so even a two-day windowed sweep computes a full-scope
     * answer. There is no partial view to false-clear from.
     *
     * Alerts only on a CHANGE into drift, for the same reason the failures path does — a nightly
     * message repeating a known delta is a message people filter.
     *
     * @param  array{ar: array{delta: float}, ap: array{delta: float}}  $gl
     */
    private function recordAndAlertDrift(array $gl): void
    {
        $ar = round((float) $gl['ar']['delta'], 2);
        $ap = round((float) $gl['ap']['delta'], 2);
        $drifting = abs($ar) >= 0.01 || abs($ap) >= 0.01;

        $wasDrifting = (bool) (SystemSetting::get('ledger_books_drifting') ?? false);

        SystemSetting::put('ledger_tie_out_ar_delta', (string) $ar);
        SystemSetting::put('ledger_tie_out_ap_delta', (string) $ap);
        SystemSetting::put('ledger_tie_out_checked_at', now()->toIso8601String());
        SystemSetting::put('ledger_books_drifting', $drifting ? '1' : '');

        if (! $drifting || $wasDrifting) {
            return;
        }

        // Same recipients and the same belt-and-braces as the failure alert: a notification hiccup
        // must never fail the deliberately exit-0 sweep it is only reporting on.
        try {
            $recipients = User::query()->permission('journal_entries.post')->get();
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new BooksDriftDetectedNotification($ar, $ap));
            }
        } catch (\Throwable $e) {
            Log::warning('Books-drift alert could not be sent: '.$e->getMessage());
        }
    }
}
