<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds indexes on hot-path columns surfaced by service-layer review:
 *
 *  - invoices.period_start    — MonthlyBillingService idempotency lookup
 *  - invoices.balance         — LateFeeService scans for > 0
 *  - payments.payment_date    — reports & filters by date
 *  - charges.frequency        — billing applicability filter
 *
 * These are pure performance — schema is unchanged. Safe to roll back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->index('period_start', 'invoices_period_start_idx');
            $table->index('balance', 'invoices_balance_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index('payment_date', 'payments_payment_date_idx');
        });

        Schema::table('charges', function (Blueprint $table) {
            $table->index('frequency', 'charges_frequency_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_period_start_idx');
            $table->dropIndex('invoices_balance_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_payment_date_idx');
        });

        Schema::table('charges', function (Blueprint $table) {
            $table->dropIndex('charges_frequency_idx');
        });
    }
};
