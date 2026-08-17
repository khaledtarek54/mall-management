<?php

use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use App\Services\DepreciationService;
use Carbon\CarbonImmutable;

function fixedAsset(array $attrs = []): FixedAsset
{
    return FixedAsset::create(array_merge([
        'asset_id' => makeAsset()->id,
        'name' => 'HVAC Unit',
        'tag' => 'FA-'.uniqid(),
        'acquisition_date' => '2026-01-01',
        'acquisition_cost' => 12000,
        'salvage_value' => 0,
        'useful_life_months' => 12,
        'method' => 'straight_line',
        'funded_from' => 'cash',
    ], $attrs));
}

beforeEach(fn () => $this->svc = app(DepreciationService::class));

it('computes the straight-line monthly charge net of salvage', function () {
    $fa = fixedAsset(['acquisition_cost' => 12000, 'salvage_value' => 1200, 'useful_life_months' => 12]);

    expect($this->svc->depreciableBase($fa))->toBe(10800.0);
    expect($this->svc->monthlyAmount($fa))->toBe(900.0); // (12000 − 1200) / 12
});

it('posts one entry per active asset per month and derives accumulated + NBV', function () {
    $fa = fixedAsset(['acquisition_cost' => 12000, 'useful_life_months' => 12]); // 1000/mo

    expect($this->svc->run(CarbonImmutable::parse('2026-03-01')))->toBe(1);

    expect($this->svc->accumulatedFor($fa))->toBe(1000.0);
    expect($this->svc->netBookValue($fa))->toBe(11000.0);
});

it('is idempotent within a month (re-run adds nothing)', function () {
    $fa = fixedAsset();
    $this->svc->run(CarbonImmutable::parse('2026-03-01'));
    $this->svc->run(CarbonImmutable::parse('2026-03-01'));

    expect(DepreciationEntry::where('fixed_asset_id', $fa->id)->count())->toBe(1);
});

it('does not depreciate before the acquisition month', function () {
    $fa = fixedAsset(['acquisition_date' => '2026-06-01']);

    expect($this->svc->run(CarbonImmutable::parse('2026-03-01')))->toBe(0);
    expect($this->svc->accumulatedFor($fa))->toBe(0.0);
});

it('stops at the depreciable base and never over-depreciates', function () {
    $fa = fixedAsset(['acquisition_cost' => 1200, 'salvage_value' => 0, 'useful_life_months' => 12]); // 100/mo

    foreach (range(0, 12) as $i) { // 13 monthly runs Jan-2026 .. Jan-2027
        $this->svc->run(CarbonImmutable::parse('2026-01-01')->addMonths($i));
    }

    expect(DepreciationEntry::where('fixed_asset_id', $fa->id)->count())->toBe(12); // 13th month: fully depreciated
    expect($this->svc->accumulatedFor($fa))->toBe(1200.0);
    expect($this->svc->netBookValue($fa))->toBe(0.0);
});

it('skips a disposed asset', function () {
    $fa = fixedAsset(['status' => 'disposed']);

    expect($this->svc->run(CarbonImmutable::parse('2026-03-01')))->toBe(0);
});

it('coerces blank money inputs to 0 (NOT-NULL guard)', function () {
    $fa = fixedAsset(['acquisition_cost' => '', 'salvage_value' => '']);

    expect((float) $fa->fresh()->acquisition_cost)->toBe(0.0);
    expect((float) $fa->fresh()->salvage_value)->toBe(0.0);
});

it('runs via the accounting:post-depreciation command', function () {
    $fa = fixedAsset(['acquisition_cost' => 12000, 'useful_life_months' => 12]);

    $this->artisan('accounting:post-depreciation --month=2026-05')->assertExitCode(0);

    expect($this->svc->accumulatedFor($fa->fresh()))->toBe(1000.0);
});

it('rejects an invalid --month without crashing', function () {
    $this->artisan('accounting:post-depreciation --month=nonsense')
        ->expectsOutputToContain('Invalid --month')
        ->assertExitCode(1);
});
