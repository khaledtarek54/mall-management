<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            // Stamped by maintenance:scan-sla-breaches after firing the
            // SlaBreachedNotification, so the scheduled scan is idempotent —
            // a breached request is alerted once, not every daily run.
            $table->timestamp('sla_breach_notified_at')->nullable()->after('target_resolution_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dropColumn('sla_breach_notified_at');
        });
    }
};
