<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-REQ-13 / FR-REQ-14 — Tenant Permits. A permit is simply a tenant request of the new `permit`
 * type (e.g. for fit-out work or a temporary installation) that carries a validity window. These
 * two nullable date columns hold that window; the request date is the existing `submitted_at`, the
 * tenant is `tenant_id`/caller, and the item/work is `description` — so nothing is duplicated.
 *
 * Nullable at the DB layer: only permits carry a window, and even for a permit the ordering
 * invariant (valid_to >= valid_from) lives in TenantRequest::booted so admin + portal + API all
 * inherit it — the columns themselves stay open so non-permit rows and partial data never blow up.
 * There is NO approval step — a permit is a typed request with a validity window, nothing more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_requests', function (Blueprint $table) {
            $table->date('valid_from')->nullable()->after('scheduled_to');
            $table->date('valid_to')->nullable()->after('valid_from');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_requests', function (Blueprint $table) {
            $table->dropColumn(['valid_from', 'valid_to']);
        });
    }
};
