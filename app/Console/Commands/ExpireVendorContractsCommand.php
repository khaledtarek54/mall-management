<?php

namespace App\Console\Commands;

use App\Models\VendorContract;
use Illuminate\Console\Command;

class ExpireVendorContractsCommand extends Command
{
    protected $signature = 'vendors:expire-contracts {--dry-run : Print what would change without writing}';

    protected $description = 'Transition active VendorContract rows past their end_date to status=expired (audit M15 F-58 / D-43).';

    public function handle(): int
    {
        $candidates = VendorContract::query()
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', today());

        $count = $candidates->count();

        if ($count === 0) {
            $this->info('No active vendor contracts past their end_date.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("Would expire {$count} vendor contract(s):");
            $candidates->with('vendor:id,name')->get(['id', 'vendor_id', 'reference', 'name', 'end_date'])
                ->each(function (VendorContract $c) {
                    $this->line(sprintf(
                        '  #%d %s · %s · %s · ended %s',
                        $c->id,
                        $c->reference ?: '—',
                        $c->vendor?->name ?? '—',
                        $c->name,
                        $c->end_date->format('Y-m-d'),
                    ));
                });

            return self::SUCCESS;
        }

        $updated = $candidates->update(['status' => 'expired']);

        $this->info("Expired {$updated} vendor contract(s).");

        return self::SUCCESS;
    }
}
