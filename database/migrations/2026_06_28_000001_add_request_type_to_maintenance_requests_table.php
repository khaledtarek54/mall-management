<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generalises maintenance requests into typed "tenant requests" (Plan 1, Phase 1
 * additive step). Adds the request_type discriminator — defaulting to
 * 'maintenance' so every existing row stays a maintenance request — plus the
 * close-out satisfaction (CSAT) capture. No rename yet; behaviour unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->string('request_type')->default('maintenance')->after('lease_id')->index();
            $table->unsignedTinyInteger('csat_rating')->nullable()->after('resolution_notes');
            $table->text('csat_comment')->nullable()->after('csat_rating');
        });

        // Explicit backfill (defensive — the column default already covers it).
        DB::table('maintenance_requests')->whereNull('request_type')->update(['request_type' => 'maintenance']);
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dropIndex(['request_type']);
            $table->dropColumn(['request_type', 'csat_rating', 'csat_comment']);
        });
    }
};
