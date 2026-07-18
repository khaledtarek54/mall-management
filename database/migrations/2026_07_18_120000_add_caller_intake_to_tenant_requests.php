<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-REQ intake (Phase 9a) — a staff channel (phone / walk-in / email) may log a request from a
 * caller who is NOT a registered tenant. So `tenant_id` becomes optional, and `caller_*` fields
 * capture who reported it instead. The invariant "tenant_id null only for a staff channel, and
 * only with a caller_name" is enforced in the model (TenantRequest::booted), so admin + portal +
 * API all inherit it. `unit_id` stays required in this slice — a request is still about a unit;
 * unit-less common-area work is a work order (module 26), which already carries its own asset_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_requests', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->change();

            $table->string('caller_name')->nullable()->after('tenant_id');
            $table->string('caller_phone')->nullable()->after('caller_name');
            $table->text('caller_notes')->nullable()->after('caller_phone');
            $table->index('caller_phone');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_requests', function (Blueprint $table) {
            $table->dropIndex(['caller_phone']);
            $table->dropColumn(['caller_name', 'caller_phone', 'caller_notes']);
            // tenant_id is left nullable: rows created during this window may legitimately hold null,
            // so reverting the column to NOT NULL could fail. The FK is unchanged.
        });
    }
};
