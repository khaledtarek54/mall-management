<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fit-out / rent-free grace period. Egyptian mall leases routinely grant the tenant 1–3 rent-free
 * months at the start to build out the shop. Operator decision 2026-07-19 (OPEN-QUESTIONS C1.5):
 * it is a FULL grace — ALL charges (base rent, service, CAM, marketing levy) are suppressed for the
 * grace window, in whole months from the commencement month. Default 0 preserves today's behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->unsignedTinyInteger('fit_out_months')->default(0)->after('marketing_levy_rate');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('fit_out_months');
        });
    }
};
