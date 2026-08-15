<?php

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Filament\Portal\Resources\CamAllocations\CamAllocationResource;
use App\Filament\Portal\Resources\Invoices\InvoiceResource;
use App\Filament\Portal\Resources\Leases\LeaseResource;
use App\Filament\Portal\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\UnitOwnership;
use App\Services\BillUnitOwnershipsService;
use App\Services\CamReconciliationService;
use Carbon\CarbonImmutable;

/**
 * A unit owner signs into the portal and sees HIS account — Yardi's owner portal (plan 08 §5.9).
 *
 * Voyager's condo owner portal is the same portal product the residents use: the owner pays dues,
 * reads his statement and raises requests there. Atriom needed no new panel for this, and that is
 * the party decision paying off — an owner IS a `tenants` row, the portal authenticates a
 * `TenantUser` against one, and every portal query already scopes by `tenant_id`.
 *
 * So most of this file asserts that something ALREADY works. The two that did not:
 *
 *   - CAM allocations were scoped `whereHas('lease', ...)`. An owner's allocation has no lease
 *     (phase 3 made ownerships participants), so his own CAM true-up was invisible to him while
 *     appearing on his invoice — one more instance of the lease-chain assumption.
 *   - Leases and sales declarations were offered to him. An owner has neither, and Voyager's owner
 *     portal does not show them.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'PRT', 'leasable_area_sqm' => 100]);
    $this->unit = makeUnit($this->asset, ['area_sqm' => 100]);

    $this->owner = makeTenant(['party_type' => PartyType::UnitOwner->value]);
    $this->ownerUser = makeTenantUser($this->owner);

    $this->ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $this->unit->id,
        'tenant_id' => $this->owner->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
    ]);

    Charge::create([
        'unit_ownership_id' => $this->ownership->id,
        'name' => 'Service charge', 'type' => 'service_charge',
        'amount' => 2000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'is_active' => true, 'start_date' => '2026-01-01',
    ]);

    app(BillUnitOwnershipsService::class)->runForPeriod(CarbonImmutable::parse('2026-03-01'), $this->asset->id);

    $this->actingAs($this->ownerUser, 'portal');
});

it('shows the owner his own assessments — no change was needed for this', function () {
    // The party decision doing its job: the portal scopes on tenant_id and an owner is a tenant.
    $invoices = InvoiceResource::getEloquentQuery()->get();

    expect($invoices)->toHaveCount(1)
        ->and($invoices->first()->unit_ownership_id)->toBe($this->ownership->id)
        ->and($invoices->first()->lease_id)->toBeNull();
});

it('shows the owner his own CAM share', function () {
    $pool = CamExpensePool::create([
        'asset_id' => $this->asset->id, 'period_year' => 2026, 'name' => 'CAM 2026',
        'total_actual_expense' => 40000, 'total_estimated_collected' => 0, 'status' => 'draft',
    ]);
    app(CamReconciliationService::class)->generateAllocations($pool);

    // Scoped `whereHas('lease', ...)` this returned nothing: his allocation has no lease. He was
    // billed a true-up he could not see the basis of.
    $mine = CamAllocationResource::getEloquentQuery()->get();

    expect($mine)->toHaveCount(1)
        ->and($mine->first()->unit_ownership_id)->toBe($this->ownership->id);

    // Control — another owner's allocation in the same pool stays invisible to him.
    $other = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => makeUnit($this->asset, ['area_sqm' => 50])->id,
        'tenant_id' => makeTenant(['party_type' => PartyType::UnitOwner->value])->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
    ]);
    CamAllocation::create([
        'cam_expense_pool_id' => $pool->id, 'unit_ownership_id' => $other->id,
        'pro_rata_share_pct' => 10, 'allocated_amount' => 100, 'estimated_paid' => 0,
        'true_up_amount' => 100, 'status' => 'pending',
    ]);

    expect(CamAllocationResource::getEloquentQuery()->count())->toBe(1);
});

it('does not offer an owner the screens he can have no rows for', function () {
    // Voyager's owner portal shows dues, statements and requests — not leases, not sales
    // declarations. An owner signs neither, so an empty screen is worse than no screen: it invites
    // him to wonder what is missing.
    expect(LeaseResource::shouldRegisterNavigation())->toBeFalse()
        ->and(TenantSalesDeclarationResource::shouldRegisterNavigation())->toBeFalse();
});

it('still offers a RETAILER both of those screens', function () {
    // The control that stops the hiding being over-applied. A retailer's portal is unchanged.
    $retailer = makeTenant();
    $this->actingAs(makeTenantUser($retailer), 'portal');

    expect(LeaseResource::shouldRegisterNavigation())->toBeTrue()
        ->and(TenantSalesDeclarationResource::shouldRegisterNavigation())->toBeTrue();
});
