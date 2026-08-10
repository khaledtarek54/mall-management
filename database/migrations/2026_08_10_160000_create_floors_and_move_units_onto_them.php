<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Floors as a per-property register, selected — not typed (space model).
 *
 * **This replaces `units.floor_level`, shipped hours earlier in the same cycle.** That column asked
 * every unit to repeat the same ordinal — two hundred units each storing `0` for the ground floor,
 * any one of which could be typed wrong — and it left the LABEL free text, so "G" and "Ground"
 * stayed two different floors to anything that groups. It fixed sorting and nothing else.
 *
 * **A floor is property-level data, and there are perhaps eight of them.** B2, B1, G, M, 1, 2, 3 —
 * defined once when the property is set up, then SELECTED. That kills the data-quality hole at the
 * source rather than normalising after it, and it is what an operator expects: you do not retype
 * "Basement 1" on the ninetieth parking bay.
 *
 * **Rentable items are what made it decisive.** Bays live on B1 and B2. With a column, the parking
 * register would have carried its own free-text floor with no guarantee it agreed with the units'.
 * One register serves both, and the two can no longer disagree.
 *
 * **Deviation from Voyager's core, stated.** Core Voyager keeps floor as an attribute of the space.
 * But Yardi sells Floorplan Manager precisely because that core is insufficient for space
 * management, so citing it as "the standard" while ignoring the product built over the gap would be
 * following the letter against the evidence. `level` is the ordinal that makes B1 sort before G.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('floors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('code', 16);            // B2, B1, G, M, 1, 2 — what an operator types
            $table->string('name')->nullable();    // "Lower ground", "Mezzanine"
            // Basement negative, ground 0, first 1… The ordinal lives HERE, once per floor, instead
            // of being repeated on every unit standing on it.
            $table->smallInteger('level');
            $table->timestamps();

            $table->unique(['asset_id', 'code']);
            $table->unique(['asset_id', 'level']);
            $table->index(['asset_id', 'level']);
        });

        Schema::table('units', function (Blueprint $table) {
            $table->foreignId('floor_id')->nullable()->after('floor')->constrained('floors')->nullOnDelete();
        });

        Schema::table('rentable_items', function (Blueprint $table) {
            $table->foreignId('floor_id')->nullable()->after('area_id')->constrained('floors')->nullOnDelete();
        });

        // ── Backfill: one floor row per distinct label already in use, per property ────────────
        $known = [
            'basement' => ['B1', -1], 'b1' => ['B1', -1], 'b' => ['B1', -1], 'b2' => ['B2', -2],
            'lower ground' => ['LG', -1], 'lg' => ['LG', -1],
            'ground' => ['G', 0], 'g' => ['G', 0], 'gf' => ['G', 0], '0' => ['G', 0],
            'mezzanine' => ['M', 1], 'm' => ['M', 1],
        ];

        $rows = DB::table('units')
            ->select('asset_id', 'floor')
            ->whereNotNull('floor')
            ->where('floor', '!=', '')
            ->distinct()
            ->get();

        foreach ($rows as $row) {
            $label = strtolower(trim((string) $row->floor));

            [$code, $level] = $known[$label]
                ?? (is_numeric($label)
                    // A numeric label sits above the mezzanine: floor 1 is level 2 once M is 1.
                    ? [(string) (int) $label, (int) $label + 1]
                    // Unrecognised: keep the label as the code and park it above everything, so it
                    // is visible and re-orderable rather than silently guessed at.
                    : [substr((string) $row->floor, 0, 16), 900]);

            $floorId = DB::table('floors')
                ->where('asset_id', $row->asset_id)->where('code', $code)->value('id');

            if (! $floorId) {
                // `level` is unique per property; nudge a colliding one rather than failing the
                // deploy on demo data nobody can fix at 2am.
                while (DB::table('floors')->where('asset_id', $row->asset_id)->where('level', $level)->exists()) {
                    $level++;
                }

                $floorId = DB::table('floors')->insertGetId([
                    'asset_id' => $row->asset_id,
                    'code' => $code,
                    'name' => $row->floor,
                    'level' => $level,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('units')
                ->where('asset_id', $row->asset_id)
                ->where('floor', $row->floor)
                ->update(['floor_id' => $floorId]);
        }

        // ── The columns this replaces come out NOW, not "in one release" ──────────────────────
        // Every reader moved to the relation in this same commit — the two tables, the invoice PDF,
        // the occupancy map's ordering and the search blob. A column nothing reads is not harmless:
        // the next person cannot tell which of the two is authoritative, and a report written
        // against the dead one looks correct and is wrong. Keeping one is only justified when a
        // consumer OUTSIDE this repo reads it, and none does.
        //
        // `floor_level` in particular lasted about four hours: it shipped earlier the same day as
        // the ordinal-on-every-unit answer, and `floors.level` is the same fact stored once. Its
        // migration is left in history rather than deleted — it was already pushed, so an install
        // that ran it has the column and the `migrations` row, and removing the file would strand
        // both. Hence the `hasColumn` guard: create-then-drop is ugly in a fresh install's history
        // and correct in every other one.
        // The INDEX comes off before the column. MySQL drops an index over a dropped column for
        // you; SQLite rebuilds the table and fails with "error in index … after drop column",
        // taking the whole test suite with it. Third cross-database difference this cycle, and the
        // same lesson each time: the two databases disagree about what is implied.
        if (Schema::hasColumn('units', 'floor_level')) {
            Schema::table('units', function (Blueprint $table) {
                $table->dropIndex(['asset_id', 'floor_level']);
            });
        }

        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('floor');

            if (Schema::hasColumn('units', 'floor_level')) {
                $table->dropColumn('floor_level');
            }
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->string('floor')->nullable()->after('code');
            $table->smallInteger('floor_level')->nullable()->after('floor');
        });

        // Put the labels back from the register, so a rollback lands on readable data rather than
        // an empty column.
        foreach (DB::table('floors')->get() as $floor) {
            DB::table('units')->where('floor_id', $floor->id)
                ->update(['floor' => $floor->code, 'floor_level' => $floor->level]);
        }

        Schema::table('rentable_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('floor_id');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('floor_id');
        });

        Schema::dropIfExists('floors');
    }
};
