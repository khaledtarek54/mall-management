<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `leases.billing_day` promised a per-lease billing day and was read by NOTHING.
 *
 * It shipped in the original 2024 schema with the comment *"day of month to issue invoice"*, and in
 * the whole life of the system no service, screen, report, importer or API resource ever read it.
 * It was not even shaped for the job it advertised: it was cast as a `date`, so the QA harness's
 * `'billing_day' => 1` stored **1 January 1970** rather than "the 1st".
 *
 * The real answer is one portfolio-wide setting — `BillingSettings::monthly_billing_day`, which
 * `routes/console.php` turns into the cron expression for `MonthlyBillingService`. That is the ONE
 * definition, and this column was a second one that nothing maintained: exactly the shape
 * `security_deposit_received` had before `2026_08_18_090000` dropped it.
 *
 * **Dropped rather than honoured, deliberately.** Honouring it is not a column read — the monthly
 * run is a single scheduled sweep over every lease, so a per-lease billing day means splitting the
 * run into per-day cohorts, which is a change to the run's shape and its idempotency stamp. That is
 * real work with a real design question behind it (per-lease, or per-property, which is what an
 * operator running several malls actually asks for — EG-18 in docs/EGYPT-MARKET-FIT.md). Leaving a
 * column that reads as support and grants none is the worse of the two outcomes: it invites an
 * importer or an API client to set it and believe something happened.
 *
 * No data migration. The only writer was the QA harness, and what it wrote was meaningless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('billing_day');
        });
    }

    public function down(): void
    {
        // Restores the COLUMN, not values: there were none worth restoring. A rollback that
        // invented a plausible billing day would be worse than an empty one.
        Schema::table('leases', function (Blueprint $table) {
            $table->date('billing_day')->nullable()->after('percentage_rent_rate');
        });
    }
};
