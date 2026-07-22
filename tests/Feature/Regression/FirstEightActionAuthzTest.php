<?php

use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Models\Invoice;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The systemic `visible()`-is-not-a-gate class, found again in the first-8 UX pass:
 * mountAction() never consults isVisible(), so a write action gated ONLY in visible() is dispatchable.
 * These pin the action()-side `abort_unless` via mountAction + callMountedAction (NOT callAction /
 * assertActionHidden, which check visible() and would FALSE-PASS while the hole is open).
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('refuses a leasing user provisioning tenant portal credentials (portalAccess is manager+ only)', function () {
    $tenant = makeTenant(['password' => null, 'status' => 'inactive']);

    // `leasing` holds tenants.edit — so it reaches EditTenant — but is NOT super_admin/manager.
    $this->actingAs(makeUser('leasing', [$this->asset->id]));
    Filament::setTenant($this->asset);

    Livewire::test(EditTenant::class, ['record' => $tenant->id])
        ->mountAction('portalAccess')
        ->callMountedAction();

    expect($tenant->fresh()->password)->toBeNull()          // no credentials set
        ->and($tenant->fresh()->status)->toBe('inactive');  // not silently activated
});

it('refuses a read-only viewer triggering a property-wide monthly billing run', function () {
    makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'active']);

    // `viewer` holds invoices.view (the list renders) but NOT invoices.create.
    $this->actingAs(makeUser('viewer', [$this->asset->id]));
    Filament::setTenant($this->asset);

    Livewire::test(ListInvoices::class)
        ->mountAction(TestAction::make('runMonthlyBilling')->table())
        ->callMountedAction();

    expect(Invoice::count())->toBe(0); // no invoices minted, no GL posted
});
