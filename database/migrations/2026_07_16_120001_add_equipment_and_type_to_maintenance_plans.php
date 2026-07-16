<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anchors preventive maintenance to the machine (module 26), now that the equipment
 * register exists — FR-PPM-01 (Routine vs Fixed) and the `equipment_id` link that makes
 * "PPM per asset code" expressible.
 *
 * Before this, a plan could only say "HVAC, somewhere in this mall". The FRD's whole model
 * is per-machine: you service chiller CH-01, and the parts you consume come off CH-01's
 * compatible list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_plans', function (Blueprint $table) {
            // Nullable: existing plans are property/unit-scoped and stay valid. A `fixed`
            // plan requires one (enforced in the model — see MaintenancePlan::booted).
            $table->foreignId('equipment_id')->nullable()->after('unit_id')
                ->constrained('equipment')->nullOnDelete();

            // FR-PPM-01. `routine` = recurring on a schedule (every existing plan);
            // `fixed` = tied to a specific machine.
            $table->enum('maintenance_type', ['routine', 'fixed'])->default('routine')->after('category');

            $table->index(['equipment_id', 'is_active'], 'maintenance_plans_equipment_active_index');
        });

        Schema::table('maintenance_work_orders', function (Blueprint $table) {
            // Copied from the plan when the order is raised; settable directly on an
            // ad-hoc order. Needed here and not only on the plan because an order outlives
            // its plan (nullOnDelete) and because ad-hoc CM has no plan at all.
            $table->foreignId('equipment_id')->nullable()->after('unit_id')
                ->constrained('equipment')->nullOnDelete();

            $table->index('equipment_id', 'maintenance_work_orders_equipment_index');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_work_orders', function (Blueprint $table) {
            $table->dropIndex('maintenance_work_orders_equipment_index');
            $table->dropConstrainedForeignId('equipment_id');
        });

        Schema::table('maintenance_plans', function (Blueprint $table) {
            $table->dropIndex('maintenance_plans_equipment_active_index');
            $table->dropConstrainedForeignId('equipment_id');
            $table->dropColumn('maintenance_type');
        });
    }
};
