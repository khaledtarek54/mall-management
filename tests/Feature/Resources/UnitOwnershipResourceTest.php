<?php

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Filament\Admin\Resources\UnitOwnerships\Pages\CreateUnitOwnership;
use App\Filament\Admin\Resources\UnitOwnerships\UnitOwnershipResource;
use App\Models\Asset;
use App\Models\UnitOwnership;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The ownership screen — RBAC, property isolation, and the write guard.
 *
 * Property isolation is asserted through `getEloquentQuery()` rather than by reading the table, so
 * the assertion is about the scope itself and cannot pass because a column happened to be hidden.
 */
beforeEach(function () {
    // The FULL seeder, not `seedRoles()` — that helper creates role rows and no permissions at all,
    // so every `canCreate()` would answer false and each refusal assertion below would pass for the
    // wrong reason. The same trap the search tests documented.
    $this->seed(RolesPermissionsSeeder::class);

    $this->mall = makeAsset();
    $this->otherMall = makeAsset();
    $this->unit = makeUnit($this->mall);
    $this->buyer = makeTenant(['party_type' => PartyType::UnitOwner->value]);
});

function ownershipIn(Asset $asset, array $attrs = []): UnitOwnership
{
    return UnitOwnership::create(array_merge([
        'asset_id' => $asset->id,
        'unit_id' => makeUnit($asset)->id,
        'tenant_id' => makeTenant(['party_type' => PartyType::UnitOwner->value])->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
    ], $attrs));
}

it('shows a user only the ownerships in the malls they can see', function () {
    $mine = ownershipIn($this->mall);
    $theirs = ownershipIn($this->otherMall);

    $user = makeUser('leasing', [$this->mall->id]);

    $this->actingAs($user);

    $visible = scopedResourceQuery(UnitOwnershipResource::class)->pluck('id')->all();

    // Paired: the refusal means nothing without a control that must find something.
    expect($visible)->toContain($mine->id)
        ->and($visible)->not->toContain($theirs->id);
});

it('lets leasing record a sale and refuses a viewer', function () {
    expect(UnitOwnershipResource::canCreate())->toBeFalse(); // no user yet

    $this->actingAs(makeUser('leasing', [$this->mall->id]));
    expect(UnitOwnershipResource::canCreate())->toBeTrue()
        ->and(UnitOwnershipResource::canViewAny())->toBeTrue();

    $this->actingAs(makeUser('viewer', [$this->mall->id]));
    expect(UnitOwnershipResource::canViewAny())->toBeTrue()
        ->and(UnitOwnershipResource::canCreate())->toBeFalse();
});

it('refuses a sale written against a property the operator cannot see', function () {
    // The asset_id Select is client-supplied when the panel is not pinned to one mall, so the
    // submitted value is re-validated server-side. A crafted payload naming another operator's mall
    // must 403 rather than quietly record a sale there.
    $user = makeUser('leasing', [$this->mall->id]);
    $this->actingAs($user);

    expect(fn () => UnitOwnershipResource::assertAssetInScope($this->otherMall->id))
        ->toThrow(HttpException::class);

    // Control — the same guard must ACCEPT the mall they do hold, or the refusal above proves
    // nothing about scope and only that the guard rejects everything.
    UnitOwnershipResource::assertAssetInScope($this->mall->id);
    expect(true)->toBeTrue();
});

it('records a sale through the create page', function () {
    $this->actingAs(makeUser('leasing', [$this->mall->id]));

    // Inside the panel's property context — the page resolves its schema from the panel, and
    // without a tenant Filament has no page to build the form against.
    asTenant($this->mall, fn () => Livewire::test(CreateUnitOwnership::class)
        ->fillForm([
            'asset_id' => $this->mall->id,
            'unit_id' => $this->unit->id,
            'tenant_id' => $this->buyer->id,
            'status' => UnitOwnershipStatus::HandedOver->value,
            'started_at' => '2026-02-01',
        ])
        ->call('create')
        ->assertHasNoFormErrors());

    $ownership = UnitOwnership::query()->where('unit_id', $this->unit->id)->firstOrFail();

    expect($ownership->owner->is($this->buyer))->toBeTrue()
        ->and($ownership->reference)->toStartWith('UO-'.$this->mall->code.'-')
        ->and($ownership->isBillableOn('2026-03-01'))->toBeTrue();
});
