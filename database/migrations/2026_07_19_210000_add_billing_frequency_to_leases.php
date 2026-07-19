<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lease-level billing frequency. Anchor / kiosk / storage leases are often contracted to pay
 * rent-in-advance quarterly, semi-annually, or annually rather than monthly (operator decision
 * 2026-07-19). The whole recurring stack (rent + service + marketing levy) bills together, once
 * per cycle, on one invoice covering the whole cycle; cycles are anchored to the lease's first
 * billable month (commencement + fit-out). Default 'monthly' preserves today's behaviour exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->enum('billing_frequency', ['monthly', 'quarterly', 'semiannual', 'annual'])
                ->default('monthly')
                ->after('fit_out_months');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('billing_frequency');
        });
    }
};
