<?php

namespace App\Console\Commands;

use App\Services\ScanChequeCoverageService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Nightly: which tenants are about to run out of lodged cheques while their lease runs on.
 *
 * The sibling of `pdc:scan-maturing`, and deliberately a separate command rather than another
 * flag on it, because they answer opposite questions. That one reports instruments that EXIST
 * and are late; this reports the instruments that do not exist yet. Folding them together would
 * produce a command whose output mixes "collect this" with "ask for this", and the two go to
 * different people on different timescales.
 */
class ScanChequeCoverageCommand extends Command
{
    protected $signature = 'pdc:scan-coverage
        {--days=60 : warn when the last lodged cheque matures within this many days}
        {--date= : YYYY-MM-DD, defaults to today}';

    protected $description = 'Report active leases whose lodged post-dated cheques run out before the lease term does.';

    public function handle(ScanChequeCoverageService $service): int
    {
        $today = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : null;

        $result = $service->run((int) $this->option('days'), $today);

        if ($result['ending'] === 0) {
            $this->info("Cheque coverage: {$result['scanned']} lease(s) with lodged cheques, none running out.");

            return self::SUCCESS;
        }

        $this->warn("Cheque coverage ending on {$result['ending']} of {$result['scanned']} lease(s):");

        $this->table(
            ['Lease', 'Covered to', 'Lease expires', 'Uncovered months'],
            array_map(fn (array $r): array => [
                $r['lease_id'],
                $r['covered_to'],
                $r['expiry'],
                $r['uncovered_months'],
            ], $result['leases']),
        );

        return self::SUCCESS;
    }
}
