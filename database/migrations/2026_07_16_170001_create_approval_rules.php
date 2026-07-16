<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The approval engine — "who has to sign this off, given how much it's worth?"
 *
 * FR-CM-11 ("the required approving manager is determined by the price/value of the spare
 * part") and FR-PROC-02 (procurement routes through an approval workflow) both need the
 * same thing, and **nothing in Atriom does amount-based approval at all**: the only
 * precedents are two flat single-boolean `approve()` verbs (VendorBill, Payroll) with no
 * value tiers, no routing, no rules. So this is net-new rather than an extension.
 *
 * Deliberately a **single approver resolved by amount, not a sequential chain.** The FRD
 * only ever says "higher-value parts require higher-level approval" — that is a level
 * lookup, not a multi-step workflow. Its own open-items list even asks whether procurement
 * approval follows the same price-based hierarchy or a separate rule, so inventing a chain
 * would be building on a guess. Bands are data, so adding one is configuration; if a real
 * chain is ever needed, this table is what it would be built from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_rules', function (Blueprint $table) {
            $table->id();

            // What is being approved — 'inventory_draw', later 'procurement', 'permit', …
            // A string, not an enum: a new module adds a row, not a migration.
            $table->string('module', 40);

            // The band, in EGP. min is inclusive, max is EXCLUSIVE, and null max = unbounded,
            // so contiguous bands (0–1000, 1000–10000, 10000–∞) tile the number line with no
            // gap and no overlap at the boundary. A part costing exactly 1000 falls in the
            // second band — one rule, not two.
            $table->decimal('min_amount', 14, 2)->default(0);
            $table->decimal('max_amount', 14, 2)->nullable();

            // The spatie permission the approver must hold. A permission rather than a role,
            // so the tiers compose with the existing RBAC instead of a parallel one.
            $table->string('required_permission', 100);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['module', 'is_active'], 'approval_rules_module_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_rules');
    }
};
