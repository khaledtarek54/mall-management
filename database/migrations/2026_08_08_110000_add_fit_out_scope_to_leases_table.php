<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `leases.fit_out_scope` — what a rent-free fit-out period actually abates.
 *
 * Until now `fit_out_months` suppressed the **entire invoice**: rent, service charge, CAM and the
 * marketing levy together (operator decision 2026-07-19). The industry standard is **net
 * abatement** — base rent free, the tenant still pays the operating-cost reimbursements, because
 * the landlord is still cleaning, securing and cooling the unit while it is fitted out. Lease
 * drafting guidance is explicit that if an abatement clause does not name CAM, that obligation
 * continues. On a 36k/month service charge over three fit-out months that is ~108k per new tenant
 * the operator is likely entitled to bill and does not.
 * See docs/benchmarks/yardi/07-phase-plan.md §1 Q2.
 *
 * **The column default is `gross` and the MODEL default is `rent_only`.** That split is the whole
 * migration strategy: every lease that already exists keeps the grace it was actually billed under
 * — retroactively re-billing a live tenancy is not a migration — while every lease created from
 * here defaults to the standard. An operator can still choose gross per deal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            // String, not enum: adding a scope later must not need a migration (project convention).
            $table->string('fit_out_scope', 16)->default('gross')->after('fit_out_months');
        });

        \Illuminate\Support\Facades\DB::table('leases')->update(['fit_out_scope' => 'gross']);
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('fit_out_scope');
        });
    }
};
