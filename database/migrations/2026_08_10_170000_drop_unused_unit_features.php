<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop `units.features` — a column nothing ever wrote and nothing ever read.
 *
 * It shipped with the original units table as a JSON array, was added to `$fillable` and `$casts`,
 * and then: no form field, no table column, no report, no service. Zero rows carry a value in the
 * demo. The ONLY writer was `UnitFactory`, which is the "tests green over dead code" trap — a
 * fixture populating a column no form, service or seeder ever fills, so every test that touched it
 * proved something about a field the application does not have.
 *
 * Removed rather than wired up, because there is no stated requirement for it: unit amenities are
 * expressible in `description` today, and inventing a features taxonomy nobody asked for would be
 * building the wrong thing carefully.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('features');
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->json('features')->nullable()->after('area_sqm');
        });
    }
};
