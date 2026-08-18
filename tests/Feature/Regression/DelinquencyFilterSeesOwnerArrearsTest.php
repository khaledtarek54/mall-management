<?php

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Models\Invoice;
use App\Models\UnitOwnership;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * GAP ANALYSIS — a unit owner in arrears is not "delinquent" to a restricted operator.
 *
 * The Tenants list's `is_delinquent` filter narrows a tenant's overdue invoices to the operator's
 * visible properties by walking `invoice → lease → unit → asset`. An owner assessment has **no
 * lease** (module 37 bills the ownership), so `whereHas('lease.unit', …)` drops it and the owner
 * falls out of the filter — an arrear that exists, is overdue, and is invisible to the collections
 * screen that exists to find exactly that.
 *
 * `invoices.asset_id` has been the authoritative property since the phase-2a denormalisation of
 * 2026-08-15, which moved `Invoice` off the `lease.unit` chain in the isolation registry precisely
 * because a lease-less invoice "falls out of every property-scoped query". This read site kept the
 * old inference.
 *
 * **Only bites a RESTRICTED user** — the narrowing is inside `->when(TenantScope::visibleAssetIds())`,
 * which is null for an unconstrained operator. That is why it survived: it is invisible to
 * super_admin, and the person who would notice is the one who cannot see it.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->asset = makeAsset(['code' => 'DQ']);
    $this->unit = makeUnit($this->asset);

    // A RESTRICTED operator — visibleAssetIds() is non-null, so the narrowing actually applies.
    $this->actingAs(makeUser('manager', [$this->asset->id]));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** An overdue invoice for a party, raised the way the given agreement raises one. */
function overdueInvoiceFor(int $tenantId, int $assetId, ?int $leaseId, ?int $ownershipId): Invoice
{
    return Invoice::create([
        'number' => 'INV-DQ-'.uniqid(),
        'asset_id' => $assetId,
        'lease_id' => $leaseId,
        'unit_ownership_id' => $ownershipId,
        'tenant_id' => $tenantId,
        'issue_date' => CarbonImmutable::now()->subMonths(2)->toDateString(),
        'period_start' => CarbonImmutable::now()->subMonths(2)->startOfMonth()->toDateString(),
        'period_end' => CarbonImmutable::now()->subMonths(2)->endOfMonth()->toDateString(),
        'due_date' => CarbonImmutable::now()->subMonth()->toDateString(),
        'status' => 'overdue',
        'subtotal' => 3000,
        'tax_amount' => 0,
        'total' => 3000,
        'paid_amount' => 0,
        'balance' => 3000,
        'currency' => 'EGP',
    ]);
}

it('flags a LESSEE in arrears — the control', function () {
    $tenant = makeTenant();
    $lease = makeLease($this->unit, $tenant, ['status' => 'active']);
    overdueInvoiceFor($tenant->id, $this->asset->id, $lease->id, null);

    Livewire::test(ListTenants::class)
        ->filterTable('is_delinquent', true)
        ->assertCanSeeTableRecords([$tenant]);
});

it('flags a unit OWNER in arrears on his assessment', function () {
    $owner = makeTenant(['party_type' => PartyType::UnitOwner->value]);
    $ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => makeUnit($this->asset)->id,
        'tenant_id' => $owner->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
        'payment_terms_days' => 10,
    ]);
    overdueInvoiceFor($owner->id, $this->asset->id, null, $ownership->id);

    Livewire::test(ListTenants::class)
        ->filterTable('is_delinquent', true)
        ->assertCanSeeTableRecords([$owner]);
});

it('still excludes a party whose arrears are in ANOTHER property', function () {
    // The narrowing must survive the fix — it is property isolation, not decoration.
    $other = makeAsset(['code' => 'DQ2']);
    $stranger = makeTenant();
    // A real agreement in the other mall — an invoice must be raised against a lease or an
    // ownership (the model refuses neither), so the fixture has to be one the product could produce.
    $otherLease = makeLease(makeUnit($other), $stranger, ['status' => 'active']);
    overdueInvoiceFor($stranger->id, $other->id, $otherLease->id, null);

    Livewire::test(ListTenants::class)
        ->filterTable('is_delinquent', true)
        ->assertCanNotSeeTableRecords([$stranger]);
});
