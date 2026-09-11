<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The annual increase is a term of EVERY charge row, not a lease-level toggle for one of them.
 *
 * Client meeting 2026-09-02, point 24 — *"the annual increase should be on all expenses not only
 * on rent — better to be an option to be a percentage or a fixed number"*. Until now the clause was
 * lease-level and stepped the base rent; `escalation_applies_to_service_charge` (2026-09-05) let
 * the service charge follow it by the same percentage; and parking, signage, storage and every
 * other charge row never escalated at all. Yardi holds the escalation schedule PER CHARGE CODE —
 * method (% / amount / CPI), floor and ceiling, frequency — so the client is asking for Yardi's
 * grain, and the 2026-09-05 migration's own docblock already named this as the shape it was a
 * stand-in for.
 *
 * Three columns on `charges`, carried onto every successor rung like `billing_timing` and
 * `prorate`: `escalation_mode` (`follows_lease` · `percent` · `fixed_amount` · `none`, null read
 * as none), and the row's own `escalation_rate` / `escalation_amount` for the two stated modes.
 * The rent's own rows carry nothing — the lease's clause IS the rent's rule — and the marketing
 * levy follows the rent by derivation.
 *
 * **Nothing an install bills moves on deploy.** Every active service-charge row of a lease whose
 * toggle was on becomes `follows_lease` (the same collared percentage on the same anniversary,
 * exactly what the toggle meant); every other row stays null, which is what it did before:
 * nothing. The toggle is then dropped — its meaning lives on the row, and a second home for it
 * would be the two-truths shape the deposit filter drifted into.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->string('escalation_mode', 32)->nullable()->after('prorate');
            $table->decimal('escalation_rate', 5, 2)->nullable()->after('escalation_mode');
            $table->decimal('escalation_amount', 12, 2)->nullable()->after('escalation_rate');
        });

        // The toggle's rows become the row's own term. ACTIVE rows only, of any date: the sweep
        // and the projection read the rung billing into each anniversary, so every rung of the
        // type must say the same thing or the clause stops at whichever rung was left silent.
        $flagged = DB::table('leases')
            ->where('escalation_applies_to_service_charge', true)
            ->pluck('id');

        if ($flagged->isNotEmpty()) {
            DB::table('charges')
                ->whereIn('lease_id', $flagged)
                ->where('type', 'service_charge')
                ->where('is_active', true)
                ->update(['escalation_mode' => 'follows_lease']);
        }

        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('escalation_applies_to_service_charge');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->boolean('escalation_applies_to_service_charge')
                ->default(false)
                ->after('escalation_interval_months');
        });

        $following = DB::table('charges')
            ->where('type', 'service_charge')
            ->where('escalation_mode', 'follows_lease')
            ->whereNotNull('lease_id')
            ->distinct()
            ->pluck('lease_id');

        if ($following->isNotEmpty()) {
            DB::table('leases')->whereIn('id', $following)->update(['escalation_applies_to_service_charge' => true]);
        }

        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn(['escalation_mode', 'escalation_rate', 'escalation_amount']);
        });
    }
};
