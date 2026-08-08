<?php

namespace App\Console\Commands;

use App\Models\Lease;
use App\Services\ChargeScheduleService;
use App\Support\OpsLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill the contracted rent ladder onto leases that predate schedule projection.
 *
 * `ChargeScheduleService::projectTermEscalations()` writes the whole term's steps when a lease is
 * created or renewed — but every lease signed before that shipped still carries a single
 * open-ended rent row. Their Charge schedule reads "no further steps scheduled" while the contract
 * says a 7% increase is due next March, which is worse than showing nothing: it is an answer, and
 * it is wrong.
 *
 * **This changes what a FUTURE month bills, and that is the point.** Before the backfill, billing
 * March 2027 ahead of the escalation sweep charges last year's rent; after it, the contracted one.
 * The rows created are the same rows the sweep would create anyway, on the same dates, from the
 * same `next_escalation_date` anchor — this just writes them now, where they can be reviewed,
 * instead of on the night they take effect. Already-billed months are untouched: the ladder starts
 * at the next anniversary, never before it.
 *
 * Dry-run by default. `--commit` writes.
 */
class ProjectLeaseSchedulesCommand extends Command
{
    protected $signature = 'atriom:project-lease-schedules
        {--commit : Actually write the rows (default is a dry run)}
        {--lease= : Restrict to one lease id}';

    protected $description = 'Backfill the contracted rent ladder onto leases created before schedule projection existed.';

    public function handle(ChargeScheduleService $schedule): int
    {
        $commit = (bool) $this->option('commit');

        $leases = Lease::query()
            ->where('status', 'active')
            ->where('escalation_type', 'fixed_percent')
            ->where('escalation_rate', '>', 0)
            ->whereNotNull('commencement_date')
            ->whereNotNull('expiry_date')
            ->when($this->option('lease'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        if ($leases->isEmpty()) {
            $this->info('No active leases with a contracted escalation.');

            return self::SUCCESS;
        }

        $rows = [];
        $totalCreated = 0;
        $skipped = 0;

        foreach ($leases as $lease) {
            // Already laddered — re-running must not duplicate. setAmount() would no-op anyway
            // (same amount already in force), but skipping keeps the report honest.
            if ($lease->charges()->where('origin', \App\Models\Charge::ORIGIN_ESCALATION)->exists()) {
                $skipped++;

                continue;
            }

            // Dry run: project inside a transaction and roll it back, so what is REPORTED is what
            // would actually be written — not a second estimate of it.
            DB::beginTransaction();
            $created = $schedule->projectTermEscalations($lease);

            $steps = $lease->charges()
                ->where('origin', \App\Models\Charge::ORIGIN_ESCALATION)
                ->where('type', 'base_rent')
                ->orderBy('start_date')
                ->get(['amount', 'start_date']);

            $rows[] = [
                $lease->reference,
                number_format((float) $lease->base_rent_monthly, 2),
                $lease->escalation_rate.'%',
                $steps->count(),
                $steps->isEmpty() ? '—' : $steps->first()->start_date->format('d/m/Y').' → '.number_format((float) $steps->first()->amount, 2),
            ];

            $commit ? DB::commit() : DB::rollBack();
            $totalCreated += $created;
        }

        $this->table(['Lease', 'Rent now', 'Rate', 'Steps', 'First step'], $rows);

        $verb = $commit ? 'Created' : 'Would create';
        $this->info("{$verb} {$totalCreated} schedule row(s) across ".count($rows).' lease(s); '."{$skipped} already laddered.");

        if (! $commit) {
            $this->warn('Dry run — nothing was written. Re-run with --commit to apply.');

            return self::SUCCESS;
        }

        OpsLog::info('Lease schedules projected', ['leases' => count($rows), 'rows' => $totalCreated]);

        return self::SUCCESS;
    }
}
