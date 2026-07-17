<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a corrective work order back to the tenant request that reported the fault.
 *
 * The module 11 → 26 seam, and it did not exist in either direction. A tenant reports a problem
 * (a `TenantRequest`, module 11); staff do the facility work through a `MaintenanceWorkOrder`
 * (module 26). Nothing connected the two — the closest the codebase came was `source_item_id`, a
 * CM raised from a *failed PPM check*, which is a different origin entirely.
 *
 * Direction: `tenant_request_id` on the work order. One request may need several work orders (a
 * flood might be plumbing AND electrical); a work order services at most one request. So the FK
 * lives on the many side.
 *
 * **nullOnDelete, never cascade.** The facility work is a real event with its own cost, GL
 * postings and audit trail; deleting the tenant's ticket must not erase the record that the work
 * happened. The link is provenance, not ownership.
 *
 * This is also what FR-USR-06's evidence clause needs: "an uploaded image **or a linked work
 * order** before a request can be marked complete" — a request evidenced by the work order that
 * fixed it. That gate is a separate change; this is the link it stands on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_work_orders', function (Blueprint $table) {
            $table->foreignId('tenant_request_id')->nullable()->after('parent_work_order_id')
                ->constrained('tenant_requests')->nullOnDelete();

            $table->index('tenant_request_id', 'mwo_tenant_request_index');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_request_id');
        });
    }
};
