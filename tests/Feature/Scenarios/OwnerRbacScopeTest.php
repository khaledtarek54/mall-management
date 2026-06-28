<?php

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\OwnerRequests\OwnerRequestResource;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

/**
 * Jawad owners are NOT a separate portal — they're admin RBAC users with the
 * 'owner' role: read-only oversight (.view everywhere + reports.download), the
 * one write being owner_requests.create, and scoped to the properties they
 * legally own (asset_owner pivot, folded into AssignedAssets). These tests pin
 * that contract.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

it('gives a Jawad owner read-only oversight (view yes, create/edit/delete no)', function () {
    $this->actingAs(makeUser('owner'));

    expect(InvoiceResource::canViewAny())->toBeTrue()
        ->and(InvoiceResource::canCreate())->toBeFalse()
        ->and(LeaseResource::canViewAny())->toBeTrue()
        ->and(LeaseResource::canCreate())->toBeFalse()
        // The one thing an owner may create: a request to the operator.
        ->and(OwnerRequestResource::canCreate())->toBeTrue();
});

it('scopes a Jawad owner to the properties they own (no cross-property leak)', function () {
    $owned = makeAsset(['code' => 'OWN']);
    $other = makeAsset(['code' => 'OTH']);
    $invOwned = makeInvoice(makeLease(makeUnit($owned)));
    $invOther = makeInvoice(makeLease(makeUnit($other)));

    $owner = makeUser('owner');
    $owner->ownedAssets()->attach($owned->id, ['ownership_percentage' => 100]);
    $this->actingAs($owner);
    Filament::setTenant(ensureAllPropertiesAsset()); // "All Properties" view

    $ids = InvoiceResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toContain($invOwned->id)->not->toContain($invOther->id);
});
