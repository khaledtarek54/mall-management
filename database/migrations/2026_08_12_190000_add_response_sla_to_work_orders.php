<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The second SLA clock — how long a corrective job may sit before anybody takes it on.
 *
 * `target_resolution_at` was written in exactly ONE place: the manual `open → in_progress`
 * transition. And `open → done` is a legal hop. So an external corrective order could be created,
 * worked for three weeks and closed **without ever having a deadline**: `isSlaBreached()` requires
 * a non-null target, so the hourly scan, the penalty gate, the table filter and the dashboard card
 * all skipped it — permanently. Not clicking Start was a silent discretion to waive a vendor
 * penalty, with nothing recording that it happened.
 *
 * FR-CM-07's rule stays: the RESOLUTION clock starts on acceptance, so an engineer is not charged
 * for the time a job spent in a queue. What was missing is the other side of that trade — if queue
 * time is not the engineer's problem, it has to be somebody's. That is the response clock, and it
 * runs from creation.
 *
 * `respond_hours` is nullable on `sla_policies` on purpose: null means this property overrides only
 * the resolution target and takes the operator-wide response target, which is a real thing to want
 * and would otherwise need a sentinel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sla_policies', function (Blueprint $table) {
            $table->unsignedInteger('respond_hours')->nullable()->after('resolve_hours');
        });

        Schema::table('maintenance_work_orders', function (Blueprint $table) {
            $table->timestamp('target_response_at')->nullable()->after('acknowledged_at');
            // Its own stamp, beside `sla_breach_notified_at`. Sharing one would mean whichever
            // clock breached first silenced the other — and a job answered late but fixed on time
            // is a different conversation from one answered on time and fixed late.
            $table->timestamp('response_breach_notified_at')->nullable()->after('sla_breach_notified_at');
            $table->index(['status', 'target_response_at']);
        });
    }

    public function down(): void
    {
        Schema::table('sla_policies', function (Blueprint $table) {
            $table->dropColumn('respond_hours');
        });

        Schema::table('maintenance_work_orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'target_response_at']);
            $table->dropColumn(['target_response_at', 'response_breach_notified_at']);
        });
    }
};
