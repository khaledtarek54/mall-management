<?php

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Filament\Admin\Resources\Payments\Pages\CreatePayment;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\UnitOwnership;
use Livewire\Livewire;

/**
 * A unit owner must be payable on the property he owns in — and must appear on that property's
 * register, no more and no less.
 *
 * Module 37 raises an assessment invoice against a unit OWNER, who holds no lease at all. Two admin
 * surfaces scoped tenants by walking `leases.unit`, so for a property-restricted user an owner sat
 * wrong on both, in opposite directions:
 *
 *   - **PaymentForm's tenant picker excluded him outright.** His assessment could be raised, shown
 *     and chased, but no payment could ever be recorded against it — the AR had no way to clear.
 *   - **TenantResource's register admitted him only through `orWhereDoesntHave('leases')`**, a branch
 *     meant for a tenant just created so they don't vanish from the list that created them. An owner
 *     is PERMANENTLY unleased, so that branch showed every owner on every property. Invisible with
 *     one mall; a cross-property leak on the second.
 *
 * Both fixes are paired below with the control that stops them widening into "everyone", which is
 * the failure mode a one-line `orWhereDoesntHave` fix would have shipped.
 */
beforeEach(function () {
    // The real catalogue, not tests/Pest.php's seedRoles(): that creates six bare role rows with no
    // permissions, and `accounting` is not among them. Mounting CreatePayment needs a real grant.
    $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

    $this->here = makeAsset(['code' => 'HERE']);
    $this->elsewhere = makeAsset(['code' => 'ELSE']);

    $this->owner = makeTenant(['party_type' => PartyType::UnitOwner->value, 'name' => 'Owner Here']);
    $this->farOwner = makeTenant(['party_type' => PartyType::UnitOwner->value, 'name' => 'Owner Elsewhere']);
    $this->unaffiliated = makeTenant(['name' => 'Just Created']);

    foreach ([[$this->owner, $this->here], [$this->farOwner, $this->elsewhere]] as [$tenant, $asset]) {
        UnitOwnership::create([
            'asset_id' => $asset->id,
            'unit_id' => makeUnit($asset)->id,
            'tenant_id' => $tenant->id,
            'status' => UnitOwnershipStatus::HandedOver->value,
            'started_at' => '2026-01-01',
            'payment_terms_days' => 0,
        ]);
    }

    // Property-RESTRICTED on purpose: visibleAssetIds() is null for a super_admin, so the scoping
    // under test never runs for one and the whole file would pass without asserting anything.
    $this->user = makeUser('accounting', [$this->here->id]);
});

it('offers the unit owner on the payment form, and still refuses another property\'s owner', function () {
    $this->actingAs($this->user);

    $options = asTenant($this->here, fn () => array_map('intval', array_keys(
        Livewire::test(CreatePayment::class)
            ->instance()
            ->form
            ->getComponent('tenant_id')
            ->getOptions()
    )));

    // No message argument: Pest reads a second argument as another expected VALUE, so a note here
    // would assert the option list contains the note.
    expect($options)->toContain($this->owner->id)
        ->and($options)->not->toContain($this->farOwner->id);
});

it('shows the owner on the register of the property he owns in', function () {
    $this->actingAs($this->user);

    $ids = asTenant($this->here, fn () => TenantResource::getEloquentQuery()->pluck('id')->all());

    expect($ids)->toContain($this->owner->id)
        // The control: before the fix this passed for the wrong reason — every unleased tenant in
        // the portfolio was on every register, so the far owner was here too.
        ->and($ids)->not->toContain($this->farOwner->id);
});

it('keeps a tenant affiliated with nowhere visible, which is what that branch is for', function () {
    $this->actingAs($this->user);

    $ids = asTenant($this->here, fn () => TenantResource::getEloquentQuery()->pluck('id')->all());

    expect($ids)->toContain($this->unaffiliated->id);
});
