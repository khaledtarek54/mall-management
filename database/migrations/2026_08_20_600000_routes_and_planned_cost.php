<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Routes and planned cost on a service plan — close-out step 7, the last in the order.
 *
 * ## Routes (Maximo §6)
 *
 * A route is an ordered list of machines covered by ONE visit — *"inspect all 42 fire extinguishers
 * on level 2"*. Scenario S5 is the gap: a `ServicePlan` targets **one** machine, unit or area with a
 * free-text `checklist`, so an operator either creates 42 plans or one plan whose checklist has 42
 * lines and **whose failures cannot be attributed to individual devices** — a checklist line reading
 * "Extinguisher 2-17 — fail" is a string, so no report can say which devices are overdue and the
 * asset history of 2-17 stays empty.
 *
 * `service_plan_stops` is the route; `facility_work_order_items.equipment_id` is what turns a failed
 * line into a fact about a device.
 *
 * **One work order with one item per stop — not a child work order per stop.** Maximo offers both.
 * Per-stop children earn their keep when each stop needs separate assignment or separate costing;
 * a fire-extinguisher round needs neither, and 42 work orders for one walk is the failure the route
 * exists to prevent. Stated rather than implied: revisit if a route ever spans trades or contractors.
 *
 * ## Planned cost (Maximo §3)
 *
 * A job plan carries labour, material and service estimates; applying it copies them onto the work
 * order, which is what gives the cost object a planned side to compare actuals against. Without
 * them every generated job is un-estimated for ever and `costVariance()` is null on the whole
 * preventive programme.
 *
 * **Hours on the plan, cost at generation.** The plan stores `est_labour_hours` and the generator
 * turns it into money at the trade's rate ON THE DAY THE JOB IS RAISED — the same origination rule
 * as every other rate here. Storing a labour COST on the plan would freeze a rate for the life of
 * the plan, which is exactly what `charges.vat_rate` did wrong before 2026-08-12.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_plan_stops')) {
            Schema::create('service_plan_stops', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_plan_id')->constrained()->cascadeOnDelete();

                // A stop IS a machine. A route over units or areas would be a different thing —
                // a cleaning round already has `area_id` on the plan itself — and inventing three
                // nullable targets here would repeat the ambiguity the plan already carries.
                $table->foreignId('equipment_id')->constrained()->cascadeOnDelete();

                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->string('note', 160)->nullable();
                $table->timestamps();

                // A machine appears on a route once. Twice is a keying error that would produce two
                // identical lines nobody can tell apart on the sheet.
                $table->unique(['service_plan_id', 'equipment_id'], 'plan_stops_unique');
            });
        }

        if (! Schema::hasColumn('facility_work_order_items', 'equipment_id')) {
            Schema::table('facility_work_order_items', function (Blueprint $table) {
                // Which machine this line is about. Null for an ordinary checklist line ("check the
                // filter"), set for a route stop — and it is what makes "extinguisher 2-17 failed"
                // a fact rather than a sentence.
                $table->foreignId('equipment_id')->nullable()->after('facility_work_order_id')
                    ->constrained()->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('service_plans', 'est_labour_hours')) {
            Schema::table('service_plans', function (Blueprint $table) {
                $table->decimal('est_labour_hours', 8, 2)->nullable()->after('checklist');
                $table->decimal('est_material_cost', 14, 2)->nullable()->after('est_labour_hours');
                $table->decimal('est_service_cost', 14, 2)->nullable()->after('est_material_cost');
            });
        }
    }

    public function down(): void
    {
        Schema::table('service_plans', function (Blueprint $table) {
            $table->dropColumn(['est_labour_hours', 'est_material_cost', 'est_service_cost']);
        });

        Schema::table('facility_work_order_items', function (Blueprint $table) {
            $table->dropForeign(['equipment_id']);
            $table->dropColumn('equipment_id');
        });

        Schema::dropIfExists('service_plan_stops');
    }
};
