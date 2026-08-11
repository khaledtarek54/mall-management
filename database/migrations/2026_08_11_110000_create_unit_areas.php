<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A unit's measured area, date-ranged — the narrow remainder of gap-analysis row 47.
 *
 * The row claimed area was "static, used only by CAM". Both halves were wrong: `totalAreaSqmOn()`
 * and `totalAreaSqmForPeriod()` already date-range a LEASE's area through the `lease_unit` pivot,
 * time-weighted, and CAM, the rent roll, reports and rate-priced rent all read it. What was genuinely
 * missing is narrower: a unit's OWN measurement was a single column, so remeasuring a shop rewrote
 * history — last year's CAM reconciliation, recomputed today, would apportion on the new area.
 *
 * **The shape mirrors the charge schedule, deliberately.** Dated rows are the truth for a period;
 * `units.area_sqm` stays as the CURRENT headline, exactly as `leases.base_rent_monthly` sits beside
 * dated `Charge` rows. That is a denormalised cache of the row in force, not a second opinion —
 * `RemeasureUnitService` writes both together, and nothing else may write the column.
 *
 * **The backfill is a no-op by construction.** Every unit gets one open-ended row carrying today's
 * area with `effective_from` null, so `areaOn(any date)` returns exactly what `area_sqm` returned
 * before. No historical figure moves on deploy; only a future remeasurement creates a second row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->decimal('area_sqm', 10, 2);
            // Null = "since always", which is what the backfilled row means. A remeasurement closes
            // the open row the day before the new one starts, the same way a charge row is closed.
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            // Why it changed — a re-survey, a demise, a fit-out that moved a wall. The number alone
            // does not survive an argument two years later.
            $table->string('reason')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['unit_id', 'effective_from']);
        });

        DB::table('units')->orderBy('id')->select('id', 'area_sqm')->each(function ($unit) {
            DB::table('unit_areas')->insert([
                'unit_id' => $unit->id,
                'area_sqm' => $unit->area_sqm ?? 0,
                'effective_from' => null,
                'effective_to' => null,
                'reason' => 'Opening measurement',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_areas');
    }
};
