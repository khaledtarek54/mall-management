<?php

use App\Filament\Admin\Resources\Assets\Pages\CreateAsset;
use App\Filament\Admin\Resources\Assets\Pages\EditAsset;
use App\Models\Asset;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * A property saved at 0 m² is a positive claim that the mall has no size.
 *
 * Reported by the tester: Plaza Mall saved with Total Area 0 and Leasable Area 0, no warning, a
 * clean "Saved" toast. Zero is NOT "not measured yet" — occupancy %, GLA, rent per m² and every
 * charge apportioned by area are computed from these two numbers, and each of them reads a zero as
 * a real measurement. Nothing errors; the reports simply come out wrong and look filled in, which
 * is the failure mode nobody reports.
 *
 * Driven through the real Create and Edit pages, because that is where the rule lives — the columns
 * stay nullable so an importer or a seeder can still stage a property, and a model-level rule would
 * refuse those too.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
    // The Properties resource sits above the per-property context, but the panel CHROME does not —
    // its breadcrumbs build a tenant-scoped URL, so the page cannot render without one.
    $this->context = makeAsset();
});

it('refuses a new property measured at zero', function () {
    asTenant($this->context, function () {
        Livewire::test(CreateAsset::class)
            ->fillForm([
                'name' => 'Plaza Mall',
                'code' => '007',
                'type' => 'mall',
                'city' => 'Alexandria',
                'country' => 'Egypt',
                'currency' => 'EGP',
                'total_area_sqm' => 0,
                'leasable_area_sqm' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['total_area_sqm', 'leasable_area_sqm']);
    });

    expect(Asset::where('code', '007')->exists())->toBeFalse();
});

it('refuses an existing property being edited down to zero', function () {
    $asset = makeAsset(['total_area_sqm' => 5000, 'leasable_area_sqm' => 4000]);

    asTenant($this->context, function () use ($asset) {
        Livewire::test(EditAsset::class, ['record' => $asset->getRouteKey()])
            ->fillForm(['total_area_sqm' => 0, 'leasable_area_sqm' => 0])
            ->call('save')
            ->assertHasFormErrors(['total_area_sqm', 'leasable_area_sqm']);
    });

    // The stored figures must survive a refused save — a form that refuses AFTER writing is worse
    // than one that never refused.
    expect((float) $asset->fresh()->total_area_sqm)->toBe(5000.0)
        ->and((float) $asset->fresh()->leasable_area_sqm)->toBe(4000.0);
});

it('refuses a property with no leasable area stated', function () {
    // Blank is not a way round the rule ON THE GLA. That is the number the money reads —
    // CamReconciliationService uses it as the recovery denominator — so leaving it empty produces
    // the same wrong recoveries as typing 0.
    asTenant($this->context, function () {
        Livewire::test(CreateAsset::class)
            ->fillForm([
                'name' => 'Unmeasured Mall',
                'code' => 'UM1',
                'type' => 'mall',
                'city' => 'Cairo',
                'country' => 'Egypt',
                'currency' => 'EGP',
            ])
            ->call('create')
            ->assertHasFormErrors(['leasable_area_sqm']);
    });
});

it('still saves a mall whose GROSS area has not been measured', function () {
    // The asymmetry, and the reason the fix is not simply "require both". A property may legitimately
    // know its lettable area before anyone has measured the whole building: `leasableEfficiencyPct()`
    // answers null rather than 0% for exactly this case, and APropertyCannotLetMoreThanItHasTest has
    // pinned it since that rule was written. Requiring both fields would have quietly reversed a
    // decision somebody had already taken — this case is here so the next person cannot.
    asTenant($this->context, function () {
        Livewire::test(CreateAsset::class)
            ->fillForm([
                'name' => 'Atriom North',
                'code' => 'NTH',
                'type' => 'mall',
                'city' => 'Cairo',
                'country' => 'Egypt',
                'currency' => 'EGP',
                'total_area_sqm' => null,
                'leasable_area_sqm' => 800,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    });

    expect(Asset::where('code', 'NTH')->sole()->leasableEfficiencyPct())->toBeNull();
});

it('still refuses a GROSS area typed as zero', function () {
    // Optional does not mean "0 is fine". A blank gross area is "not measured"; a zero gross area is
    // a claim that the building has no size, and it would make the load factor infinite.
    asTenant($this->context, function () {
        Livewire::test(CreateAsset::class)
            ->fillForm([
                'name' => 'Zero Gross Mall',
                'code' => 'ZGM',
                'type' => 'mall',
                'city' => 'Cairo',
                'country' => 'Egypt',
                'currency' => 'EGP',
                'total_area_sqm' => 0,
                'leasable_area_sqm' => 800,
            ])
            ->call('create')
            ->assertHasFormErrors(['total_area_sqm']);
    });
});

it('still accepts a real measurement', function () {
    // The control. Every refusal above passes just as happily on a form that refuses everything.
    asTenant($this->context, function () {
        Livewire::test(CreateAsset::class)
            ->fillForm([
                'name' => 'Plaza Mall',
                'code' => '007',
                'type' => 'mall',
                'city' => 'Alexandria',
                'country' => 'Egypt',
                'currency' => 'EGP',
                'total_area_sqm' => 12000,
                'leasable_area_sqm' => 9000,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    });

    expect((float) Asset::where('code', '007')->sole()->leasable_area_sqm)->toBe(9000.0);
});

it('still refuses a leasable area larger than the building', function () {
    // The rule that was already there has to survive the new one: both bounds sit on both fields,
    // and a `min` that short-circuited the cross-field closure would drop it silently.
    asTenant($this->context, function () {
        Livewire::test(CreateAsset::class)
            ->fillForm([
                'name' => 'Impossible Mall',
                'code' => 'IMP',
                'type' => 'mall',
                'city' => 'Cairo',
                'country' => 'Egypt',
                'currency' => 'EGP',
                'total_area_sqm' => 1000,
                'leasable_area_sqm' => 2000,
            ])
            ->call('create')
            ->assertHasFormErrors(['leasable_area_sqm']);
    });
});
