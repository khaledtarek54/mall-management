<?php

use App\Filament\Admin\RelationManagers\ChargeScheduleRelationManager;
use App\Filament\Admin\RelationManagers\LeaseDepositsRelationManager;
use App\Filament\Admin\RelationManagers\LeaseRentableItemsRelationManager;
use App\Filament\Admin\RelationManagers\LeaseStraightLineRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Charge;
use App\Settings\BillingSettings;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset), makeTenant(), [
        'status' => 'active', 'security_deposit' => 100000,
        'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31',
    ]);
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('mounts every tab that now carries a composed lease action', function (string $rm) {
    // The binding `LeaseActions::forOwner()` happens while the table is BUILT, so a mistake here
    // renders as a 500 on the tab and never as a failing unit test.
    Livewire::test($rm, [
        'ownerRecord' => $this->lease,
        'pageClass' => EditLease::class,
    ])->assertOk();
})->with([
    LeaseDepositsRelationManager::class,
    ChargeScheduleRelationManager::class,
    LeaseRentableItemsRelationManager::class,
]);

it('shows the straight-line tab only when the feature is on AND the lease can be averaged', function () {
    // A registered GL posting source that appeared on NO screen: the engine, its journalizer and
    // its scheduled command all shipped, the visibility layer never did, so a lease's straight-line
    // position was reachable only from a CLI. Found by sweeping the lease page for unreachable
    // functionality (2026-08-18).
    $rm = LeaseStraightLineRelationManager::class;
    $page = EditLease::class;

    app(BillingSettings::class)->fill(['straight_line_rent_enabled' => false])->save();
    expect($rm::canViewForRecord($this->lease, $page))->toBeFalse();

    app(BillingSettings::class)->fill(['straight_line_rent_enabled' => true])->save();

    // A lease with no rent ladder cannot be averaged either — the schedule reads the base_rent
    // charge rows, which is what a really-created lease always has.
    Charge::create([
        'lease_id' => $this->lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 48000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);

    // Both conditions matter. A lease with no term cannot be averaged, and averaging a term whose
    // end is unknown would be worse than recognising nothing.
    expect($rm::canViewForRecord($this->lease, $page))->toBeTrue();

    // `expiry_date` is NOT NULL at the column, so "no term" is unreachable — the reachable version
    // of the same refusal is a lease with no rent ladder to average.
    $noLadder = makeLease(makeUnit($this->asset), makeTenant(), [
        'status' => 'active', 'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31',
    ]);
    expect($rm::canViewForRecord($noLadder, $page))->toBeFalse();
});

it('mounts the straight-line tab and states the schedule above the rows', function () {
    app(BillingSettings::class)->fill(['straight_line_rent_enabled' => true])->save();

    Charge::create([
        'lease_id' => $this->lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 48000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);

    Livewire::test(LeaseStraightLineRelationManager::class, [
        'ownerRecord' => $this->lease,
        'pageClass' => EditLease::class,
    ])->assertOk();
});
