<?php

use App\Models\Asset;
use App\Models\Invoice;
use App\Models\Unit;
use App\Support\TenantScope;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->hw = makeAsset(['code' => 'HW']);
    $this->pa = makeAsset(['code' => 'PA']);
    $this->hwUnit = makeUnit($this->hw, ['code' => 'HW-1']);
    $this->paUnit = makeUnit($this->pa, ['code' => 'PA-1']);
    $this->hwLease = makeLease($this->hwUnit);
    $this->paLease = makeLease($this->paUnit);
    makeInvoice($this->hwLease, ['balance' => 100]);
    makeInvoice($this->paLease, ['balance' => 200]);
});

it('passes a query through unchanged when no tenant is set', function () {
    expect(TenantScope::applyTo(Invoice::query())->count())->toBe(2);
});

it('filters by direct asset_id when no relation is given', function () {
    asTenant($this->hw, function () {
        expect(TenantScope::applyTo(Unit::query())->count())->toBe(1);
        expect(TenantScope::applyTo(Unit::query())->first()->code)->toBe('HW-1');
    });
});

it('filters via whereHas when a relation chain is given', function () {
    asTenant($this->hw, function () {
        expect(TenantScope::applyTo(Invoice::query(), 'lease.unit')->sum('balance'))->toEqual(100);
    });

    asTenant($this->pa, function () {
        expect(TenantScope::applyTo(Invoice::query(), 'lease.unit')->sum('balance'))->toEqual(200);
    });
});

it('passes through unchanged when All Properties is active', function () {
    $all = Asset::where('code', Asset::ALL_PROPERTIES_CODE)->first();
    asTenant($all, function () {
        expect(TenantScope::applyTo(Invoice::query(), 'lease.unit')->count())->toBe(2);
    });
});
