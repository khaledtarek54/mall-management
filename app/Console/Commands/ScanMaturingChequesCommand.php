<?php

namespace App\Console\Commands;

use App\Models\PostDatedCheque;
use App\Support\OpsLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ScanMaturingChequesCommand extends Command
{
    protected $signature = 'pdc:scan-maturing {--days=7 : maturity horizon in days} {--date= : YYYY-MM-DD, defaults to today}';

    protected $description = 'Report post-dated cheques already matured but not yet cleared, and those maturing soon (the maturity schedule).';

    public function handle(): int
    {
        $today = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : CarbonImmutable::now();
        $days = (int) $this->option('days');

        // Off the shared model scopes, so this nightly report, the Action Required card and the
        // register's "Matured & uncleared" filter can never disagree about what's due.
        $matured = PostDatedCheque::query()->maturedUncleared($today)->count();
        $upcoming = PostDatedCheque::query()->maturingWithin($days, $today)->count();

        // Matured-but-uncleared is money the register expected by now — surface it off-box.
        if ($matured > 0) {
            OpsLog::warning('pdc.matured_uncleared', ['count' => $matured, 'as_of' => $today->toDateString()]);
        }

        $this->info("Matured & uncleared: {$matured}; maturing within {$this->option('days')}d: {$upcoming}.");

        return self::SUCCESS;
    }
}
