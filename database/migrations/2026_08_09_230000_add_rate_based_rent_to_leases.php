<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rent priced as a rate per m² per year (story LS-04).
 *
 * **What was missing.** Commercial rent is negotiated per square metre almost everywhere, and
 * Atriom stored only a flat monthly figure: `units.area_sqm` existed and priced nothing. Two
 * consequences — an operator could not compare two deals on the only basis that makes them
 * comparable, and when the let area changed the rent had to be recomputed by hand and retyped.
 *
 * **The rate lives on the LEASE, the derived amount lives on the schedule.** That split matters:
 * a schedule row must go on recording what was actually in force for its months (the phase-1
 * invariant), so it keeps storing a flat amount. The rate is the *term* the amount was derived
 * from, and it is what re-derives the money when an expansion changes the area.
 *
 * `rent_pricing_basis` defaults to `flat`, so every existing lease keeps its typed rent and nothing
 * re-prices on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            // String, not a DB enum — the house rule.
            $table->string('rent_pricing_basis')->default('flat')->after('base_rent_monthly');
            $table->decimal('base_rent_rate_per_sqm_year', 12, 2)->nullable()->after('rent_pricing_basis');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn(['rent_pricing_basis', 'base_rent_rate_per_sqm_year']);
        });
    }
};
