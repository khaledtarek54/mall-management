<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The utility tariff catalogue — what a unit of electricity, water or gas costs, and the day that
 * price came into force.
 *
 * **What this replaces.** `utility_meters.rate_per_unit` was a single number per meter, and it is
 * the wrong shape for the same reason `TaxSettings::vat_standard_rate` was
 * ({@see 2026_08_12_120000_create_tax_codes.php}, whose reasoning this migration follows
 * deliberately rather than inventing a second one):
 *
 *   1. **A price has a date.** Egyptian utility tariffs move by government decree, announced ahead
 *      of the day they take effect. With one number per meter there is nowhere to put a rise until
 *      the morning it starts — so the operator has to edit every meter on the day, and a reading
 *      keyed the evening before or the morning after costs the wrong amount. A dated rung can be
 *      entered the day it is announced and starts billing itself.
 *   2. **A tariff is not a property of one meter.** Four hundred meters on the same public
 *      electricity tariff held four hundred copies of one number, and a decree meant four hundred
 *      edits with no way to tell which had been done. That is not a data-entry annoyance; a
 *      half-applied tariff bills two tenants differently for the same supply on the same day.
 *
 * **History is already safe and this does not change that.** `meter_readings.cost` is computed and
 * STORED when the reading is entered, so a rate change has never re-priced a past reading. What is
 * being fixed is *origination*: which number a NEW reading is priced at. That is the same
 * origination-only rule VAT runs on, and the reason this table can be edited freely.
 *
 * ## Two tables, because identity and price have different lifetimes
 *
 * `utility_tariffs` is the stable thing a meter points at ("EGPC commercial electricity");
 * `utility_tariff_rates` is what it cost over time. Collapsing them would mean a price change
 * either rewrites history or creates a second tariff that every meter on the first never learns
 * about.
 *
 * ## No `effective_to`, for the reason the tax ladder has none
 *
 * A rung is in force from its `effective_from` until the next rung for the same tariff starts. A
 * from/to pair per row makes two data errors *representable* that this shape cannot express:
 * overlapping windows (two prices on one day, and whichever the query ordered first wins) and gaps
 * (a date with no price, falling through to a fallback nobody chose). This system has already been
 * bitten by exactly that on charge schedules — `atriom:audit-charge-schedules` exists because
 * overlapping rows **bill nothing**. Retiring a tariff is `is_active = false`.
 *
 * ## The meter keeps its own rate, as an OVERRIDE
 *
 * `utility_meters.rate_per_unit` is NOT dropped and NOT backfilled away. It stays as the per-meter
 * override for the real case the catalogue cannot describe — a rate negotiated for one tenant, or a
 * sub-meter billed at a blended figure — and **when it is set, it wins**. Null becomes the normal
 * state, meaning "follow the tariff". That is exactly the shape `charges.vat_rate` already has
 * against the tax catalogue, and `UtilityMeter::resolvedRatePerUnit($on)` is the one place that
 * answers what a meter charges on a date, mirroring `Charge::resolvedVatRate($on)`.
 *
 * Because the override wins, **this migration changes the price of nothing**: every existing meter
 * has a `rate_per_unit` and keeps billing exactly as it did. Moving a meter onto a tariff is a
 * deliberate act — assign the tariff, clear the override — which is the safe direction for a change
 * that decides what a tenant is charged.
 *
 * ## Tiered (شرائح) bands are deliberately NOT here
 *
 * Egyptian domestic electricity is banded, and a banded tariff would need `tier_from`/`tier_to` on
 * the rung plus a consumption-splitting rule in the resolver. That is a second slice, and it is not
 * built on speculation: whether Eltizam recharges tenants in bands or at a single commercial rate is
 * a question for the operator, and shipping unused nullable tier columns would be a schema that
 * documents a decision nobody made. The rung table is additive, so bands cost one migration when the
 * answer arrives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utility_tariffs', function (Blueprint $table) {
            $table->id();

            // The stable identity an operator recognises on a bill — "EGPC-COMM", "WATER-STD".
            $table->string('code', 32)->unique();

            $table->string('name_en');
            $table->string('name_ar');

            // electric | water | gas — the SAME set as `utility_meters.type`, registered once in
            // App\Support\ValueSets. A tariff is only offered for meters of its own utility, which
            // is what stops a water meter being priced at the electricity rate.
            $table->string('utility_type', 16);

            // kWh / m³. Stated on the tariff as well as the meter because a rate is meaningless
            // without the unit it prices, and a mismatch between the two is a real misconfiguration
            // worth being able to see side by side.
            $table->string('unit_of_measurement', 16)->nullable();

            // Who sets this price — "North Cairo Electricity Distribution Company". The audit
            // question about a utility rate, like the one about a tax rate, is rarely "what" but "on
            // whose authority".
            $table->string('provider')->nullable();

            // Ships active. Unlike a tax code, a tariff with an empty ladder bills nothing dangerous
            // — the resolver falls through to 0 and `BillMeterReadingService` already refuses a
            // zero-cost recharge — so there is no half-commissioned state to guard against.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['utility_type', 'is_active']);
        });

        Schema::create('utility_tariff_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utility_tariff_id')->constrained()->cascadeOnDelete();

            // 12,4 — the same precision the meter column already uses. Utility rates run to four
            // decimals (EGP 1.4500/kWh) and a column that cannot hold the published figure is worse
            // than no configuration at all.
            $table->decimal('rate_per_unit', 12, 4);

            $table->date('effective_from');

            // The decree or circular this came from. Same purpose as `tax_rates.note`.
            $table->string('note')->nullable();

            $table->timestamps();

            // One price per tariff per day — what makes "the latest rung on or before this date" a
            // single deterministic answer rather than an ordering accident.
            $table->unique(['utility_tariff_id', 'effective_from']);
        });

        Schema::table('utility_meters', function (Blueprint $table) {
            // Nullable, and null is a real state: a meter that is monitored but never recharged
            // (a landlord / common-area meter) has no tariff and no rate, and must keep costing 0.
            $table->foreignId('utility_tariff_id')
                ->nullable()
                ->after('type')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('utility_meters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('utility_tariff_id');
        });

        Schema::dropIfExists('utility_tariff_rates');
        Schema::dropIfExists('utility_tariffs');
    }
};
