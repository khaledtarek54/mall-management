<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-lease marketing-levy options. The levy used to be forced onto every lease at the global 5%.
 * Now a lease can opt OUT (some tenants — anchors, kiosks, storage — don't pay it) and can OVERRIDE
 * the rate (negotiated per deal). Defaults preserve today's behaviour exactly:
 *   - has_marketing_levy = true  → every existing/new lease still gets the levy
 *   - marketing_levy_rate = NULL → falls back to the global MarketingSettings rate (5%)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->boolean('has_marketing_levy')->default(true)->after('service_charge_monthly');
            $table->decimal('marketing_levy_rate', 5, 2)->nullable()->after('has_marketing_levy'); // % override; null = global default
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn(['has_marketing_levy', 'marketing_levy_rate']);
        });
    }
};
