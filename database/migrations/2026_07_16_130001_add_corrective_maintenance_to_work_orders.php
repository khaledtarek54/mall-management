<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrective maintenance (FR-CM-01/02/03/04/14/15) — module 26.
 *
 * CM lands on `maintenance_work_orders` rather than module 11's `tenant_requests` because a
 * CM raised from a failed check on a common-area chiller has **no tenant and no unit**, and
 * both of those are NOT NULL on tenant_requests. Module 11 stays tenant-facing.
 *
 * A CM is a work order with a discriminator, not a new entity: it then inherits the state
 * machine, the checklist, the pass/fail gate and the equipment link already built here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_work_orders', function (Blueprint $table) {
            // ppm = planned/preventive (every existing row); cm = corrective.
            $table->enum('work_order_type', ['ppm', 'cm'])->default('ppm')->after('maintenance_plan_id');

            // FR-CM-02 — in-house vs a third-party company. Null on PPM: the distinction is
            // a CM concept, and a PPM order legitimately carries both a department and a
            // vendor at once.
            $table->enum('execution_type', ['internal', 'external'])->nullable()->after('work_order_type');

            // FR-CM-03 — the individual technician. Paired with the existing vendor_id as an
            // XOR (enforced in the model): internal => a technician, external => a vendor.
            $table->foreignId('assigned_to_user_id')->nullable()->after('vendor_id')
                ->constrained('users')->nullOnDelete();

            // FR-CM-04 — what is wrong. Distinct from `notes`, which on a PPM order holds
            // the plan's description. Required for CM at the service + form layer (it cannot
            // be NOT NULL here: every existing PPM row has none).
            $table->text('description')->nullable()->after('notes');

            // FR-CM-01 — the failed check this CM came from. nullOnDelete: the CM outlives
            // its trigger, and an ad-hoc CM has no source item at all.
            $table->foreignId('source_item_id')->nullable()->after('description')
                ->constrained('maintenance_work_order_items')->nullOnDelete();

            // FR-CM-14/15 — a follow-up when the original fix was incomplete. The client
            // explicitly wants a NEW linked order rather than reopening the closed one, so
            // the original's SLA + closure record stay intact for audit. nullOnDelete keeps
            // the survivor visible if the original is ever purged.
            $table->foreignId('parent_work_order_id')->nullable()->after('source_item_id')
                ->constrained('maintenance_work_orders')->nullOnDelete();

            $table->index(['work_order_type', 'status'], 'mwo_type_status_index');
            $table->index('parent_work_order_id', 'mwo_parent_index');
            $table->index('assigned_to_user_id', 'mwo_assignee_index');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_work_orders', function (Blueprint $table) {
            $table->dropIndex('mwo_assignee_index');
            $table->dropIndex('mwo_parent_index');
            $table->dropIndex('mwo_type_status_index');
            $table->dropConstrainedForeignId('parent_work_order_id');
            $table->dropConstrainedForeignId('source_item_id');
            $table->dropConstrainedForeignId('assigned_to_user_id');
            $table->dropColumn(['description', 'execution_type', 'work_order_type']);
        });
    }
};
