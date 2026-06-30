<?php

namespace Database\Seeders;

use App\Services\Accounting\FiscalCalendar;
use Illuminate\Database\Seeder;

/**
 * Orchestrates the General-Ledger reference data: the starter chart of accounts,
 * the default semantic account mappings, and an open fiscal year (current +
 * previous, so backfilled history and current postings both have an open period).
 *
 * Runs in every environment (reference data, not demo data) — wired into
 * DatabaseSeeder before DemoSeeder.
 */
class AccountingSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ChartOfAccountsSeeder::class,
            AccountMappingSeeder::class,
        ]);

        $calendar = app(FiscalCalendar::class);
        $thisYear = (int) now()->year;
        $calendar->ensureYear($thisYear - 1);
        $calendar->ensureYear($thisYear);
    }
}
