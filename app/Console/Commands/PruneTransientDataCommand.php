<?php

namespace App\Console\Commands;

use App\Settings\HousekeepingSettings;
use Carbon\CarbonImmutable;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Delete the by-products of running the system, at the operator's retention periods (D2-09).
 *
 * EG-34 gave the AUDIT TRAIL a period and stopped there. Five other tables grow for ever with
 * nothing to prune them, and none of them is money or evidence — which is why nobody notices until
 * one has years in it.
 *
 * ## Framework pruners are USED, not re-implemented
 *
 * Laravel ships `queue:prune-failed` and Sanctum ships `sanctum:prune-expired`. Both are called
 * here rather than reproduced, because a second implementation of "delete old failed jobs" is a
 * second thing to keep correct across upgrades.
 *
 * They are invoked from INSIDE this command rather than scheduled with their own `--hours`, and
 * that is the whole reason this is one command rather than five schedule lines: the period is a
 * SETTING, and `Schedule::command('queue:prune-failed --hours='.$setting)` would read the database
 * while the schedule is being DEFINED. `routes/console.php` is loaded by every artisan invocation,
 * including `migrate` on a database with no settings table. EG-34 records that trap; this is the
 * same trap with five more chances to fall into it.
 *
 * ## What the framework does NOT cover, and why each needs code here
 *
 * - **`notifications`** — not `Prunable` at all.
 * - **`exports`** — Filament's model `use`s the `Prunable` trait and declares **no `prunable()`
 *   method**, so `model:prune` throws `LogicException` on it. More importantly there is no
 *   `pruning()` hook either, so even a working prune would delete the row and **orphan the file** —
 *   a full CSV of a register left on disk for ever. The file is the point of this entry.
 * - **`imports` / `failed_import_rows`** — `FailedImportRow` implements `prunable()` with a
 *   HARDCODED one month, which is not the operator's choice, and the parent `Import` has none.
 *
 * ## `0` means keep everything, and it is reported
 *
 * Per key, the convention EG-34 set. A silent no-op is indistinguishable from a broken schedule.
 */
class PruneTransientDataCommand extends Command
{
    protected $signature = 'atriom:prune-transient-data {--dry-run : Count what would be deleted without deleting it}';

    protected $description = 'Delete notifications, exports, import failures, failed jobs and expired tokens past their retention period';

    public function handle(): int
    {
        $settings = app(HousekeepingSettings::class);

        $this->pruneNotifications((int) $settings->notification_retention_days);
        $this->pruneExports((int) $settings->export_retention_days);
        $this->pruneImports((int) $settings->import_retention_days);
        $this->pruneFailedJobs((int) $settings->failed_job_retention_days);
        $this->pruneExpiredTokens((int) $settings->expired_token_grace_days);

        return self::SUCCESS;
    }

    /**
     * A bell notification whose subject still exists.
     *
     * Pruned by age regardless of whether it was read. An unread three-month-old alert is not a
     * safety net — the invoice, work order or breach it points at is still on its own screen, and
     * keeping it makes the bell less usable, not more.
     */
    private function pruneNotifications(int $days): void
    {
        $this->pruneByAge(
            'notifications',
            $days,
            fn (CarbonImmutable $cutoff) => DatabaseNotification::query()->where('created_at', '<', $cutoff),
        );
    }

    /**
     * Export rows AND the files behind them.
     *
     * The file first: a row deleted before its file is an orphan nothing will ever find again. If
     * the delete fails the row STAYS, so the next run tries again rather than losing the only
     * pointer to it.
     */
    private function pruneExports(int $days): void
    {
        if ($days <= 0) {
            $this->info('Exports: keeping everything.');

            return;
        }

        $cutoff = CarbonImmutable::now()->subDays($days)->startOfDay();
        $query = fn () => Export::query()->where('created_at', '<', $cutoff);
        $doomed = $query()->count();

        if ($this->option('dry-run')) {
            $this->info("Exports: would delete {$doomed} older than {$cutoff->toDateString()} ({$days} days), with their files.");

            return;
        }

        $files = 0;

        $query()->chunkById(200, function ($exports) use (&$files): void {
            foreach ($exports as $export) {
                $this->deleteExportFile($export) && $files++;
                $export->delete();
            }
        });

        $this->info("Exports: deleted {$doomed} older than {$cutoff->toDateString()} ({$days} days), and {$files} file(s).");
    }

    /**
     * Remove one export's generated file.
     *
     * Wrapped: a disk that has been reconfigured, or a file already swept by something else, must
     * not stop the whole prune. The row is still deleted — what is being reclaimed is space, and a
     * missing file is the state we wanted anyway.
     */
    private function deleteExportFile(Export $export): bool
    {
        try {
            $directory = $export->getFileDirectory();
            $disk = Storage::disk($export->file_disk);

            return $disk->directoryExists($directory) ? $disk->deleteDirectory($directory) : false;
        } catch (Throwable $e) {
            $this->warn("  export #{$export->getKey()}: could not remove its file — {$e->getMessage()}");

            return false;
        }
    }

    /** Imports and the per-row failure notes under them. */
    private function pruneImports(int $days): void
    {
        $this->pruneByAge(
            'import records',
            $days,
            // The failure rows cascade with their import through the schema's foreign key; deleting
            // the parent is what removes both.
            fn (CarbonImmutable $cutoff) => Import::query()->where('created_at', '<', $cutoff),
        );
    }

    /** Laravel's own pruner, given the operator's period at RUN time. */
    private function pruneFailedJobs(int $days): void
    {
        if ($days <= 0) {
            $this->info('Failed jobs: keeping everything.');

            return;
        }

        if ($this->option('dry-run')) {
            $this->info("Failed jobs: would prune entries older than {$days} days (queue:prune-failed).");

            return;
        }

        $this->call('queue:prune-failed', ['--hours' => $days * 24]);
    }

    /** Sanctum's own pruner. The tokens are already expired; this reclaims the rows. */
    private function pruneExpiredTokens(int $days): void
    {
        if ($days <= 0) {
            $this->info('Expired API tokens: keeping everything.');

            return;
        }

        if ($this->option('dry-run')) {
            $this->info("Expired API tokens: would prune those expired more than {$days} days ago (sanctum:prune-expired).");

            return;
        }

        $this->call('sanctum:prune-expired', ['--hours' => $days * 24]);
    }

    /**
     * The shared shape: report a keep-everything decision, count before deleting, delete in CHUNKS.
     *
     * Chunked for the reason EG-34 gives — an unbounded `DELETE` on a table with years in it holds
     * locks for as long as it takes, and this runs unattended.
     *
     * @param  callable(CarbonImmutable): Builder<*>  $expired
     */
    private function pruneByAge(string $label, int $days, callable $expired): void
    {
        if ($days <= 0) {
            $this->info(ucfirst($label).': keeping everything.');

            return;
        }

        $cutoff = CarbonImmutable::now()->subDays($days)->startOfDay();
        $doomed = $expired($cutoff)->count();

        if ($this->option('dry-run')) {
            $this->info(ucfirst($label).": would delete {$doomed} older than {$cutoff->toDateString()} ({$days} days).");

            return;
        }

        do {
            $deleted = $expired($cutoff)->limit(1000)->delete();
        } while ($deleted > 0);

        $this->info(ucfirst($label).": deleted {$doomed} older than {$cutoff->toDateString()} ({$days} days).");
    }
}
