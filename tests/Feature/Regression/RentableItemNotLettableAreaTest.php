<?php

use App\Models\Area;
use App\Models\CamExpensePool;
use App\Models\Lease;
use App\Models\RentableItem;
use App\Services\CamReconciliationService;
use App\Services\Reports\ReportService;
use Carbon\CarbonImmutable;

/**
 * A rentable item is never lettable area (parking, storage, signage).
 *
 * **This is the reason the register exists at all.** Yardi keeps spaces and rentable items apart
 * (docs/benchmarks/yardi/09-yardi-space-and-parking.md), and the industry definition of GLA excludes
 * parking because it is not leasable floor area. Put a parking bay in `units` and three live numbers
 * go quietly wrong:
 *
 *  - `Asset::totalUnitAreaSqm()` sums every unit unfiltered → the CAM denominator grows → **every
 *    tenant's recovery share falls** and the landlord absorbs the difference.
 *  - `areaOccupancyRate()` divides occupied by that same total → the mall reports as massively
 *    vacant, because a car park is never "occupied".
 *  - the rent roll's EGP/m²/yr — the one number it exists to make comparable — stops meaning
 *    anything.
 *
 * None of those throws. They report the wrong number, which is this codebase's worst failure mode.
 *
 * **The tests below are deliberately "nothing changed" assertions.** That is the whole claim: a
 * property can let fifty parking bays and every area-derived figure must be byte-identical to a
 * property that let none. A separate table with no area column makes that structural — these pin it
 * so a later "just add an area_sqm for reporting" cannot pass unnoticed.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function parkingFor(\App\Models\Asset $asset, string $code, ?Area $zone = null): RentableItem
{
    return RentableItem::create([
        'asset_id' => $asset->id,
        'area_id' => $zone?->id,
        'code' => $code,
        'type' => RentableItem::TYPE_PARKING,
        'monthly_rate' => 900,
    ]);
}

it('leaves gross leasable area untouched', function () {
    $asset = makeAsset();
    makeUnit($asset, ['code' => 'S-01', 'area_sqm' => 100]);
    makeUnit($asset, ['code' => 'S-02', 'area_sqm' => 150]);

    $before = $asset->totalUnitAreaSqm();

    foreach (range(1, 40) as $n) {
        parkingFor($asset, 'P-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT));
    }

    expect($asset->fresh()->totalUnitAreaSqm())->toBe($before)
        ->and($before)->toBe(250.0);
});

it('leaves occupancy untouched', function () {
    // A car park is never "occupied", so counting it would report a full mall as half empty.
    $asset = makeAsset();
    makeUnit($asset, ['code' => 'S-01', 'area_sqm' => 100, 'status' => 'occupied']);
    makeUnit($asset, ['code' => 'S-02', 'area_sqm' => 100, 'status' => 'occupied']);

    $before = $asset->areaOccupancyRate();

    parkingFor($asset, 'P-001');
    parkingFor($asset, 'P-002');

    expect($asset->fresh()->areaOccupancyRate())->toBe($before)
        ->and($before)->toBe(100.0);
});

it('leaves every tenant’s CAM share untouched', function () {
    // THE money case, as a true A/B: two identical properties, one of which also lets forty bays.
    // If parking reached the denominator each tenant's share would fall and the landlord would
    // silently absorb the shortfall — with the pool still tying out, so nothing downstream complains.
    CarbonImmutable::setTestNow('2027-01-15');

    $allocationsFor = function (bool $withParking): array {
        $asset = makeAsset();

        foreach ([['C-01', 100], ['C-02', 300]] as [$code, $area]) {
            makeLease(makeUnit($asset, ['code' => $code, 'area_sqm' => $area]), null, [
                'status' => 'active',
                'commencement_date' => '2026-01-01',
                'expiry_date' => '2029-12-31',
            ]);
        }

        if ($withParking) {
            foreach (range(1, 40) as $n) {
                parkingFor($asset, 'P-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT));
            }
        }

        $pool = CamExpensePool::create([
            'asset_id' => $asset->id,
            'period_year' => 2026,
            'pool_code' => 'cam',
            'total_actual_expense' => 400000,
            'expense_basis' => CamExpensePool::BASIS_STATED,
            'estimate_basis' => CamExpensePool::BASIS_STATED,
            'status' => 'draft',
        ]);

        app(CamReconciliationService::class)->generateAllocations($pool);

        return $pool->allocations()
            ->orderBy('lease_id')
            ->pluck('allocated_amount')
            ->map(fn ($a) => round((float) $a, 2))
            ->all();
    };

    $without = $allocationsFor(false);
    $with = $allocationsFor(true);

    // 100/400 and 300/400 of the pool — the same either way.
    expect($without)->toBe([100000.0, 300000.0])
        ->and($with)->toBe($without);
});

it('leaves the rent roll’s EGP per m² untouched', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    $asset = makeAsset();
    makeLease(makeUnit($asset, ['code' => 'R-01', 'area_sqm' => 120]), null, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2029-12-31',
        'base_rent_monthly' => 36000,
    ]);

    // The rent roll reads the schedule, not `leases.base_rent_monthly` — so the fixture needs the
    // row an operator's lease would actually have.
    app(\App\Services\ChargeScheduleService::class)->setAmount(
        Lease::first(),
        'base_rent',
        36000,
        CarbonImmutable::parse('2026-01-01'),
        ['name' => 'Base Rent', 'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => 0],
    );

    $rate = fn () => app(ReportService::class)
        ->rentRoll(CarbonImmutable::parse('2026-06-15'), $asset->id)
        ->sole()['rent_per_sqm_year'];

    $before = $rate();
    parkingFor($asset, 'P-001');
    parkingFor($asset, 'P-002');

    expect($rate())->toBe($before)->and($before)->toBe(3600.0);
});

it('carries no area column for a future report to sum', function () {
    // The structural half of the guarantee. Every assertion above could be satisfied today and
    // broken tomorrow by adding `area_sqm` "just for reporting" — at which point it is one join
    // away from a GLA sum. There is no legitimate reader for it, so there is no column.
    $columns = \Illuminate\Support\Facades\Schema::getColumnListing('rentable_items');

    expect(array_filter($columns, fn (string $c) => str_contains($c, 'area') && $c !== 'area_id'))
        ->toBe([]);
});

it('is property-owned, so one mall never sees another’s bays', function () {
    $here = makeAsset();
    $elsewhere = makeAsset();

    parkingFor($here, 'P-001');
    parkingFor($elsewhere, 'P-001');   // the same code in another property is fine

    expect(RentableItem::where('asset_id', $here->id)->count())->toBe(1);
});

it('refuses two items with the same code in one property', function () {
    $asset = makeAsset();
    parkingFor($asset, 'P-001');

    expect(fn () => parkingFor($asset, 'P-001'))
        ->toThrow(Illuminate\Database\QueryException::class);
});
