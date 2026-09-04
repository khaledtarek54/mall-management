<?php

use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Models\Charge;
use App\Models\Invoice;
use App\Support\Vat;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Write actions that must be gated in action(), not merely hidden.
 *
 * CORRECTED 2026-07-31. This file used to claim "mountAction() never consults isVisible(), so a
 * write action gated ONLY in visible() is dispatchable". It does consult it, indirectly:
 * mountAction() refuses DISABLED actions and `CanBeDisabled::isDisabled()` returns true when
 * `isHidden()` does. Verified by mutation — deleting runMonthlyBilling's
 * `abort_unless(InvoiceResource::canCreate())` left this file 2/2 green, so the mountAction tests
 * below were measuring visible() and reporting it as the gate.
 *
 * The mountAction tests are kept (they prove the action is unreachable through the UI path) and the
 * direct-call test at the bottom reaches the `abort_unless` itself. See
 * FilamentActionDispatchContractTest for the framework behaviour both rest on.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('refuses a leasing user provisioning tenant portal credentials (mobileAppAccess is manager+ only)', function () {
    $tenant = makeTenant(['password' => null, 'status' => 'inactive']);

    // `leasing` holds tenants.edit — so it reaches EditTenant — but is NOT super_admin/manager.
    $this->actingAs(makeUser('leasing', [$this->asset->id]));
    Filament::setTenant($this->asset);

    Livewire::test(EditTenant::class, ['record' => $tenant->id])
        ->mountAction('mobileAppAccess')
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

/* ---- the abort_unless inside action(), reached directly -------------------- */

it('refuses a viewer running monthly billing when the action closure is reached directly', function () {
    // A property-wide billing run mints invoices and posts to the GL, so this is the gate that
    // matters. Action::call() evaluates the closure directly (Action.php:666), which is the only
    // path that reaches an abort_unless — mountAction refuses the action as disabled first.
    makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'active']);

    $this->actingAs(makeUser('viewer', [$this->asset->id]));
    Filament::setTenant($this->asset);

    $action = Livewire::test(ListInvoices::class)->instance()->getTable()->getAction('runMonthlyBilling');
    expect($action)->not->toBeNull();

    expect(fn () => $action->call())
        ->toThrow(HttpException::class);

    expect(Invoice::count())->toBe(0);
});

it('control: an authorised user CAN run monthly billing through the same path', function () {
    // Without this the refusal above would pass identically if call() never ran the closure —
    // the failure that made every test in this file meaningless before today.
    //
    // The lease needs a billable charge: an active lease with none produces no invoice, which
    // would make this control fail for a reason unrelated to authorisation (it did, first run).
    $lease = makeLease(makeUnit($this->asset), makeTenant(), [
        'status' => 'active',
        'commencement_date' => now()->subYear()->startOfMonth(),
        'expiry_date' => now()->addYear()->endOfMonth(),
    ]);

    Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Base rent',
        'type' => 'base_rent',
        'amount' => 25000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => false,
        'vat_rate' => Vat::EXEMPT,
        'start_date' => now()->subYear()->startOfMonth(),
        'is_active' => true,
    ]);

    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    Filament::setTenant($this->asset);

    $action = Livewire::test(ListInvoices::class)->instance()->getTable()->getAction('runMonthlyBilling');
    $action->call();

    expect(Invoice::count())->toBeGreaterThan(0);
});
