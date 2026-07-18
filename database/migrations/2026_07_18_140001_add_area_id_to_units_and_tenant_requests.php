<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Area routing (module 30 → 11) — wire the facility zone into the two records
 * that carry it: a Unit belongs to a zone, and a TenantRequest inherits its
 * unit's zone on intake so it can be routed to the zone's supervisors.
 *
 * Both columns are nullable FKs with `nullOnDelete` — retiring a zone (soft or
 * hard delete) must never strand a historical request or a unit; the link just
 * goes null. A unit may have no zone yet, and a caller-only / unit-less request
 * has none by definition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->foreignId('area_id')
                ->nullable()
                ->after('asset_id')
                ->constrained('areas')
                ->nullOnDelete();
            $table->index('area_id');
        });

        Schema::table('tenant_requests', function (Blueprint $table) {
            $table->foreignId('area_id')
                ->nullable()
                ->after('unit_id')
                ->constrained('areas')
                ->nullOnDelete();
            $table->index('area_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_requests', function (Blueprint $table) {
            $table->dropForeign(['area_id']);
            $table->dropIndex(['area_id']);
            $table->dropColumn('area_id');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->dropForeign(['area_id']);
            $table->dropIndex(['area_id']);
            $table->dropColumn('area_id');
        });
    }
};
