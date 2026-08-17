<?php

use App\Models\MeterReading;
use App\Models\UtilityMeter;
use App\Models\UtilityTariff;
use App\Models\UtilityTariffRate;
use Illuminate\Database\QueryException;

/**
 * A utility price has a date, and a reading is priced at the price in force when it was consumed.
 *
 * **What this replaces.** `utility_meters.rate_per_unit` was one number per meter with no date
 * attached. Egyptian utility tariffs move by decree, announced ahead of the day they take effect —
 * so there was nowhere to put a rise until the morning it started, the operator had to edit every
 * affected meter on that morning, and a reading keyed either side of a half-finished sweep priced
 * two tenants differently for the same supply on the same day.
 *
 * The shape is the tax catalogue's, deliberately: a stable identity with a ladder of dated rungs,
 * no `effective_to` (so overlaps and gaps are unrepresentable), and resolution for the DOCUMENT's
 * date rather than for today.
 *
 * **History was already safe and stays that way.** `meter_readings.cost` is computed and stored when
 * the reading is entered, so no rung ever re-prices a recharge that has been raised. What these
 * tests pin is *origination* — which number a NEW reading is priced at.
 */
beforeEach(function () {
    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset);

    $this->tariff = UtilityTariff::factory()->create([
        'utility_type' => 'electric',
        'unit_of_measurement' => 'kWh',
    ]);

    // A rise decreed in advance: 1.45 until 31 Aug, 1.80 from 1 Sept.
    UtilityTariffRate::factory()->create([
        'utility_tariff_id' => $this->tariff->id,
        'rate_per_unit' => 1.45,
        'effective_from' => '2026-01-01',
    ]);
    UtilityTariffRate::factory()->create([
        'utility_tariff_id' => $this->tariff->id,
        'rate_per_unit' => 1.80,
        'effective_from' => '2026-09-01',
    ]);

    $this->meter = UtilityMeter::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $this->unit->id,
        'meter_number' => 'E-1001',
        'type' => 'electric',
        'status' => 'active',
        'unit_of_measurement' => 'kWh',
        'utility_tariff_id' => $this->tariff->id,
        'rate_per_unit' => null,
    ]);
});

it('resolves the rung in force on the date, not the newest one', function () {
    expect($this->meter->resolvedRatePerUnit('2026-08-31'))->toBe(1.45)
        ->and($this->meter->resolvedRatePerUnit('2026-09-01'))->toBe(1.80)
        ->and($this->meter->resolvedRatePerUnit('2026-12-31'))->toBe(1.80);
});

it('lets a decreed rise be entered in advance without changing what bills today', function () {
    // The capability the whole table exists for. On 15 August the September rung is already on
    // file, and August readings are still priced at 1.45 — which is what makes entering it early
    // safe, and what the old single column could not express at all.
    expect($this->meter->resolvedRatePerUnit('2026-08-15'))->toBe(1.45);
});

it('prices a back-filled reading at what the supply cost when it was consumed', function () {
    // A reading keyed in September for August. Priced at August's rate, not September's — the
    // difference between a correct recharge and a tenant being overcharged for the delay of
    // whoever walked the meters.
    expect($this->meter->costFor(1000, '2026-08-20'))->toBe(1450.0)
        ->and($this->meter->costFor(1000, '2026-09-20'))->toBe(1800.0);
});

it('answers 0 before the first rung rather than falling back to the newest', function () {
    // A date before the ladder starts has no price, and inventing one would be worse than none:
    // `BillMeterReadingService` refuses a zero-cost recharge, so the reading is on file and
    // visibly unbillable rather than billed at a rate nobody set for that period.
    expect($this->tariff->rateOn('2025-06-01'))->toBeNull()
        ->and($this->meter->resolvedRatePerUnit('2025-06-01'))->toBe(0.0);
});

it('lets the meter override the tariff, and says so', function () {
    // The real case the catalogue cannot describe: a rate negotiated with one tenant. Same shape
    // as `charges.vat_rate` against the tax catalogue — override wins, null is the normal state.
    $this->meter->update(['rate_per_unit' => 0.90]);

    expect($this->meter->fresh()->resolvedRatePerUnit('2026-09-20'))->toBe(0.90)
        ->and($this->meter->fresh()->hasRateOverride())->toBeTrue();
});

it('treats a meter with neither tariff nor override as monitored-but-not-recharged', function () {
    // A landlord / common-area meter's normal state, and the reason 0 is the floor rather than an
    // error: the reading is worth recording even when nobody is billed for it.
    $common = UtilityMeter::create([
        'asset_id' => $this->asset->id,
        'unit_id' => null,
        'meter_number' => 'E-COMMON',
        'type' => 'electric',
        'status' => 'active',
        'utility_tariff_id' => null,
        'rate_per_unit' => null,
    ]);

    expect($common->resolvedRatePerUnit())->toBe(0.0)
        ->and($common->hasRateOverride())->toBeFalse();
});

it('does not re-price a reading that has already been costed', function () {
    // The invariant that makes the ladder editable at all. `meter_readings.cost` is stored, so a
    // rung added, edited or removed afterwards changes what is priced NEXT and nothing that has
    // been priced — the same origination-only rule VAT runs on.
    $reading = MeterReading::create([
        'utility_meter_id' => $this->meter->id,
        'reading_date' => '2026-08-20',
        'reading_value' => 5000,
        'consumption' => 1000,
        'cost' => $this->meter->costFor(1000, '2026-08-20'),
    ]);

    expect((float) $reading->cost)->toBe(1450.0);

    // Somebody corrects August's rate upward after the fact.
    UtilityTariffRate::where('utility_tariff_id', $this->tariff->id)
        ->whereDate('effective_from', '2026-01-01')
        ->update(['rate_per_unit' => 9.99]);

    expect((float) $reading->fresh()->cost)->toBe(1450.0);
});

it('keeps one price per tariff per day', function () {
    // The unique index is what makes "the latest rung on or before this date" a single
    // deterministic answer rather than whichever row the query happened to order first.
    expect(fn () => UtilityTariffRate::factory()->create([
        'utility_tariff_id' => $this->tariff->id,
        'effective_from' => '2026-09-01',
        'rate_per_unit' => 2.10,
    ]))->toThrow(QueryException::class);
});

it('refuses a utility_type outside the value set', function () {
    // No DB-level enums anywhere in this system; the wildcard `eloquent.saving` listener is what
    // holds the set, and it must hold for a table added after that rule was written.
    expect(fn () => UtilityTariff::factory()->create(['utility_type' => 'steam']))
        ->toThrow(DomainException::class);
});
