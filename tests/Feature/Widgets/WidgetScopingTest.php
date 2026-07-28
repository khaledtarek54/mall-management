<?php

use App\Filament\Admin\Widgets\ActionRequired;
use App\Filament\Admin\Widgets\ArAging;
use App\Filament\Admin\Widgets\LeasingPipeline;
use App\Filament\Admin\Widgets\MallStats;
use App\Filament\Admin\Widgets\TenantMix;
use App\Models\Asset;
use App\Models\Unit;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    // Real role definitions: the ActionRequired cards are gated on the {module}.view permission of
    // the register each links to, and `makeUser()` alone creates a bare role with no permissions —
    // a manager that cannot exist in production. Without this the card list is empty and the
    // assertions below would pass over nothing.
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->hw = makeAsset(['code' => 'HW']);
    $this->pa = makeAsset(['code' => 'PA']);

    $this->hwUnit = makeUnit($this->hw, ['status' => 'occupied']);
    $this->paUnit = makeUnit($this->pa, ['status' => 'vacant']);

    $this->hwLease = makeLease($this->hwUnit, attrs: ['base_rent_monthly' => 10000, 'service_charge_monthly' => 2000]);
    $this->paLease = makeLease($this->paUnit, attrs: ['base_rent_monthly' => 5000, 'service_charge_monthly' => 1000]);

    $this->hwInvoice = makeInvoice($this->hwLease, ['balance' => 11400, 'due_date' => now()->subDays(10)]);
    $this->paInvoice = makeInvoice($this->paLease, ['balance' => 6000, 'due_date' => now()->subDays(10)]);
});

/**
 * Reflection helper: call protected widget methods like getStats() / getData()
 * in isolation without instantiating Livewire scaffolding.
 */
function widgetCall(string $widgetClass, string $method): mixed
{
    $widget = new $widgetClass;
    $ref = new ReflectionMethod($widget, $method);

    return $ref->invoke($widget);
}

it('MallStats reflects only the current property AR balance', function () {
    asTenant($this->hw, function () {
        $stats = widgetCall(MallStats::class, 'getStats');
        $arStat = collect($stats)->first(fn ($s) => str_contains($s->getValue(), '11,400'));
        expect($arStat)->not->toBeNull();
    });

    asTenant($this->pa, function () {
        $stats = widgetCall(MallStats::class, 'getStats');
        $arStat = collect($stats)->first(fn ($s) => str_contains($s->getValue(), '11,400'));
        expect($arStat)->toBeNull();
    });
});

it('MallStats surfaces economic (area) occupancy, property-scoped', function () {
    // Give the two properties deliberately different area mixes so they can't share a figure,
    // then assert each scope's widget value equals that property's own areaOccupancyRate() —
    // which proves the stat renders, weights by area, AND stays isolated to the scoped property
    // (all-mode aggregation would match neither single asset).
    makeUnit($this->hw, ['status' => 'occupied', 'area_sqm' => 3000]);
    makeUnit($this->pa, ['status' => 'vacant', 'area_sqm' => 4000]);

    foreach ([$this->hw, $this->pa] as $asset) {
        asTenant($asset, function () use ($asset) {
            $stats = widgetCall(MallStats::class, 'getStats');
            $eco = collect($stats)->first(fn ($s) => $s->getLabel() === __('admin.widgets.mall_stats.economic_occupancy'));
            expect($eco)->not->toBeNull();
            expect($eco->getValue())->toBe($asset->fresh()->areaOccupancyRate().'%');
        });
    }

    // And the two must genuinely differ, or the isolation assertion above proves nothing.
    expect($this->hw->fresh()->areaOccupancyRate())
        ->not->toBe($this->pa->fresh()->areaOccupancyRate());
});

it('ArAging totals are property-scoped', function () {
    asTenant($this->hw, function () {
        $data = widgetCall(ArAging::class, 'getData');
        $totals = array_sum($data['datasets'][0]['data']);
        expect($totals)->toBe(11400.0);
    });

    asTenant($this->pa, function () {
        $data = widgetCall(ArAging::class, 'getData');
        $totals = array_sum($data['datasets'][0]['data']);
        expect($totals)->toBe(6000.0);
    });
});

it('ArAging aggregates both properties under All Properties', function () {
    $all = Asset::where('code', Asset::ALL_PROPERTIES_CODE)->first();

    asTenant($all, function () {
        $data = widgetCall(ArAging::class, 'getData');
        $totals = array_sum($data['datasets'][0]['data']);
        expect($totals)->toBe(17400.0);
    });
});

it('LeasingPipeline counts only the current property leases', function () {
    asTenant($this->hw, function () {
        $stats = widgetCall(LeasingPipeline::class, 'getStats');
        $active = collect($stats)->first(fn ($s) => str_contains($s->getDescription(), '12,000'));
        expect($active)->not->toBeNull();
    });

    asTenant($this->pa, function () {
        $stats = widgetCall(LeasingPipeline::class, 'getStats');
        $active = collect($stats)->first(fn ($s) => str_contains($s->getDescription(), '6,000'));
        expect($active)->not->toBeNull();
    });
});

it('TenantMix slices by category for the current property', function () {
    makeUnit($this->hw, ['status' => 'occupied', 'category' => 'food_beverage']);
    makeUnit($this->hw, ['status' => 'occupied', 'category' => 'food_beverage']);
    makeLease(Unit::where('asset_id', $this->hw->id)->where('category', 'food_beverage')->orderBy('id')->first(), attrs: ['status' => 'active']);
    makeLease(Unit::where('asset_id', $this->hw->id)->where('category', 'food_beverage')->orderBy('id', 'desc')->first(), attrs: ['status' => 'active']);

    asTenant($this->hw, function () {
        $data = widgetCall(TenantMix::class, 'getData');
        $labels = $data['labels'];
        expect($labels)->not->toBeEmpty();
    });
});

it('ActionRequired surfaces only the current property overdue invoices', function () {
    asTenant($this->hw, function () {
        $view = widgetCall(ActionRequired::class, 'getViewData');
        $overdue = collect($view['items'])->firstWhere('key', 'overdue');
        expect($overdue)->not->toBeNull();
        expect($overdue['body'])->toContain('11,400');
    });

    asTenant($this->pa, function () {
        $view = widgetCall(ActionRequired::class, 'getViewData');
        $overdue = collect($view['items'])->firstWhere('key', 'overdue');
        expect($overdue['body'])->toContain('6,000');
    });
});
