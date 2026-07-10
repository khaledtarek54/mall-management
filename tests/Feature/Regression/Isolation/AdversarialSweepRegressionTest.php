<?php

use App\Filament\Admin\RelationManagers\TenantLeasesRelationManager;
use App\Filament\Admin\RelationManagers\TenantMaintenanceRelationManager;
use App\Filament\Admin\RelationManagers\TenantPaymentsRelationManager;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Filament\Admin\Resources\Vendors\Pages\EditVendor;
use App\Filament\Admin\Resources\Vendors\RelationManagers\ContractsRelationManager;
use App\Filament\Admin\Widgets\MallStats;
use App\Models\Payment;
use App\Models\TenantRequest;
use App\Models\Vendor;
use App\Models\VendorContract;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Regression guards for the 2026-07 adversarial isolation sweep. Each targets a
 * confirmed cross-property leak that the read-scope / write-guard work had missed:
 *   (A) the Leases "Quick new lease" wizard action (unscoped unit picker + no guard),
 *   (B) Tenant/Vendor relation-manager tables listing a SHARED entity's rows portfolio-wide,
 *   (C) dashboard widgets scoping by currentAssetId() (null in All-Properties mode → leak).
 * A restricted user assigned only to property A must never see/write property B's data.
 */

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->a = makeAsset(['code' => 'ADVA']);
    $this->b = makeAsset(['code' => 'ADVB']);
    $this->unitA = makeUnit($this->a);
    $this->unitB = makeUnit($this->b);

    // A single SHARED tenant leasing in BOTH malls — the pivot the sweep abused.
    $this->tenant = makeTenant();
    $this->leaseA = makeLease($this->unitA, $this->tenant);
    $this->leaseB = makeLease($this->unitB, $this->tenant);

    // A manager restricted to property A only (visibleAssetIds() = [A]).
    $this->actingAs(makeUser('manager', [$this->a->id]));
});

/* ---- (A) Quick-new-lease wizard write guard ------------------------------- */

it('blocks the quick-lease guard for an out-of-scope unit (and allows in-scope)', function () {
    LeaseResource::assertUnitAssetInScope($this->unitA->id);
    expect(true)->toBeTrue();

    expect(fn () => LeaseResource::assertUnitAssetInScope($this->unitB->id))
        ->toThrow(HttpException::class);
});

it('wires the guard + scoped picker into the quickLease action (not just the standard page)', function () {
    $src = file_get_contents(app_path('Filament/Admin/Resources/Leases/Tables/LeasesTable.php'));
    expect($src)->toContain('assertUnitAssetInScope')                 // write guard in the action
        ->and($src)->toContain('TenantScope::visibleAssetIds');       // scoped unit picker
});

/* ---- (B) Relation-manager tables scoped to the visible properties --------- */

it('TenantLeasesRelationManager lists only leases in the visible property', function () {
    Livewire::test(TenantLeasesRelationManager::class, [
        'ownerRecord' => $this->tenant,
        'pageClass' => EditTenant::class,
    ])
        ->assertCanSeeTableRecords([$this->leaseA])
        ->assertCanNotSeeTableRecords([$this->leaseB]);
});

it('TenantMaintenanceRelationManager lists only requests in the visible property', function () {
    $reqA = makeTenantRequestFor($this->unitA, $this->tenant);
    $reqB = makeTenantRequestFor($this->unitB, $this->tenant);

    // asTenant provides the {tenant} route param the RM's "open" row-action URL needs
    // (visibleAssetIds() stays [A] for the restricted manager regardless).
    asTenant($this->a, function () use ($reqA, $reqB) {
        Livewire::test(TenantMaintenanceRelationManager::class, [
            'ownerRecord' => $this->tenant,
            'pageClass' => EditTenant::class,
        ])
            ->assertCanSeeTableRecords([$reqA])
            ->assertCanNotSeeTableRecords([$reqB]);
    });
});

it('TenantPaymentsRelationManager lists only payments settling visible-property invoices', function () {
    $payA = makePaymentFor(makeInvoice($this->leaseA), $this->tenant);
    $payB = makePaymentFor(makeInvoice($this->leaseB), $this->tenant);

    Livewire::test(TenantPaymentsRelationManager::class, [
        'ownerRecord' => $this->tenant,
        'pageClass' => EditTenant::class,
    ])
        ->assertCanSeeTableRecords([$payA])
        ->assertCanNotSeeTableRecords([$payB]);
});

it('Vendor ContractsRelationManager hides another property\'s contract, shows portfolio + in-scope', function () {
    $vendor = Vendor::create(['name' => 'Shared Vendor', 'status' => 'active']);
    $cA = VendorContract::create(['vendor_id' => $vendor->id, 'asset_id' => $this->a->id, 'name' => 'A contract', 'status' => 'active', 'start_date' => now(), 'currency' => 'EGP']);
    $cB = VendorContract::create(['vendor_id' => $vendor->id, 'asset_id' => $this->b->id, 'name' => 'B contract', 'status' => 'active', 'start_date' => now(), 'currency' => 'EGP']);
    $cNull = VendorContract::create(['vendor_id' => $vendor->id, 'asset_id' => null, 'name' => 'Portfolio contract', 'status' => 'active', 'start_date' => now(), 'currency' => 'EGP']);

    Livewire::test(ContractsRelationManager::class, [
        'ownerRecord' => $vendor,
        'pageClass' => EditVendor::class,
    ])
        ->assertCanSeeTableRecords([$cA, $cNull])
        ->assertCanNotSeeTableRecords([$cB]);
});

/* ---- (C) Widget stays scoped for a restricted user in All-Properties mode -- */

it('MallStats does not leak another property\'s AR in All-Properties mode', function () {
    // A: outstanding AR = 100. B: a distinctive 9999 that must NOT appear.
    makeInvoice($this->leaseA, ['balance' => 100, 'total' => 100, 'status' => 'issued']);
    makeInvoice($this->leaseB, ['balance' => 9999, 'total' => 9999, 'status' => 'issued']);

    // All-Properties mode: currentAssetId() is null; the fix must fall back to [A].
    asTenant(ensureAllPropertiesAsset(), function () {
        $stats = (new ReflectionMethod(MallStats::class, 'getStats'))->invoke(new MallStats);
        $rendered = collect($stats)->map(fn ($s) => (string) $s->getValue().' '.(string) ($s->getDescription() ?? ''))->implode(' | ');

        // A's 100 is in scope; B's 9,999 must be filtered out entirely.
        expect($rendered)->not->toContain('9,999')->not->toContain('9999');
    });
});

/* ---- helpers -------------------------------------------------------------- */

function makeTenantRequestFor(\App\Models\Unit $unit, \App\Models\Tenant $tenant): TenantRequest
{
    return TenantRequest::create([
        'reference' => 'MR-'.uniqid(),
        'unit_id' => $unit->id,
        'tenant_id' => $tenant->id,
        'title' => 'Test request',
        'description' => 'Test',
        'status' => 'submitted',
        'priority' => 'medium',
        'category' => 'electrical',
        'submitted_at' => now(),
    ]);
}

function makePaymentFor(\App\Models\Invoice $invoice, \App\Models\Tenant $tenant): Payment
{
    $payment = Payment::create([
        'tenant_id' => $tenant->id,
        'amount' => 100,
        'payment_date' => now(),
        'method' => 'cash',
        'status' => 'captured',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 100]);

    return $payment;
}
