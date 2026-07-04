<?php

use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use App\Filament\Admin\Resources\FixedAssets\Pages\EditFixedAsset;
use App\Filament\Admin\Resources\FixedAssets\Pages\ListFixedAssets;
use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use App\Services\DepreciationService;
use App\Services\DisposeFixedAssetService;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

function makeFixedAsset(int $assetId, array $attrs = []): FixedAsset
{
    return FixedAsset::create(array_merge([
        'asset_id' => $assetId,
        'name' => 'HVAC Unit',
        'tag' => 'FA-' . uniqid(),
        'acquisition_date' => '2026-01-01',
        'acquisition_cost' => 12000,
        'salvage_value' => 0,
        'useful_life_months' => 12,
        'method' => 'straight_line',
        'funded_from' => 'cash',
    ], $attrs));
}

/* ---- RBAC ---------------------------------------------------------------- */

it('gates the fixed-asset resource on fixed_assets permissions', function () {
    // accounting owns fixed_assets.* — can view + create; leasing has none.
    $this->actingAs(makeUser('accounting'));
    expect(FixedAssetResource::canViewAny())->toBeTrue();
    expect(FixedAssetResource::canCreate())->toBeTrue();

    $this->actingAs(makeUser('leasing'));
    expect(FixedAssetResource::canViewAny())->toBeFalse();

    // viewer sees, but cannot create.
    $this->actingAs(makeUser('viewer'));
    expect(FixedAssetResource::canViewAny())->toBeTrue();
    expect(FixedAssetResource::canCreate())->toBeFalse();
});

it('hides the fixed-asset resource when the module is disabled', function () {
    $this->actingAs(makeUser('super_admin'));
    expect(FixedAssetResource::canViewAny())->toBeTrue();

    $settings = app(\App\Settings\ModulesSettings::class);
    $settings->fixed_assets = false;
    $settings->save();

    expect(FixedAssetResource::canViewAny())->toBeFalse();
});

/* ---- Property scoping ---------------------------------------------------- */

it('scopes fixed assets to the current property', function () {
    $assetA = makeAsset(['code' => 'FAA']);
    $assetB = makeAsset(['code' => 'FAB']);
    makeFixedAsset($assetA->id, ['tag' => 'TAG-A']);
    makeFixedAsset($assetB->id, ['tag' => 'TAG-B']);

    $this->actingAs(makeUser('super_admin'));

    asTenant($assetA, function () {
        expect(scopedResourceQuery(FixedAssetResource::class)->pluck('tag')->all())
            ->toContain('TAG-A')->not->toContain('TAG-B');
    });
});

/* ---- Derived columns ----------------------------------------------------- */

it('exposes the derived accumulated depreciation on the query', function () {
    $asset = makeAsset();
    $fa = makeFixedAsset($asset->id, ['acquisition_cost' => 12000, 'useful_life_months' => 12]); // 1000/mo
    app(DepreciationService::class)->run(\Carbon\CarbonImmutable::parse('2026-03-01'));

    $this->actingAs(makeUser('super_admin'));

    asTenant($asset, function () use ($fa) {
        $row = scopedResourceQuery(FixedAssetResource::class)->whereKey($fa->id)->first();
        expect((float) $row->accumulated)->toBe(1000.0);
        expect(round((float) $row->acquisition_cost - (float) $row->accumulated, 2))->toBe(11000.0); // NBV
    });
});

/* ---- Dispose action ------------------------------------------------------ */

it('disposes an asset and stops future depreciation', function () {
    $asset = makeAsset();
    $fa = makeFixedAsset($asset->id);

    $this->actingAs(makeUser('accounting', [$asset->id]));

    asTenant($asset, function () use ($fa) {
        Livewire::test(ListFixedAssets::class)
            ->callTableAction('dispose', $fa, data: ['disposed_on' => now()->toDateString(), 'proceeds' => 250])
            ->assertHasNoTableActionErrors();
    });

    expect($fa->fresh()->status)->toBe('disposed');
    // The action records the disposal (the GL write-off source).
    expect($fa->fresh()->disposal)->not->toBeNull();
    expect((float) $fa->fresh()->disposal->proceeds)->toBe(250.0);

    // A disposed asset is skipped by the monthly run.
    expect(app(DepreciationService::class)->run(\Carbon\CarbonImmutable::parse('2026-08-01')))->toBe(0);
    expect(DepreciationEntry::where('fixed_asset_id', $fa->id)->count())->toBe(0);
});

it('forbids disposing for a role without fixed_assets.edit', function () {
    $asset = makeAsset();
    $fa = makeFixedAsset($asset->id);

    // viewer can view but not edit → the dispose action is hidden/unauthorized.
    $this->actingAs(makeUser('viewer', [$asset->id]));

    asTenant($asset, function () use ($fa) {
        Livewire::test(ListFixedAssets::class)
            ->assertTableActionHidden('dispose', $fa);
    });

    expect($fa->fresh()->status)->toBe('active');
});

it('makes a disposed asset terminal — hides edit + blocks the edit page (immutable)', function () {
    $asset = makeAsset();
    $fa = makeFixedAsset($asset->id);
    $this->actingAs(makeUser('accounting', [$asset->id]));
    app(DisposeFixedAssetService::class)->dispose($fa, ['disposed_on' => now()->toDateString()]);

    asTenant($asset, function () use ($fa) {
        // Edit action is hidden on a disposed (terminal) row.
        Livewire::test(ListFixedAssets::class)->assertTableActionHidden('edit', $fa->fresh());

        // Direct edit-page access is blocked server-side (403).
        try {
            Livewire::test(EditFixedAsset::class, ['record' => $fa->getKey()])
                ->fillForm(['name' => 'Tampered'])
                ->call('save');
        } catch (\Throwable $e) {
            // abort(403) may surface as an exception on the Livewire path.
        }
    });

    // The disposed asset was not mutated.
    expect($fa->fresh()->name)->not->toBe('Tampered');
});

/* ---- Post-depreciation header action ------------------------------------ */

it('posts this month\'s depreciation from the list action', function () {
    $asset = makeAsset();
    $fa = makeFixedAsset($asset->id, ['acquisition_date' => '2026-01-01', 'acquisition_cost' => 12000, 'useful_life_months' => 12]);

    $this->actingAs(makeUser('accounting', [$asset->id]));

    asTenant($asset, function () {
        Livewire::test(ListFixedAssets::class)
            ->callAction('post_depreciation')
            ->assertHasNoActionErrors();
    });

    // One entry for the current month.
    expect(DepreciationEntry::where('fixed_asset_id', $fa->id)->count())->toBe(1);
});

it('hides the post-depreciation action from a read-only role', function () {
    $asset = makeAsset();
    makeFixedAsset($asset->id);

    $this->actingAs(makeUser('viewer', [$asset->id]));

    asTenant($asset, function () {
        Livewire::test(ListFixedAssets::class)
            ->assertActionHidden('post_depreciation');
    });
});

it('posts depreciation only for the user\'s visible properties (never portfolio-wide from a scoped context)', function () {
    $assetA = makeAsset(['code' => 'DPA']);
    $assetB = makeAsset(['code' => 'DPB']);
    $faA = makeFixedAsset($assetA->id, ['tag' => 'DP-A', 'acquisition_date' => '2026-01-01']);
    $faB = makeFixedAsset($assetB->id, ['tag' => 'DP-B', 'acquisition_date' => '2026-01-01']);

    // Accounting user scoped to property A only.
    $this->actingAs(makeUser('accounting', [$assetA->id]));

    asTenant($assetA, function () {
        Livewire::test(ListFixedAssets::class)
            ->callAction('post_depreciation')
            ->assertHasNoActionErrors();
    });

    // Only property A's asset was depreciated; property B untouched.
    expect(DepreciationEntry::where('fixed_asset_id', $faA->id)->count())->toBe(1);
    expect(DepreciationEntry::where('fixed_asset_id', $faB->id)->count())->toBe(0);
});

/* ---- asset_id scope guard (All-Properties tamper) ------------------------ */

it('rejects an out-of-scope asset_id and allows an in-scope one', function () {
    $assetA = makeAsset(['code' => 'SGA']);
    $assetB = makeAsset(['code' => 'SGB']);

    // Accounting user restricted to property A.
    $this->actingAs(makeUser('accounting', [$assetA->id]));

    // In-scope target passes.
    FixedAssetResource::assertAssetInScope($assetA->id);
    expect(true)->toBeTrue();

    // Tampered out-of-scope target aborts 403.
    expect(fn () => FixedAssetResource::assertAssetInScope($assetB->id))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('lets a portfolio user target any property (visibleAssetIds is null)', function () {
    $assetA = makeAsset(['code' => 'PGA']);
    $assetB = makeAsset(['code' => 'PGB']);

    $this->actingAs(makeUser('super_admin'));

    // No throw for either property.
    FixedAssetResource::assertAssetInScope($assetA->id);
    FixedAssetResource::assertAssetInScope($assetB->id);
    expect(true)->toBeTrue();
});
