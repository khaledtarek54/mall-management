<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **When percentage rent is CHARGED is a different term from how it is CALCULATED.**
 *
 * `percentage_rent_frequency` is the calculation basis — period-by-period, or cumulative
 * year-to-date. Billing was not modelled at all: the overage was invoiced the moment a declaration
 * was locked, so every lease charged monthly whatever its contract said. A clause reading
 * *"percentage rent payable quarterly in arrears within 30 days of the quarter end"* — an entirely
 * ordinary retail term — could not be expressed.
 *
 * Yardi carries the two separately (plus a third, the reporting frequency), and the project's own
 * benchmark says so in bold: *a system that assumes they are the same cannot express the most common
 * retail deal* (docs/benchmarks/yardi/03).
 *
 * **Defaults to `monthly`, which is what every existing lease was already doing** — so this
 * migration changes no money. A string, not an enum, per the project convention: the value set lives
 * in `App\Support\ValueSets` and is enforced on every model save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->string('percentage_rent_billing_frequency', 32)
                ->default('monthly')
                ->after('percentage_rent_frequency');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('percentage_rent_billing_frequency');
        });
    }
};
