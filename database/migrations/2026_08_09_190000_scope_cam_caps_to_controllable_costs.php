<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caps match the clause: controllable-only scoping and cumulative headroom (story RC-07).
 *
 * **Two gaps, both of which made the cap the wrong number.**
 *
 * 1. **Scope.** `LeaseCamTerm` capped the tenant's WHOLE share. Most cap clauses cap only the
 *    *controllable* costs — the ones the landlord can actually manage — and expressly carve out
 *    rates, insurance and utilities, because a landlord cannot be asked to absorb a government levy
 *    it does not set. Capping everything is more protective than the contract, so the landlord was
 *    absorbing money it was entitled to recover.
 *
 * 2. **Carry-forward.** A cap is usually cumulative: a year that comes in UNDER the ceiling banks
 *    the difference, and a later spike can draw on it. Without that, a landlord who runs the centre
 *    cheaply for three years gets no credit for it in the fourth.
 *
 * **Controllable is a THIRD axis, not the fixed/variable one from RC-04.** A security contract is
 * fixed AND controllable; utilities are variable and NOT controllable; insurance is fixed and not
 * controllable. Conflating them would cap the wrong half of the pool.
 *
 * `is_controllable` defaults to TRUE and `cap_scope` to `total`, so every existing term keeps
 * capping exactly what it capped before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_cam_terms', function (Blueprint $table) {
            // `total` (legacy, the whole share) or `controllable`.
            $table->string('cap_scope')->default('total')->after('cap_type');
            $table->boolean('cap_carry_forward')->default(false)->after('cap_scope');
        });

        Schema::table('cam_pool_accounts', function (Blueprint $table) {
            // TRUE preserves today's behaviour: with everything controllable, a controllable-scoped
            // cap covers the whole share exactly as an unscoped one does.
            $table->boolean('is_controllable')->default(true)->after('cost_nature');
        });

        Schema::table('cam_expense_pools', function (Blueprint $table) {
            // The controllable share of a pool whose expense was typed rather than sourced from
            // accounts. NULL = 100% controllable, which is the legacy reading.
            $table->decimal('controllable_pct', 5, 2)->nullable()->after('variable_pct');
        });

        Schema::table('cam_allocations', function (Blueprint $table) {
            // What the cap actually did this year, kept so the tenant statement can replay it and
            // so next year's headroom is derived from records rather than recomputed from live
            // terms that may since have changed.
            $table->decimal('cap_headroom_used', 14, 2)->default(0)->after('cap_absorbed_amount');
            $table->decimal('cap_headroom_banked', 14, 2)->default(0)->after('cap_headroom_used');
        });
    }

    public function down(): void
    {
        Schema::table('lease_cam_terms', function (Blueprint $table) {
            $table->dropColumn(['cap_scope', 'cap_carry_forward']);
        });

        Schema::table('cam_pool_accounts', function (Blueprint $table) {
            $table->dropColumn('is_controllable');
        });

        Schema::table('cam_expense_pools', function (Blueprint $table) {
            $table->dropColumn('controllable_pct');
        });

        Schema::table('cam_allocations', function (Blueprint $table) {
            $table->dropColumn(['cap_headroom_used', 'cap_headroom_banked']);
        });
    }
};
