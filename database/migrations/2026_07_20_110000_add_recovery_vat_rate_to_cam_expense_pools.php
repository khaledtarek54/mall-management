<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-pool VAT rate on the CAM cost recovery (the year-end true-up + the over-collection credit).
 *
 * The monthly CAM estimate bills as a `service_charge` at 14% VAT (a taxable service supply); the
 * year-end true-up is additional consideration for that same supply, so it should carry the same
 * output VAT — billing the recovery at 0% systematically under-remitted output VAT and was internally
 * inconsistent with the estimate. Default 14% (matches the monthly estimate); an operator can set 0%
 * per pool for a genuinely non-taxable pass-through. Frozen alongside the rest of the recovery basis
 * once any allocation is billed (see CamExpensePool::booted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cam_expense_pools', function (Blueprint $table) {
            $table->decimal('recovery_vat_rate', 5, 2)->default(14.00)->after('admin_fee_on_net');
        });
    }

    public function down(): void
    {
        Schema::table('cam_expense_pools', function (Blueprint $table) {
            $table->dropColumn('recovery_vat_rate');
        });
    }
};
