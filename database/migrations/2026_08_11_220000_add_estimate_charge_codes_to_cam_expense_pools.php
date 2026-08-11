<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which billed charge codes ARE this pool's estimate.
 *
 * Until now that was one global constant — `CamExpensePool::ESTIMATE_ITEM_TYPES = ['service_charge']`
 * — consulted by every pool on every property. A mall running a `cam` pool AND a `tax` pool for the
 * same year therefore had BOTH subtract the tenant's entire year of billed service charge, so the
 * tax pool reconciled to (allocated 20,000 − estimate 100,000) = **−80,000** and issued a credit
 * note that was auto-applied against live AR.
 *
 * Nullable on purpose: a null means "not declared", and the model falls back to the constant **only
 * for the `cam` pool**, which is what every row written before this migration is. Any other pool
 * that wants the billed basis must say what it bills, and the reconciliation refuses rather than
 * guessing — the same floor-and-refuse shape as `Vat::EXEMPT_TYPES`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cam_expense_pools', function (Blueprint $table) {
            $table->json('estimate_charge_codes')->nullable()->after('estimate_basis');
        });
    }

    public function down(): void
    {
        Schema::table('cam_expense_pools', function (Blueprint $table) {
            $table->dropColumn('estimate_charge_codes');
        });
    }
};
