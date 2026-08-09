<?php

namespace App\Console\Commands;

use App\Services\PostStraightLineRentService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Post the month's straight-line rent adjustments (story RA-02).
 *
 * A no-op while `BillingSettings::straight_line_rent_enabled` is false, which is how it ships — so
 * this can sit on the schedule from day one and start working the moment the accountant flips the
 * switch, rather than needing a deploy at that point.
 */
class PostStraightLineRentCommand extends Command
{
    protected $signature = 'accounting:post-straight-line-rent
        {--month= : The month to recognise (YYYY-MM); defaults to the current one}';

    protected $description = 'Recognise rent on a straight-line basis for the month (no-op unless enabled in Settings → Billing)';

    public function handle(PostStraightLineRentService $service): int
    {
        $month = $this->option('month')
            ? CarbonImmutable::parse($this->option('month').'-01')
            : CarbonImmutable::now();

        $stats = $service->postForMonth($month->startOfMonth());

        if (! $stats['enabled']) {
            $this->components->info(
                "Straight-line rent is switched off — nothing posted for {$stats['period']}. ".
                'Enable it in Settings → Billing once the accountant has signed it off.'
            );

            return self::SUCCESS;
        }

        $this->components->info(
            "{$stats['period']}: {$stats['posted']} adjustments posted, {$stats['skipped']} leases skipped ".
            "of {$stats['leases']} considered."
        );

        return self::SUCCESS;
    }
}
