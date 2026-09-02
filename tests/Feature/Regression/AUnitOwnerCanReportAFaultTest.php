<?php

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Filament\Portal\Resources\TenantRequests\Pages\CreateTenantRequest;
use App\Models\TenantRequest;
use App\Models\UnitOwnership;
use App\Services\TenantRequestService;
use Database\Seeders\TenantRequestSubcategorySeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A UNIT OWNER COULD NOT RAISE A REQUEST: THE SCREEN WAS OFFERED AND THE PICKER WAS EMPTY.
 *
 * Module 37's own rule is that an owner IS a `tenants` row, and every other portal surface treats
 * them as one — they receive assessments, pay them, read their own statement. The tenant-request
 * form's Unit picker drew from LEASES only, so an owner who has taken handover of a shop and holds
 * no lease saw an EMPTY dropdown on a `required()` field.
 *
 * **That is the worst shape of failure this project keeps finding**: nothing errors, nothing is
 * refused, and an empty picker reads as *"no such record"* rather than as a bug — so it gets
 * reported as missing data, or not at all. The fault they were trying to report came in by
 * telephone instead.
 *
 * The ownership predicate is `handed_over` AND covering today, which is the same one the assessment
 * run bills from: `contracted` or `reserved` means the shop has not been given to them yet, and a
 * `transferred` one is somebody else's now. Neither is a place they can report a fault in.
 */
beforeEach(function () {
    $this->seed(TenantRequestSubcategorySeeder::class);

    $this->asset = makeAsset();
    $this->shop = makeUnit($this->asset, ['code' => 'OWNED-1']);
    $this->owner = makeTenant(['name' => 'Mr Owner', 'party_type' => PartyType::UnitOwner->value]);

    $this->ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $this->shop->id,
        'tenant_id' => $this->owner->id,
        'tenure_type' => 'freehold',
        'status' => UnitOwnershipStatus::HandedOver,
        'assessment_basis' => 'area',
        'ownership_share_pct' => 100,
        'started_at' => now()->subYear()->toDateString(),
        'handover_date' => now()->subYear()->toDateString(),
        'currency' => 'EGP',
    ]);

    $this->actingAs(makeTenantUser($this->owner, isAdmin: true), 'portal');
    Filament::setCurrentPanel(Filament::getPanel('portal'));
});

it('lets an owner with no lease report a fault in their own shop', function () {
    Livewire::test(CreateTenantRequest::class)
        // The picker opens on it, which is what an empty dropdown never did.
        ->assertFormSet(['unit_id' => $this->shop->id])
        ->fillForm([
            'unit_id' => $this->shop->id,
            'title' => 'Lift out of service',
            'description' => 'The service lift has been out since Friday.',
            'request_type' => 'maintenance',
            'category' => 'electrical',
            'priority' => 'medium',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(TenantRequest::where('tenant_id', $this->owner->id)->where('unit_id', $this->shop->id)->exists())
        ->toBeTrue();
});

it('does not offer a shop the owner has not been handed yet', function () {
    // The refusal, and it is the same predicate the assessment run bills from: `contracted` means
    // the sale is signed and the shop is not theirs to be in.
    $notYet = makeUnit($this->asset, ['code' => 'NOT-YET']);

    UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $notYet->id,
        'tenant_id' => $this->owner->id,
        'tenure_type' => 'freehold',
        'status' => UnitOwnershipStatus::Contracted,
        'assessment_basis' => 'area',
        'ownership_share_pct' => 100,
        'started_at' => now()->subMonth()->toDateString(),
        'currency' => 'EGP',
    ]);

    Livewire::test(CreateTenantRequest::class)
        ->fillForm([
            'unit_id' => $notYet->id,
            'title' => 'Not mine yet',
            'description' => 'Should be refused.',
            'request_type' => 'maintenance',
            'category' => 'electrical',
            'priority' => 'medium',
        ])
        ->call('create')
        ->assertHasFormErrors(['unit_id']);
});

it('does not offer a shop the owner has since SOLD', function () {
    // A transferred ownership is somebody else's shop now — and `covering()` is what expresses it,
    // because the row stays on the books as history.
    $this->ownership->update([
        'status' => UnitOwnershipStatus::Transferred,
        'ended_at' => now()->subMonth()->toDateString(),
    ]);

    Livewire::test(CreateTenantRequest::class)
        ->fillForm([
            'unit_id' => $this->shop->id,
            'title' => 'Sold it',
            'description' => 'Should be refused.',
            'request_type' => 'maintenance',
            'category' => 'electrical',
            'priority' => 'medium',
        ])
        ->call('create')
        ->assertHasFormErrors(['unit_id']);
});

it('still opens on a LEASED unit for an ordinary retailer', function () {
    // The control. Owners were added to the picker, and a change that swapped one for the other
    // would satisfy every assertion above while taking the feature away from every tenant.
    $retailer = makeTenant(['name' => 'Café Crema']);
    $shop = makeUnit($this->asset, ['code' => 'LEASED-1']);
    makeLease($shop, $retailer);

    $this->actingAs(makeTenantUser($retailer, isAdmin: true), 'portal');

    Livewire::test(CreateTenantRequest::class)
        ->assertFormSet(['unit_id' => $shop->id]);
});

it('refuses with a message, not a 500, when the account has no shop at all', function () {
    // `tenant_requests.unit_id` is NOT NULL, so a party with no lease and no handed-over shop — a
    // tenant whose lease ended, an owner still waiting for handover — hit an integrity-constraint
    // violation and got the error page. A `DomainException` renders as its own message, which is
    // what a person can act on.
    $stranded = makeTenant(['name' => 'No shop']);

    expect(fn () => app(TenantRequestService::class)->create([
        'title' => 'Anything',
        'description' => 'Anything at all',
        'request_type' => 'maintenance',
        'priority' => 'medium',
    ], $stranded))->toThrow(DomainException::class);
});
