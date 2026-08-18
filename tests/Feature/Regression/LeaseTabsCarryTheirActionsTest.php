<?php

use App\Filament\Admin\RelationManagers\ChargeScheduleRelationManager;
use App\Filament\Admin\RelationManagers\LeaseDepositsRelationManager;
use App\Filament\Admin\RelationManagers\LeaseRentableItemsRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
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
