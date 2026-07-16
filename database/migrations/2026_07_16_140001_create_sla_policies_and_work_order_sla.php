<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-property SLA for corrective maintenance (FR-CM-05/06/07) — module 26.
 *
 * FR-CM-05 wants SLA durations "set once per location/mall". Today they are a single
 * portfolio-wide spatie-settings singleton (`MaintenanceSettings`) with no `asset_id`
 * dimension at all, so this is a schema change rather than a settings tweak: a mall with a
 * 24/7 engineering team and a small strip centre cannot share one number.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One row per property × priority. Absent = fall back to the global default, so an
        // operator only records the malls that actually differ.
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->enum('priority', ['low', 'medium', 'high', 'urgent']);
            $table->unsignedInteger('resolve_hours');
            $table->timestamps();

            $table->unique(['asset_id', 'priority'], 'sla_policy_asset_priority_unique');
        });

        Schema::table('maintenance_work_orders', function (Blueprint $table) {
            // FR-CM-06 asks for at least Normal + Urgent. We keep the 4-tier superset the
            // rest of the system already speaks (module 11's priorities), so the two halves
            // of maintenance don't disagree about what "urgent" means. Normal ≈ medium.
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium')->after('status');

            // FR-CM-07 — the clock starts on ACCEPTANCE, not on creation, so both stamps
            // are written when the job is accepted rather than at insert.
            $table->dateTime('acknowledged_at')->nullable()->after('scheduled_for');
            $table->dateTime('target_resolution_at')->nullable()->after('acknowledged_at');

            // Idempotency stamp for the breach scan, mirroring tenant_requests'.
            $table->dateTime('sla_breach_notified_at')->nullable()->after('target_resolution_at');

            // The scan's hot query: open CMs past their target that haven't been alerted.
            $table->index(['status', 'target_resolution_at'], 'mwo_sla_scan_index');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_work_orders', function (Blueprint $table) {
            $table->dropIndex('mwo_sla_scan_index');
            $table->dropColumn(['priority', 'acknowledged_at', 'target_resolution_at', 'sla_breach_notified_at']);
        });

        Schema::dropIfExists('sla_policies');
    }
};
