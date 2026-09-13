<?php

namespace App\Console\Commands;

use App\Models\Charge;
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
        {--retrue : Also RE-TRUE leases already laddered — prune their not-yet-started projected rungs and project again from the clause as it now reads (the repair the Lease hook runs on an edit; here for ladders that drifted before the hook existed, or that were projected before the collar was written into the schedule)}
        {--lease= : Restrict to one lease id}';

    protected $description = 'Backfill the contracted rent ladder onto leases created before schedule projection existed.';

    public function handle(ChargeScheduleService $schedule): int
    {
        $commit = (bool) $this->option('commit');

        // Both projectable clause types, matching `projectTermEscalations()` exactly — a narrower
        // query here would report "No active leases with a contracted escalation" about a portfolio
        // full of them (2026-08-16: `fixed_amount` was excluded in three places at once — the
        // projector, this backfill, and the panel heading — so an amount-escalating lease had its
        // rent moved every year by the sweep with nothing anywhere saying it would).
        // Every lease OPEN to a commercial act carries a ladder worth repairing — a `future` or
        // `pending_approval` lease bills nothing yet, which is exactly when its rungs are cheapest
        // to re-date (2026-09-13: the box's pending lease kept its snapped rungs through the
        // anniversary-day repair because this read `active` alone).
        $leases = Lease::query()
            ->whereIn('status', Lease::OPEN_TO_COMMERCIAL_ACTS)
            ->where(fn ($q) => $q
                ->where(fn ($p) => $p->where('escalation_type', 'fixed_percent')->where('escalation_rate', '>', 0))
                ->orWhere(fn ($a) => $a->where('escalation_type', 'fixed_amount')->where('escalation_amount', '>', 0)))
            ->whereNotNull('commencement_date')
            ->whereNotNull('expiry_date')
            ->when($this->option('lease'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        if ($leases->isEmpty()) {
            $this->info('No open leases with a contracted escalation.');

            return self::SUCCESS;
        }

        $rows = [];
        $totalCreated = 0;
        $skipped = 0;
        $retrued = 0;

        foreach ($leases as $lease) {
            // Already laddered — re-running must not duplicate. setAmount() would no-op anyway
            // (same amount already in force), but skipping keeps the report honest. `--retrue`
            // takes those through the hook's own method instead — started rungs stay, a stated
            // rung stays, a relief window is walked through — so the console repairs exactly
            // what an edit would (2026-09-11: the ladders projected before the collar was written
            // into the schedule, and the ones that drifted before the hook existed).
            $laddered = $lease->charges()->where('origin', Charge::ORIGIN_ESCALATION)->exists();

            if ($laddered && ! $this->option('retrue')) {
                $skipped++;

                continue;
            }

            if ($laddered) {
                $retrued++;
            }

            // Dry run: project inside a transaction and roll it back, so what is REPORTED is what
            // would actually be written — not a second estimate of it.
            DB::beginTransaction();
            $created = $laddered
                ? $schedule->retrueProjectedLadder($lease)
                : $schedule->projectTermEscalations($lease);

            // ACTIVE rungs only: a re-trued ladder leaves its retired rungs in the table (the
            // audit trail), and without this the dry run reported a 100 % clause as stepping
            // 7 % — the tester's old rungs, first by date, counted as the ladder.
            $steps = $lease->charges()
                ->where('origin', Charge::ORIGIN_ESCALATION)
                ->where('type', 'base_rent')
                ->where('is_active', true)
                ->orderBy('start_date')
                ->get(['amount', 'start_date']);

            $rows[] = [
                $lease->reference,
                number_format((float) $lease->base_rent_monthly, 2),
                // In the clause's OWN unit. Printed as a bare percentage, an amount lease reported
                // its step as "0.00%" — a number that reads as "no increase" beside four steps.
                (string) $lease->escalation_type === 'fixed_amount'
                    ? '+'.number_format((float) $lease->escalation_amount, 2).' EGP'
                    : $lease->escalation_rate.'%',
                $steps->count(),
                $steps->isEmpty() ? '—' : $steps->first()->start_date->format('d/m/Y').' → '.number_format((float) $steps->first()->amount, 2),
            ];

            $commit ? DB::commit() : DB::rollBack();
            $totalCreated += $created;
        }

        $this->table(['Lease', 'Rent now', 'Step', 'Steps', 'First step'], $rows);

        $verb = $commit ? 'Created' : 'Would create';
        $this->info("{$verb} {$totalCreated} schedule row(s) across ".count($rows).' lease(s); '.($this->option('retrue')
            ? "{$retrued} already laddered and re-trued."
            : "{$skipped} already laddered (pass --retrue to re-true them)."));

        if (! $commit) {
            $this->warn('Dry run — nothing was written. Re-run with --commit to apply.');

            return self::SUCCESS;
        }

        OpsLog::info('Lease schedules projected', ['leases' => count($rows), 'rows' => $totalCreated]);

        return self::SUCCESS;
    }
}
