<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CAM caps (recovery-clause engine, slice 2) — the controllable-expense ceiling anchor tenants
 * negotiate so their CAM share can't rise faster than a stated limit. A cap hits ONLY the
 * true-up (and the admin-fee base): `capped_cost = min(allocated, ceiling)`, the landlord
 * ABSORBS `cap_absorbed = allocated − capped_cost`, and `allocated_amount` stays UNCAPPED so the
 * books-check's Σ allocated = total_actual_expense tie-out is untouched.
 *
 * NO-OP for every existing lease: absence of a lease_cam_terms row ⇒ no ceiling ⇒ capped_cost =
 * allocated ⇒ true-up + admin fee byte-identical to slice 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Effective-dated per-lease cap terms. The applicable row for a reconciled year is the
        // one with the greatest effective_year ≤ that year.
        Schema::create('lease_cam_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('effective_year'); // cap applies from this reconciliation year onward
            $table->string('cap_type', 16); // absolute | yoy | both
            $table->decimal('cap_absolute_amount', 14, 2)->nullable(); // EGP ceiling on the lease's cost share
            $table->unsignedSmallInteger('base_year')->nullable();      // YoY anchor year
            $table->decimal('base_year_amount', 14, 2)->nullable();     // the lease's CAM cost in base_year
            $table->decimal('yoy_pct', 6, 4)->nullable();               // fraction, 0.0500 = 5%
            $table->boolean('compounding')->default(true);              // compound vs. simple YoY growth
            $table->string('notes', 1000)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['lease_id', 'effective_year']); // one cap config per lease per effective year
        });

        Schema::table('cam_allocations', function (Blueprint $table) {
            // cap_amount already exists (the resolved ceiling that applied — was unread until now).
            $table->decimal('capped_cost_amount', 14, 2)->nullable()->after('cap_amount'); // min(allocated, ceiling)
            $table->decimal('cap_absorbed_amount', 14, 2)->default(0)->after('capped_cost_amount'); // allocated − capped
        });
    }

    public function down(): void
    {
        Schema::table('cam_allocations', function (Blueprint $table) {
            $table->dropColumn(['capped_cost_amount', 'cap_absorbed_amount']);
        });
        Schema::dropIfExists('lease_cam_terms');
    }
};
