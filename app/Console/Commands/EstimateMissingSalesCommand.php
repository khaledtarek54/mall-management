<?php

namespace App\Console\Commands;

use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use App\Support\OpsLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Raise an ESTIMATED sales declaration for a percentage-rent tenant who never declared.
 *
 * **The leak this closes.** `sales:scan-missing-declarations` chases the tenant and stops there.
 * Nothing bills, so a tenant who simply never files pays no percentage rent at all — silence is a
 * complete and costless way to avoid the charge. Yardi bills an estimate and retro-bills the true
 * figure when it arrives; this is that, minus the retro-billing, which the existing lock/void
 * re-truing already provides.
 *
 * **The estimate is the tenant's own trailing average**, not a landlord guess: the mean of their
 * last three locked declarations. That is defensible to a tenant, self-correcting as they trade,
 * and — critically — it is superseded the moment they file, because a real declaration for the
 * period replaces the estimate rather than adding to it.
 *
 * Deliberately NOT locked. An estimate is a prompt for a decision, not a fact: locking it would
 * bill the tenant on a number nobody has agreed. The operator reviews and locks, which is the same
 * gate every other percentage-rent charge passes through.
 *
 * **AN ESTIMATE FOLLOWS A RECORDED REMINDER (SW-253, 2026-09-11).** This ran "a week after the
 * chase" — as a schedule day. It never asked whether the chase had happened, so a reminder lost to
 * any cause (SW-252: a mail-first channel over a dead transport swallowed August's whole run) still
 * ended in an estimate on a tenant nobody had asked. The reminder is a STAMP now: a lease is
 * estimated only when `Lease::salesDeclarationRemindedAt()` — the same record the chase writes
 * and reads for its own idempotency — is at least `--after-days` whole calendar days old. The
 * benchmark (`docs/benchmarks/yardi/03` B5) documents that Voyager bills on estimated sales when
 * a declaration is missing and documents NO notice as a prerequisite, so this is read as STRICTER
 * than Voyager and stated as such: an estimate is a claim the tenant must be able to answer, and
 * the answer starts with having been told.
 *
 * Two consequences, both designed rather than accepted. A lease with NO reminder is skipped and
 * REPORTED (ops log + the console), never chased from here — chasing stays the scan's one job, and
 * an operator re-runs it with `--period` for a month the box missed, then this with the same
 * `--period` a week later if the month will have left the lookback by the next 17th. And the
 * default run looks back THREE declarable months rather than one, because a late chase must still
 * end in an estimate: without the lookback, a period whose reminder went out after the 17th was
 * never estimated by anything, which reopens the exact leak this command exists to close.
 *
 * **The run records its own outcome** (`sales.estimate_run` on the ops log) — the console is
 * `/dev/null` under cron, and a run whose only result is "too soon" would otherwise leave nothing
 * on the box to tell "ran and skipped" from "never ran", which is SW-252's shape again.
 *
 * The stamp is a `notifications` row, which `atriom:prune-transient-data` deletes by age
 * (`HousekeepingSettings::notification_retention_days`, default 90). The oldest reminder this
 * command consults is ~68 days (chased on the 10th of M+1, still inside the lookback on the 17th of
 * M+3), so the default holds and a retention under ~70 days would make a chased lease read as
 * unchased. `AnEstimateFollowsARecordedReminderTest` pins the default against the lookback.
 */
class EstimateMissingSalesCommand extends Command
{
    protected $signature = 'sales:estimate-missing
        {--period= : First-of-month YYYY-MM-01 to estimate; defaults to the last three declarable months}
        {--min-history=2 : Minimum locked declarations required before an estimate is defensible}
        {--after-days=7 : Whole days a recorded reminder must be old before the estimate may follow it}
        {--dry-run : Print what would be raised without writing}';

    /** How many declarable months a default run looks back — a late chase must still end in an estimate. */
    public const LOOKBACK_MONTHS = 3;

    protected $description = 'Raise estimated sales declarations for percentage-rent tenants who never declared (idempotent).';

    public function handle(): int
    {
        $periods = $this->option('period')
            ? [CarbonImmutable::parse($this->option('period'))->startOfMonth()]
            : collect(range(0, self::LOOKBACK_MONTHS - 1))
                // The ONE definition of "the last month a declaration can exist for", shared with
                // `sales:scan-missing-declarations` and with the two reports that divide by it.
                ->map(fn (int $back) => TenantSalesDeclaration::lastDeclarableMonth()->subMonthsNoOverflow($back))
                ->all();
        $minHistory = (int) $this->option('min-history');
        $afterDays = max(0, (int) $this->option('after-days'));
        $dryRun = (bool) $this->option('dry-run');

        $raised = 0;
        $skipped = 0;
        $unchased = [];
        $tooSoon = [];

        foreach ($periods as $periodStart) {
            $periodEnd = $periodStart->endOfMonth();
            $periodKey = $periodStart->format('Y-m');

            // The same definition of "owes a declaration" the reminder scan and the month-end
            // checklist use — one rule, three callers.
            foreach (Lease::missingSalesDeclarationsFor($periodStart, $periodEnd) as $lease) {
                try {
                    // The gate (SW-253): no recorded reminder, no estimate. Reported rather than
                    // chased from here — see the class docblock.
                    $remindedAt = $lease->salesDeclarationRemindedAt($periodKey);

                    if ($remindedAt === null) {
                        $unchased[] = "{$lease->reference} · {$periodKey}";
                        $skipped++;

                        continue;
                    }

                    // Whole calendar DAYS, compared as dates: the chase runs on the 10th at 08:00
                    // and this on the 17th at 07:30 — a week by the calendar and thirty minutes
                    // short of one by the clock. Dates rather than a Carbon day-diff, because on
                    // Egypt's DST-start day 00:00 does not exist, `startOfDay()` lands on 01:00,
                    // and a float diff reads the seventh day as 6.96 (the suite pins UTC and
                    // cannot see it). The ordinary schedule passes; a reminder sent yesterday
                    // does not; a future-dated one is "too soon" for as long as it takes.
                    if ($remindedAt->toDateString() > CarbonImmutable::today()->subDays($afterDays)->toDateString()) {
                        $this->line(sprintf('  too soon · %s · %s · reminded %s', $lease->reference, $periodKey, $remindedAt->toDateString()));
                        $tooSoon[] = "{$lease->reference} · {$periodKey} · reminded {$remindedAt->toDateString()}";
                        $skipped++;

                        continue;
                    }

                    $history = TenantSalesDeclaration::query()
                        ->where('lease_id', $lease->id)
                        ->where('status', 'locked')
                        ->whereDate('period_start', '<', $periodStart->toDateString())
                        ->orderByDesc('period_start')
                        ->limit(3)
                        ->pluck('declared_sales');

                    // Without history there is no defensible number. Inventing one would be
                    // inventing data — the same rule that stops the escalation sweep guessing a
                    // CPI figure. The tenant keeps being chased by the reminder scan instead.
                    if ($history->count() < $minHistory) {
                        $skipped++;

                        continue;
                    }

                    $estimate = round((float) $history->avg(), 2);

                    if ($estimate <= 0) {
                        $skipped++;

                        continue;
                    }

                    if ($dryRun) {
                        $this->line(sprintf('  would estimate %s · %s · %s',
                            $lease->reference, $periodStart->format('M Y'), number_format($estimate, 2)));
                        $raised++;

                        continue;
                    }

                    $this->raise($lease, $periodStart, $periodEnd, $estimate) ? $raised++ : $skipped++;
                } catch (Throwable $e) {
                    OpsLog::error('sales_estimate.failed', ['lease_id' => $lease->id, 'period' => $periodKey, 'error' => $e->getMessage()]);
                    $skipped++;
                }
            }
        }

        // A lease nobody asked is the finding this run cannot act on, so it is RECORDED where the
        // daily check reads — the box, not the console (a scheduled command's output is /dev/null).
        if ($unchased !== []) {
            $this->warn('Not estimated — no reminder on record. Re-run sales:scan-missing-declarations --period=… first, '
                .'then sales:estimate-missing --period=… a week later if that month will have left the lookback:');
            foreach ($unchased as $line) {
                $this->line("  {$line}");
            }

            if (! $dryRun) {
                OpsLog::warning('sales.estimate_skipped_unchased', ['count' => count($unchased), 'leases' => $unchased]);
            }
        }

        $label = count($periods) === 1
            ? $periods[0]->format('F Y')
            : end($periods)->format('M Y').' – '.$periods[0]->format('M Y');

        // The outcome, on the box: the console is /dev/null under cron.
        if (! $dryRun) {
            OpsLog::info('sales.estimate_run', [
                'periods' => array_map(fn (CarbonImmutable $p) => $p->format('Y-m'), $periods),
                'raised' => $raised,
                'skipped' => $skipped,
                'too_soon' => $tooSoon,
                'unchased' => count($unchased),
            ]);
        }

        $this->info(($dryRun ? 'Would raise ' : 'Raised ')."{$raised} estimated declaration(s) for {$label}; {$skipped} skipped.");

        if ($dryRun) {
            $this->warn('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }

    private function raise(Lease $lease, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, float $estimate): bool
    {
        return DB::transaction(function () use ($lease, $periodStart, $periodEnd, $estimate) {
            // Re-check inside the transaction: between the query above and here the tenant may
            // have filed, and their real figure must never be overwritten by an estimate.
            $exists = TenantSalesDeclaration::query()
                ->where('lease_id', $lease->id)
                ->whereDate('period_start', $periodStart->toDateString())
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                return false;
            }

            TenantSalesDeclaration::create([
                'lease_id' => $lease->id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'declared_sales' => $estimate,
                'is_estimate' => true,
                'declared_at' => now(),
                'status' => 'submitted',
                'audit_notes' => 'Estimated by the operator from the tenant\'s last '
                    .'declarations — no return was filed for this period.',
            ]);

            return true;
        });
    }
}
