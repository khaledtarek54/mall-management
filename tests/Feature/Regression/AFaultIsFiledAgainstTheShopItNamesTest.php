<?php

use App\Enums\UnitOwnershipStatus;
use App\Filament\Portal\Resources\TenantRequests\Pages\CreateTenantRequest;
use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Models\Unit;
use App\Models\UnitOwnership;
use App\Services\TenantRequestService;
use Database\Seeders\TenantRequestSubcategorySeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Regression — mobile §L L3 (drift A4). A fault is filed against the shop it names — including a shop
 * the party OWNS while leasing another.
 *
 * `TenantRequestService::create()` resolved a named unit through the party's leases and fell back to
 * `activeLeases()->first()` when none held it — so for a party leasing one shop and owning another,
 * naming the OWNED shop returned the lease's master unit, never null, and the owned-unit branch below
 * was unreachable for anyone who held a lease. The validator had accepted the shop; the service
 * discarded it without a word. The wrong unit decides the reference's mall code, the SLA calendar, the
 * staff notified and the area supervisor, so an owned shop in another mall went to the wrong mall's
 * board. The portal offers owned shops into the same method, so it had the same bug.
 *
 * The ownership is now asked before the fallback. Every case after the first three is a control on a
 * path the reorder must leave exactly as it was — the clamp above all: a stranger's shop still
 * collapses to the party's own lease, or "fixing" this would have opened every unit in the building.
 */
beforeEach(function () {
    $this->seed(TenantRequestSubcategorySeeder::class);

    $this->mall = makeAsset();
    $this->party = makeTenant(['name' => 'Two Hats Trading']);
    $this->leased = makeUnit($this->mall, ['code' => 'LEASED-7']);
    $this->lease = makeLease($this->leased, $this->party);
    $this->owned = makeUnit($this->mall, ['code' => 'OWNED-7']);

    handOverShopTo($this->party, $this->owned);
});

afterEach(function () {
    Filament::setTenant(null, isQuiet: true);
});

/** A handed-over ownership covering today — the predicate the assessment run bills from. */
function handOverShopTo(Tenant $party, Unit $shop): UnitOwnership
{
    return UnitOwnership::create([
        'asset_id' => $shop->asset_id,
        'unit_id' => $shop->id,
        'tenant_id' => $party->id,
        'tenure_type' => 'freehold',
        'status' => UnitOwnershipStatus::HandedOver,
        'assessment_basis' => 'area',
        'ownership_share_pct' => 100,
        'started_at' => now()->subYear()->toDateString(),
        'handover_date' => now()->subYear()->toDateString(),
        'currency' => 'EGP',
    ]);
}

it('files against the shop they own when that is the shop they name — not the one they lease', function () {
    $this->postJson('/api/v1/me/requests', [
        'requestType' => 'maintenance',
        'title' => 'Shutter jammed',
        'description' => 'It will not close.',
        'category' => 'electrical',
        'unitId' => $this->owned->id,
    ], apiHeaders($this->party))
        ->assertCreated()
        ->assertJsonPath('data.unit.code', 'OWNED-7');

    $request = TenantRequest::sole();

    expect($request->unit_id)->toBe($this->owned->id)
        // The owner-only path's existing shape: an owned shop is held by no lease.
        ->and($request->lease_id)->toBeNull('an owned shop must not borrow the lease of the shop next door');
});

it('still files against the leased shop when that is the one named', function () {
    $this->postJson('/api/v1/me/requests', [
        'requestType' => 'maintenance',
        'title' => 'Light out',
        'description' => 'Front of house.',
        'category' => 'electrical',
        'unitId' => $this->leased->id,
    ], apiHeaders($this->party))
        ->assertCreated()
        ->assertJsonPath('data.unit.code', 'LEASED-7');

    expect(TenantRequest::sole()->lease_id)->toBe($this->lease->id);
});

it('still falls back to the lease when no shop is named', function () {
    $this->postJson('/api/v1/me/requests', [
        'requestType' => 'maintenance',
        'title' => 'Light out',
        'description' => 'Somewhere.',
        'category' => 'electrical',
    ], apiHeaders($this->party))
        ->assertCreated()
        ->assertJsonPath('data.unit.code', 'LEASED-7');
});

it('still clamps a stranger\'s shop to the party\'s own lease — the reorder opens nothing', function () {
    // Through the SERVICE: the API validator already 422s a stranger's unit, and the service is the
    // one choke point the portal and the API share, so the clamp has to hold here on its own.
    $stranger = makeUnit($this->mall, ['code' => 'NEXT-DOOR']);
    makeLease($stranger, makeTenant());

    $request = reportFaultThroughTheService($this->party, $stranger, 'electrical');

    expect($request->unit_id)->toBe($this->leased->id, 'a unit that is neither leased nor owned must never be filed against')
        ->and($request->lease_id)->toBe($this->lease->id);
});

it('routes an owned shop in another mall to that mall — its code on the reference', function () {
    $otherMall = makeAsset();
    $abroad = makeUnit($otherMall, ['code' => 'ABROAD-1']);
    handOverShopTo($this->party, $abroad);

    $request = reportFaultThroughTheService($this->party, $abroad, 'electrical');

    expect($request->unit_id)->toBe($abroad->id)
        ->and($request->reference)->toContain($otherMall->code)
        ->and($request->reference)->not->toContain($this->mall->code);
});

it('does the same from the portal, which reaches the same service', function () {
    $this->actingAs(makeTenantUser($this->party, isAdmin: true), 'portal');
    Filament::setCurrentPanel(Filament::getPanel('portal'));

    Livewire::test(CreateTenantRequest::class)
        ->fillForm([
            'unit_id' => $this->owned->id,
            'title' => 'Shutter jammed',
            'description' => 'It will not close.',
            'request_type' => 'maintenance',
            'category' => 'electrical',
            'priority' => 'medium',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(TenantRequest::sole()->unit_id)->toBe($this->owned->id);
});

it('still files against the lease when the owned shop named has been deleted, as before', function () {
    // A soft-deleted unit is not a shop anyone can report in. The reorder once refused this party —
    // who holds a live lease — as having no shop at all; the clamp's answer, the lease, is the old one.
    $this->owned->delete();

    $request = reportFaultThroughTheService($this->party, $this->owned, 'electrical');

    expect($request->unit_id)->toBe($this->leased->id)
        ->and($request->lease_id)->toBe($this->lease->id);
});

it('files an owner\'s fault against their own shop when they name a stranger\'s, as before', function () {
    $owner = makeTenant(['name' => 'Owner Only']);
    $shop = makeUnit($this->mall, ['code' => 'OWNER-ONLY']);
    handOverShopTo($owner, $shop);

    $request = reportFaultThroughTheService($owner, $this->leased, 'electrical');

    expect($request->unit_id)->toBe($shop->id)
        ->and($request->lease_id)->toBeNull();
});

it('files an owner\'s unnamed fault against their own shop, as before', function () {
    $owner = makeTenant(['name' => 'Owner Only']);
    $shop = makeUnit($this->mall, ['code' => 'OWNER-ONLY']);
    handOverShopTo($owner, $shop);

    $request = app(TenantRequestService::class)->create([
        'request_type' => 'maintenance',
        'priority' => 'medium',
        'category' => 'electrical',
        'title' => 'Reported fault',
        'description' => 'Something is wrong.',
    ], $owner);

    expect($request->unit_id)->toBe($shop->id);
});
