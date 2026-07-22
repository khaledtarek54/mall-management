<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Percentage-rent breakpoint frequency: `monthly` (the breakpoint applies fresh each declared month —
 * the existing behaviour) or `annual` (the breakpoint is a YEARLY figure; each locked month bills the
 * DELTA that trues the year's total billed % rent up to `overage(cumulative sales this year)` — the
 * incremental amount, netting a seasonal spike against the running total instead of over-charging it
 * against a monthly breakpoint). Defaults to `monthly`, so existing leases are byte-identical.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            // String, not a DB-level enum — the allowed values are enforced in the app (the Filament
            // Select's options() validate server-side), keeping the value set changeable without a
            // schema migration.
            $table->string('percentage_rent_frequency')->default('monthly')
                ->after('percentage_rent_calculation_type');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('percentage_rent_frequency');
        });
    }
};
