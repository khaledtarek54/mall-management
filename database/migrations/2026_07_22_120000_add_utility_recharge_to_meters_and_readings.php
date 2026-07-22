<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Utility recharge (module 10 completion). Readings were recorded but could never be BILLED: there was
 * no tariff on the meter (so `cost` was hand-typed into a NOT-NULL column) and nothing turned a reading
 * into an invoice line — even though `InvoiceItemType::Utility` already maps to `utility_revenue`
 * (41104001). This adds:
 *   - `utility_meters.rate_per_unit` — the tariff (EGP per kWh / m³) so cost derives instead of being
 *     typed. Nullable: a meter that is monitored but not recharged simply has no rate.
 *   - `meter_readings.billed_invoice_id` / `billed_at` — the idempotency + traceability anchor, so a
 *     reading can be billed exactly once and the invoice it produced is findable from the reading.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utility_meters', function (Blueprint $table) {
            $table->decimal('rate_per_unit', 12, 4)->nullable()->after('unit_of_measurement');
        });

        Schema::table('meter_readings', function (Blueprint $table) {
            $table->foreignId('billed_invoice_id')->nullable()->after('cost')
                ->constrained('invoices')->nullOnDelete();
            $table->timestamp('billed_at')->nullable()->after('billed_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('meter_readings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billed_invoice_id');
            $table->dropColumn('billed_at');
        });

        Schema::table('utility_meters', function (Blueprint $table) {
            $table->dropColumn('rate_per_unit');
        });
    }
};
