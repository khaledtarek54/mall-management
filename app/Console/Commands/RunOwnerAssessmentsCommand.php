<?php

namespace App\Console\Commands;

use App\Services\BillUnitOwnershipsService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The monthly صيانة run for unit OWNERS (module 37) — the assessment side of billing.
 *
 * Deliberately a separate command from `billing:run-monthly`, for the same reason
 * {@see BillUnitOwnershipsService} is a separate service: an ownership has a tenure and a schedule
 * and none of a lease's rules (renewal, holdover, straight-line rent, escalation ladders), so
 * folding the two runs together would make every one of those answer "not applicable" at runtime.
 * The two take different cache locks and bill disjoint sets of agreements.
 *
 * Until 2026-08-18 this command did not exist and neither did its schedule entry, so nothing in
 * production ever called the service — module 37 shipped the billing and never raised an invoice
 * outside the demo seeder and the test suite. Every unit owner was silently un-billed.
 */
class RunOwnerAssessmentsCommand extends Command
{
    protected $signature = 'billing:run-assessments
        {--period= : YYYY-MM, defaults to current month}
        {--asset= : Restrict to one property id; omit for the whole portfolio}';

    protected $description = 'Raise the monthly service-charge assessment for every handed-over unit ownership (idempotent per ownership+period).';

    public function handle(BillUnitOwnershipsService $service): int
    {
        $periodOption = $this->option('period');

        $period = $periodOption
            // !Y-m, not Y-m: with no day in the format Carbon fills it from TODAY, and on the
            // 29th–31st that overflows a shorter month — "2026-02" parsed on the 29th becomes
            // 1 March. The `!` resets every unspecified field, giving midnight on the 1st.
            ? CarbonImmutable::createFromFormat('!Y-m', $periodOption)->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();

        $assetId = $this->option('asset') !== null ? (int) $this->option('asset') : null;

        $this->info("Running owner assessments for {$period->format('F Y')}...");
        $stats = $service->runForPeriod($period, $assetId);

        $this->table(
            ['Period', 'Considered', 'Created', 'Skipped', 'Failed'],
            [[$stats['period'], $stats['considered'], $stats['created'], $stats['skipped'], $stats['failed']]]
        );

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
