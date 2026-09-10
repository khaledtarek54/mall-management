<?php

namespace App\Console\Commands;

use App\Services\ScanUnbilledPeriodsService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Weekly: lease-months the term covered that were never invoiced.
 *
 * The sibling of `atriom:audit-charge-schedules`, and delivered the same way for the same reason —
 * both answer "this lease is quietly billing less than it should" and both go to whoever runs the
 * books, not to a tenant. It is deliberately NOT a bell: the finding is portfolio-level finance
 * work, and the per-lease notifications in this system exist for conversations with a counterparty.
 *
 * It is also deliberately NOT part of `atriom:preflight`. An install carrying historical gaps —
 * an imported book of business, a staging box seeded with back-dated history — would make that step
 * permanently red, and a permanently red step is one people stop reading. Exits 0 with its findings
 * printed unless `--strict` is passed, which is for a cutover where the answer must be none.
 */
class ScanUnbilledPeriodsCommand extends Command
{
    protected $signature = 'billing:scan-unbilled-periods
        {--months= : how many completed months to look back over (default 12)}
        {--date= : YYYY-MM-DD, the month to count back from; defaults to today}
        {--strict : exit non-zero when any gap is found}';

    protected $description = 'Report lease-months whose term was billable and which were never invoiced.';

    public function handle(ScanUnbilledPeriodsService $service): int
    {
        $months = $this->option('months') !== null
            ? (int) $this->option('months')
            : ScanUnbilledPeriodsService::DEFAULT_LOOKBACK_MONTHS;

        $result = $service->run(
            $months,
            $this->option('date') ? CarbonImmutable::parse((string) $this->option('date')) : null,
        );

        if ($result['gaps'] === 0) {
            $this->info("Unbilled periods: none over the last {$result['months_scanned']} month(s).");

            return self::SUCCESS;
        }

        $this->warn("{$result['gaps']} lease-month(s) were billable and never invoiced, "
            ."worth EGP {$result['total']} over the last {$result['months_scanned']} month(s):");

        $this->table(
            ['Period', 'Lease', 'Tenant', 'Unit', 'Would bill'],
            array_map(fn (array $r): array => [
                $r['period'],
                $r['lease_reference'] ?? $r['lease_id'],
                $r['tenant_name'] ?? '—',
                $r['unit_code'] ?? '—',
                number_format($r['total'], 2),
            ], $result['rows']),
        );

        // Named rather than implied: the operator has to know this is a decision they take, not a
        // job that will catch itself up, and WHERE they take it.
        $this->line('  Raise them from the lease\'s Billing forecast tab — one period at a time, so the');
        $this->line('  posting date is a decision and a closed period is refused rather than silently skipped.');

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
