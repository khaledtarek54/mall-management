<?php

use App\Filament\Admin\Resources\Areas\Pages\CreateArea;
use App\Filament\Admin\Resources\Violations\Pages\CreateViolation;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Violation;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Regression — the "Announcements tenancy trap" on Violation + Area (found by the adversarial review
 * of the violations module).
 *
 * Both resources let the operator PICK asset_id (the mall the record belongs to), and that Select is
 * enabled in All-Properties mode. While they used Filament auto-tenancy
 * (`$tenantOwnershipRelationshipName='asset'` + BypassesScopingOnAll, which does NOT turn
 * `isScopedToTenant()` off), Filament registered a model `creating` hook that force-associated
 * asset_id with the CURRENT tenant — and in All-mode the tenant is the ALL pseudo-asset. So a record
 * created in All-mode picking a real mall was silently stored against the pseudo-asset and vanished
 * from every real mall's scoped list. Fixed by switching both to BypassesFilamentTenantAutoScope +
 * an explicit getEloquentQuery (the AnnouncementResource pattern), so the picked asset_id is kept and
 * re-validated by assertAssetInScope.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->mall = makeAsset(['code' => 'REAL']);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    // Put the panel in All-Properties mode — the exact condition that triggered the clobber.
    Filament::setTenant(Asset::where('code', Asset::ALL_PROPERTIES_CODE)->firstOrFail());
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('pins a violation to the CHOSEN mall when created in All-Properties mode', function () {
    Livewire::test(CreateViolation::class)
        ->fillForm([
            'asset_id' => $this->mall->id,
            'tenant_id' => makeTenant()->id,
            'description' => 'Blocked fire exit',
            'violation_date' => now()->toDateString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Violation::latest('id')->first()->asset_id)
        ->toBe($this->mall->id); // the real mall, NOT the ALL pseudo-asset
});

it('pins an area to the CHOSEN mall when created in All-Properties mode', function () {
    Livewire::test(CreateArea::class)
        ->fillForm([
            'asset_id' => $this->mall->id,
            'name' => 'Food Court',
            'code' => 'FC',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Area::latest('id')->first()->asset_id)
        ->toBe($this->mall->id);
});
