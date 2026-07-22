<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generalise planned work from "maintenance of equipment" to ANY recurring facility service.
 *
 * FM splits work into HARD services (HVAC, electrical, lifts — equipment-centric PPM) and SOFT
 * services (cleaning, landscaping, pest control, waste, security — LOCATION-centric rounds). Both run
 * through the same work-order engine; they differ only in what they target and how often. Plans and
 * work orders could target asset/unit/equipment but NOT an area, so soft services — which the operator
 * schedules in-house — could not be planned at all.
 *
 *  - `area_id` on plans + work orders: the location a round is performed on (food court, parking L2).
 *    Nullable — an equipment PPM has no area, an area round has no equipment.
 *  - `days_of_week` on plans: ISO weekdays [1=Mon … 7=Sun] a round may fall on, so "every Mon/Wed/Fri"
 *    is expressible. Null/empty = any day (today's behaviour, unchanged).
 *
 * Note on sub-daily rounds ("clean twice daily"): deliberately NOT one work order per round — that is
 * 700+ orders a year per area of pure noise. The FM convention is ONE daily work order whose CHECKLIST
 * carries the rounds ("morning round", "evening round"), which `checklist` already supports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_plans', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable()->after('unit_id')
                ->constrained('areas')->nullOnDelete();
            $table->json('days_of_week')->nullable()->after('frequency_value');
            $table->index(['area_id', 'is_active'], 'maintenance_plans_area_active_index');
        });

        Schema::table('maintenance_work_orders', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable()->after('unit_id')
                ->constrained('areas')->nullOnDelete();
            $table->index('area_id', 'maintenance_work_orders_area_index');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_work_orders', function (Blueprint $table) {
            $table->dropIndex('maintenance_work_orders_area_index');
            $table->dropConstrainedForeignId('area_id');
        });

        Schema::table('maintenance_plans', function (Blueprint $table) {
            $table->dropIndex('maintenance_plans_area_active_index');
            $table->dropConstrainedForeignId('area_id');
            $table->dropColumn('days_of_week');
        });
    }
};
