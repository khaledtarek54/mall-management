<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give floors an ORDER (story: space model, docs/benchmarks/yardi/09-yardi-space-and-parking.md).
 *
 * **This replaces a workaround that is nearly right.** `units.floor` is free text, so `OccupancyMap`
 * carries a three-clause `orderByRaw` to force a sane order: a CASE lifting 'ground'/'g'/'0' to the
 * front, then `length()`, then the value. It gets the common case right — Ground → 1 → 2 → 10 — and
 * then:
 *
 *     Ground → 1 → 2 → 10 → Basement → Mezzanine
 *
 * A basement sorts AFTER the tenth floor, because the CASE only knows about the ground floor and
 * `length()` is doing the rest of the work. Three further problems the ordinal fixes and the
 * workaround cannot: it is raw SQL using `lower()`/`length()` (the cross-database hazard this
 * project has already hit twice), it lives in exactly one screen so every other consumer still gets
 * plain string order, and free text means "Ground"/"G"/"ground" remain three different groups to
 * anything that groups rather than sorts.
 *
 * **Why an ordinal and not a `Floor` entity.** Core Voyager keeps floor as an ATTRIBUTE of the
 * space; the floor-and-stacking capability is a separate product layered over the lease data, and
 * the stacking plan is a view rather than a hierarchy. An ordinal fixes the sort, makes floors
 * groupable and is the precondition for a stacking plan — without inventing a hierarchy the
 * benchmark itself does not have. Promote `Floor` to an entity only when per-floor GLA or
 * common-area figures are actually needed.
 *
 * The label stays: "Ground" is what an operator says, and 0 is what a computer sorts by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            // Basement negative, ground 0, first 1… Nullable because a unit may genuinely have no
            // floor (an external kiosk), and a NULL sorts apart from a real level rather than
            // pretending to be the ground floor.
            $table->smallInteger('floor_level')->nullable()->after('floor');
            $table->index(['asset_id', 'floor_level']);
        });

        // Backfill from the labels already in use. Anything unrecognised stays NULL rather than
        // being guessed at — a wrong ordinal sorts confidently and wrongly, which is worse than an
        // obviously missing one.
        $map = [
            'basement' => -1, 'b1' => -1, 'b' => -1, 'b2' => -2,
            'ground' => 0, 'g' => 0, 'gf' => 0, 'lower ground' => -1,
            'mezzanine' => 1, 'm' => 1,
        ];

        foreach (DB::table('units')->select('id', 'floor')->whereNotNull('floor')->get() as $unit) {
            $label = strtolower(trim((string) $unit->floor));

            $level = $map[$label] ?? (is_numeric($label) ? (int) $label : null);

            if ($level !== null) {
                DB::table('units')->where('id', $unit->id)->update(['floor_level' => $level]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropIndex(['asset_id', 'floor_level']);
            $table->dropColumn('floor_level');
        });
    }
};
