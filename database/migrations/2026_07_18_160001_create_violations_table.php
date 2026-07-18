<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant violations (module 31) — FR-REQ-15/16/17.
 *
 * The operator records a violation committed by a tenant at one of the malls
 * (e.g. blocked fire exit, unauthorised signage, after-hours noise), together
 * with an optional cost/fine. A violation stands in exactly one property
 * (direct `asset_id`, like Unit / Area / Equipment) and references the SHARED
 * Tenant it is raised against (like Invoice / Lease).
 *
 * SCOPE — this is a REGISTER, not a billing document. `fine_amount` records the
 * money the operator assessed; it deliberately does NOT create an Invoice /
 * Charge / GL entry (auto-billing the fine is a separate, later slice, exactly
 * like the deferred tenant-repair recharge). `notified_at` stamps when a notice
 * was sent to the tenant (FR-REQ-17) — an explicit operator action, never on
 * create.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('violations', function (Blueprint $table) {
            $table->id();
            // The mall where the violation occurred — the property-isolation anchor.
            // `constrained()` creates the index MySQL needs on the FK, so asset_id
            // and tenant_id are both indexed for the scoped-list queries.
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            // The tenant the violation is against (shared master; scoped-select at the form).
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->text('description');
            // The associated cost/fine (FR-REQ-15). Nullable — a violation may carry
            // no monetary penalty. Recorded only; never billed here.
            $table->decimal('fine_amount', 12, 2)->nullable();
            $table->date('violation_date');
            // Minimal lifecycle: open → resolved (no invented workflow).
            $table->string('status')->default('open');
            // When the tenant notice was sent (FR-REQ-17). Null until the operator sends it.
            $table->timestamp('notified_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Explicit indexes for the two scoping keys (belt-and-braces alongside the
            // FK indexes; the register is queried by property and by tenant).
            $table->index('asset_id', 'violations_asset_id_index');
            $table->index('tenant_id', 'violations_tenant_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('violations');
    }
};
