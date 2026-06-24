<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled work window (FR REQ-1): the from→to date/time during which the
 * maintenance work is to be performed. Distinct from target_resolution_at,
 * which is the SLA deadline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dateTime('scheduled_from')->nullable()->after('target_resolution_at');
            $table->dateTime('scheduled_to')->nullable()->after('scheduled_from');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dropColumn(['scheduled_from', 'scheduled_to']);
        });
    }
};
