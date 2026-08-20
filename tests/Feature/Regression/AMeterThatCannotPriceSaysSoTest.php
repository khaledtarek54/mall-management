<?php

/*
|--------------------------------------------------------------------------
| The tariff catalogue that nothing ever created (2026-08-20)
|--------------------------------------------------------------------------
| The dated tariff ladder shipped complete and correct — and NOTHING created a tariff.
| `atriom:install` laid down roles, approvals, departments and the accounting reference data and no
| tariffs; `DemoSeeder` created 48 meters with neither a tariff nor a `rate_per_unit` override.
|
| So on a fresh install AND in the demo, a new meter reading priced at **0.00** —
| `UtilityMeter::resolvedRatePerUnit()` falls through both steps — and `BillMeterReadingService`
| then correctly refused to bill a zero-cost recharge. The capability was complete and the data to
| make it work did not exist, which reads to an operator as a feature that does nothing.
|
| Found by counting rows on the seeded portfolio, not by a failing test: every number in the module
| was right, and 48 meters could not price.
|
| The tariffs are seeded WITHOUT rungs on purpose. A published rate is the operator's to confirm
| against their own bill, and inventing one would silently recharge every tenant at a figure nobody
| checked — worse than not billing at all, and the same reasoning `rateOn()` gives for returning
| null rather than 0.
*/

use App\Models\UtilityMeter;
use App\Models\UtilityTariff;
use App\Models\UtilityTariffRate;
use Database\Seeders\UtilityTariffSeeder;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
});

function meterOn($ctx, array $overrides = []): UtilityMeter
{
    return UtilityMeter::create(array_merge([
        'asset_id' => $ctx->asset->id,
        'meter_number' => 'E-'.fake()->unique()->numerify('####'),
        'type' => 'electric',
        'unit_of_measurement' => 'kWh',
        'status' => 'active',
    ], $overrides));
}

it('seeds the tariffs a mall recharges against', function () {
    (new UtilityTariffSeeder)->run();

    // Electricity, water, gas — the three supplies an Egyptian mall meters.
    expect(UtilityTariff::count())->toBe(3)
        ->and(UtilityTariff::pluck('code')->all())->toContain('ELEC-COMM', 'WATER-COMM', 'GAS-COMM');
});

it('seeds them with NO rate, so nobody is billed at a figure nobody confirmed', function () {
    (new UtilityTariffSeeder)->run();

    foreach (UtilityTariff::all() as $tariff) {
        // Null, not zero — "nobody has priced this" must stay distinguishable from "this is free",
        // which is the distinction the whole pricing path is built on.
        expect($tariff->rateOn())->toBeNull();
    }
});

it('is idempotent, and never overwrites a rate somebody entered', function () {
    (new UtilityTariffSeeder)->run();

    $elec = UtilityTariff::where('code', 'ELEC-COMM')->sole();
    UtilityTariffRate::create([
        'utility_tariff_id' => $elec->id, 'effective_from' => '2026-01-01', 'rate_per_unit' => 1.45,
    ]);

    (new UtilityTariffSeeder)->run();

    expect(UtilityTariff::count())->toBe(3)
        ->and($elec->fresh()->rateOn('2026-06-30'))->toBe(1.45);
});

it('prices every meter on a tariff the moment one rung is entered', function () {
    (new UtilityTariffSeeder)->run();
    $elec = UtilityTariff::where('code', 'ELEC-COMM')->sole();

    $a = meterOn($this, ['utility_tariff_id' => $elec->id]);
    $b = meterOn($this, ['utility_tariff_id' => $elec->id]);

    expect($a->resolvedRatePerUnit('2026-06-30'))->toBe(0.0);

    UtilityTariffRate::create([
        'utility_tariff_id' => $elec->id, 'effective_from' => '2026-01-01', 'rate_per_unit' => 1.45,
    ]);

    // One rung, every meter — that is the point of a catalogue over a per-meter number.
    expect($a->fresh()->costFor(1000, '2026-06-30'))->toBe(1450.0)
        ->and($b->fresh()->costFor(1000, '2026-06-30'))->toBe(1450.0);
});

it('says on the meter itself when it cannot price', function () {
    $bare = meterOn($this);

    // The refusal used to arrive at BILLING time, on a reading already taken. This is the same
    // signal one step earlier, where the meter is set up.
    expect($bare->resolvedRatePerUnit())->toBe(0.0)
        ->and($bare->hasRateOverride())->toBeFalse()
        ->and($bare->utilityTariff)->toBeNull();
});

it('lets a per-meter override price a meter with no tariff at all', function () {
    $negotiated = meterOn($this, ['rate_per_unit' => 2.50]);

    // A rate negotiated with one tenant beats a published one, and is a complete answer on its own.
    expect($negotiated->costFor(1000))->toBe(2500.0)
        ->and($negotiated->hasRateOverride())->toBeTrue();
});
