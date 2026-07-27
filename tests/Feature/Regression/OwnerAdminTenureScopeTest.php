<?php

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Support\AssignedAssets;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

/*
|--------------------------------------------------------------------------
| Owner ownership-tenure scoping on the ADMIN panel (module 15)
|--------------------------------------------------------------------------
| Owners are admin-panel RBAC users (the /owner panel was removed) scoped to
| their owned properties via AssignedAssets / User::accessibleAssets /
| canAccessTenant. Those used the ALL-TIME ownedAssets relation, so a former
| owner (sold their stake → asset_owner.ended_at in the past) kept the sold mall
| in their /admin scope + tenant switcher. Now they use currentOwnedAssets.
|
| The subtle trap: AssignedAssets returns null (= UNRESTRICTED) when the id set
| is empty (single-mall back-compat) — so a former owner with NO current holdings
| must NOT collapse to null (would see EVERY mall). It returns a never-matching
| sentinel [0] (see-nothing) for a lapsed scope, null only for a never-scoped user.
*/

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

it('drops a sold property from a former owner\'s admin scope while keeping a still-held one', function () {
    $sold = makeAsset(['code' => 'SOLD']);
    $held = makeAsset(['code' => 'HELD']);
    $soldInv = makeInvoice(makeLease(makeUnit($sold)));
    $heldInv = makeInvoice(makeLease(makeUnit($held)));

    $owner = makeUser('owner');
    $owner->ownedAssets()->attach($sold->id, ['ownership_percentage' => 100, 'ended_at' => now()->subMonth()->toDateString()]);
    $owner->ownedAssets()->attach($held->id, ['ownership_percentage' => 100]); // current
    $this->actingAs($owner);

    // Scope helpers: only the held property, and NOT null (unrestricted).
    expect(AssignedAssets::idsFor($owner))->toContain($held->id)->not->toContain($sold->id)->not->toBeNull();
    expect($owner->accessibleAssets()->pluck('id')->all())->toContain($held->id)->not->toContain($sold->id);
    expect($owner->canAccessTenant($sold))->toBeFalse()
        ->and($owner->canAccessTenant($held))->toBeTrue();

    // The admin resource (All-Properties scope) surfaces the held invoice, never the sold one.
    Filament::setTenant(ensureAllPropertiesAsset());
    $invoiceIds = InvoiceResource::getEloquentQuery()->pluck('id')->all();
    expect($invoiceIds)->toContain($heldInv->id)->not->toContain($soldInv->id);
});

it('a former owner holding NOTHING now sees NOTHING, not everything (the null-collapse trap)', function () {
    $sold = makeAsset(['code' => 'SOLD']);
    $soldInv = makeInvoice(makeLease(makeUnit($sold)));

    $owner = makeUser('owner');
    $owner->ownedAssets()->attach($sold->id, ['ownership_percentage' => 100, 'ended_at' => now()->subMonth()->toDateString()]);
    $this->actingAs($owner);

    // Must be RESTRICTED (not null → unrestricted → sees every mall).
    expect(AssignedAssets::idsFor($owner))->not->toBeNull()
        ->and(AssignedAssets::isRestricted($owner))->toBeTrue();

    Filament::setTenant(ensureAllPropertiesAsset());
    expect(InvoiceResource::getEloquentQuery()->pluck('id')->all())->toBeEmpty();
});

it('preserves the single-mall back-compat: a user never assigned or owning stays unrestricted (null)', function () {
    $viewer = makeUser('viewer'); // a role, but no assignment + no ownership + not an owner
    expect(AssignedAssets::idsFor($viewer))->toBeNull();
});

it('a current owner still sees their property in the admin scope', function () {
    $held = makeAsset(['code' => 'CUR']);
    $inv = makeInvoice(makeLease(makeUnit($held)));
    $owner = makeUser('owner');
    $owner->ownedAssets()->attach($held->id, ['ownership_percentage' => 100]);
    $this->actingAs($owner);

    expect(AssignedAssets::idsFor($owner))->toBe([$held->id])
        ->and($owner->canAccessTenant($held))->toBeTrue();

    Filament::setTenant(ensureAllPropertiesAsset());
    expect(InvoiceResource::getEloquentQuery()->pluck('id')->all())->toContain($inv->id);
});
