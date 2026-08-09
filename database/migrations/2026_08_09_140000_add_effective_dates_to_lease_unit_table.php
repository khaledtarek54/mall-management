<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lease's premises become date-ranged, like its rent (story LE-02, scenario S5).
 *
 * **What was wrong.** Phase 1 gave the *money* a schedule, but the *space* stayed a simple set: the
 * `lease_unit` pivot said which units a lease covers, with no notion of since when. So a mid-term
 * expansion — the tenant takes the adjacent unit from 1 November — was recorded by adding a pivot
 * row, and the extra area counted from the moment somebody clicked save. Bill October after making
 * the change and October gets November's floor area; the CAM share re-based retroactively over a
 * year the tenant did not occupy that space.
 *
 * Contraction was worse: giving space back meant *deleting* the pivot row, which erased the fact
 * that the tenant had ever held it. Any recovery reconciliation run afterwards re-apportioned the
 * whole year as if the unit had never been theirs.
 *
 * **Both columns are nullable and nothing is backfilled**, deliberately: NULL means "unbounded on
 * that side", so every pivot row that exists today reads as "held for the whole lease", which is
 * exactly what it has always meant. No existing lease, allocation or report changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_unit', function (Blueprint $table) {
            $table->date('effective_from')->nullable()->after('is_master');
            $table->date('effective_to')->nullable()->after('effective_from');
        });
    }

    public function down(): void
    {
        Schema::table('lease_unit', function (Blueprint $table) {
            $table->dropColumn(['effective_from', 'effective_to']);
        });
    }
};
