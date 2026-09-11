<?php

use App\Filament\Admin\RelationManagers\AssetUnitsRelationManager;
use App\Filament\Admin\RelationManagers\TenantInvoicesRelationManager;
use App\Filament\Admin\RelationManagers\TenantLeasesRelationManager;
use App\Filament\Admin\Resources\Assets\Pages\EditAsset;
use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Admin\Resources\Leases\Pages\ListLeases;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Filament\Admin\Resources\Units\Pages\ListUnits;
use App\Support\BadgeColors;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * A STATUS BADGE IS THE SAME COLOUR ON EVERY SCREEN THAT SHOWS IT.
 *
 * Measured 2026-09-11 before `App\Support\BadgeColors` existed: a `vacant` unit was RED on the unit
 * register and AMBER on the property's own Units tab; a `future` lease was blue on the register and
 * GREY on the tenant's leases tab; an `issued` invoice was blue on the register and AMBER on the
 * tenant's invoices tab. Each screen had written its own `match ($state)`, each was right on its
 * own, and no test compared two.
 *
 * These three pairs are the ones an operator meets in one sitting — the register, then the record
 * they clicked through to. Each reads the REAL column off the REAL mounted component and asks it
 * for the colour it would render, so a tab that stopped reading the registry (or read the wrong
 * set) turns the pair red. `BadgeColorsConformanceTest` is the source half — it forbids an inline
 * map for a registered set — and this is the half that proves the screens actually agree.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset();
    $this->actingAs(makeUser('manager', [$this->asset->id]));
});

/** The colour a mounted component's `status` column resolves for one record. */
function statusColourOn(string $component, array $params, $record): ?string
{
    $column = Livewire::test($component, $params)->instance()->getTable()->getColumn('status');

    return $column->record($record)->getColor($record->status);
}

it('colours a vacant unit the same on the register and on the property tab', function () {
    $unit = makeUnit($this->asset, ['status' => 'vacant']);

    $colours = asTenant($this->asset, fn (): array => [
        'register' => statusColourOn(ListUnits::class, [], $unit),
        'tab' => statusColourOn(AssetUnitsRelationManager::class, [
            'ownerRecord' => $this->asset, 'pageClass' => EditAsset::class,
        ], $unit),
    ]);

    expect($colours['register'])->toBe(BadgeColors::for('units.status', 'vacant'))
        ->and($colours['tab'])->toBe($colours['register']);
});

it('colours a future lease the same on the register and on the tenant tab', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit($this->asset), $tenant, [
        'status' => 'future',
        'commencement_date' => now()->addMonths(2)->startOfMonth(),
        'expiry_date' => now()->addMonths(26)->endOfMonth(),
    ]);

    $colours = asTenant($this->asset, fn (): array => [
        'register' => statusColourOn(ListLeases::class, [], $lease),
        'tab' => statusColourOn(TenantLeasesRelationManager::class, [
            'ownerRecord' => $tenant, 'pageClass' => EditTenant::class,
        ], $lease),
    ]);

    expect($colours['register'])->toBe(BadgeColors::for('leases.status', 'future'))
        ->and($colours['tab'])->toBe($colours['register']);
});

it('colours an issued invoice the same on the register and on the tenant tab', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit($this->asset), $tenant, ['status' => 'active']);
    $invoice = makeInvoice($lease, ['status' => 'issued']);

    $colours = asTenant($this->asset, fn (): array => [
        'register' => statusColourOn(ListInvoices::class, [], $invoice),
        'tab' => statusColourOn(TenantInvoicesRelationManager::class, [
            'ownerRecord' => $tenant, 'pageClass' => EditTenant::class,
        ], $invoice),
    ]);

    expect($colours['register'])->toBe(BadgeColors::for('invoices.status', 'issued'))
        ->and($colours['tab'])->toBe($colours['register']);
});
