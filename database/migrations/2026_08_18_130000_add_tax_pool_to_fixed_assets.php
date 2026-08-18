<?php

use App\Support\TaxDepreciation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which Egyptian tax pool each fixed asset falls in (Law 91/2005, Article 25).
 *
 * `fixed_assets.category` is free text the operator invents ("HVAC", "Fit-out", "IT"), so it cannot
 * be mapped to a statutory pool automatically — the same word means different things in two malls.
 * The pool is therefore its own column, stated rather than inferred.
 *
 * Backfilled to `general`, which is the law's OWN residual category ("all other assets of the
 * activity") — so an asset nobody has classified is treated the way the statute treats it rather
 * than silently dropped from the schedule. Excluding something (land) has to be said out loud.
 *
 * A `string(32)`, never an enum: `App\Support\ValueSets` is where the allowed values live and the
 * global saving listener is what enforces them. See CLAUDE.md — the DB-enum count is zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->string('tax_pool', 32)->nullable()->after('method');
        });

        DB::table('fixed_assets')->whereNull('tax_pool')->update(['tax_pool' => TaxDepreciation::default()]);
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropColumn('tax_pool');
        });
    }
};
