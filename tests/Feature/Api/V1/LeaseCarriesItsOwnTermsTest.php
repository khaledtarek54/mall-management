<?php

use App\Models\DepositTransaction;
use App\Models\Lease;
use App\Models\RentableItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * **A tenant can read their own contract in the app, not only on the web.**
 *
 * `LeaseResource` published 13 fields while the portal's own lease view rendered those plus fifteen
 * more — the deposit, the escalation, the percentage-rent threshold, the bays. The rule both files
 * state is that *"the portal and /api/v1 are the same surface with different renderers"*, and it had
 * been honoured for visibility and not for content.
 *
 * Each case here is one of the omissions that was more than incompleteness.
 *
 * **Every money assertion casts `(float)` first, and that is a WIRE FACT rather than test tidiness.**
 * PHP's `json_encode(180000.0)` emits `180000` — no decimal point — so a whole money figure reaches
 * the client as a JSON *integer* and `json_decode` hands PHP back an `int`. It is true of every
 * money field this API has ever sent (`total`, `balance`, `amount`), not of these new ones, and it
 * is what makes a Dart `as double` throw. Pinned by its own case at the foot of this file.
 */
it('tells the tenant what deposit is still outstanding — the only place they are ever told', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant, ['security_deposit' => 180000]);

    // 150,000 of an agreed 180,000 has actually been received.
    DepositTransaction::create([
        'lease_id' => $lease->id,
        'tenant_id' => $tenant->id,
        'type' => 'receipt',
        'amount' => 150000,
        'method' => 'bank',
        'transaction_date' => now(),
    ]);

    $data = $this->getJson('/api/v1/me/leases', apiHeaders($tenant))
        ->assertOk()
        ->json('data.0');

    // Three numbers, not one. The contracted figure alone cannot be read: it is
    // indistinguishable from a bill, a receipt, or a line copied off the contract.
    expect((float) $data['securityDeposit'])->toBe(180000.0)
        ->and((float) $data['depositHeld'])->toBe(150000.0)
        // The one that matters. A deposit shortfall is never invoiced, so nothing else in this
        // system will ever ask the tenant for it.
        ->and((float) $data['depositOutstanding'])->toBe(30000.0);
});

it('shows a rent step that is a fixed AMOUNT, not only a percentage', function () {
    $tenant = makeTenant();
    makeLease(makeUnit(makeAsset()), $tenant, [
        'escalation_type' => 'fixed_amount',
        'escalation_amount' => 5000,
        'escalation_rate' => 0,
        'next_escalation_date' => '2027-01-01',
    ]);

    $data = $this->getJson('/api/v1/me/leases', apiHeaders($tenant))->assertOk()->json('data.0');

    // The sibling bug the portal fixed was keying visibility on `escalation_rate > 0`, which is
    // ZERO on a fixed-amount lease — so a tenant whose rent steps by EGP 5,000 a year was shown
    // nothing at all about it. Both shapes ship; the client reads `escalationType`.
    expect($data['escalationType'])->toBe('fixed_amount')
        ->and((float) $data['escalationAmount'])->toBe(5000.0)
        ->and($data['nextEscalationDate'])->toBe('2027-01-01');
});

it('sends the percentage-rent THRESHOLD, without which the rate answers nothing', function () {
    $tenant = makeTenant();
    makeLease(makeUnit(makeAsset()), $tenant, [
        'has_percentage_rent' => true,
        'percentage_rent_rate' => 5,
        'percentage_rent_threshold' => 800000,
        'percentage_rent_frequency' => 'annual',
    ]);

    $data = $this->getJson('/api/v1/me/leases', apiHeaders($tenant))->assertOk()->json('data.0');

    // "5%" cannot tell a tenant whether they owe anything. "5% above 800,000, annual" can.
    expect((float) $data['percentageRentRate'])->toBe(5.0)
        ->and((float) $data['percentageRentThreshold'])->toBe(800000.0)
        ->and($data['percentageRentFrequency'])->toBe('annual');
});

it('names each bay and its rate, not just how many there are', function () {
    $tenant = makeTenant();
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset), $tenant);

    $bay = RentableItem::create([
        'asset_id' => $asset->id,
        'code' => 'P-14',
        'type' => RentableItem::TYPE_PARKING,
        'status' => 'available',
    ]);
    $lease->rentableItems()->attach($bay->id, [
        'monthly_rate' => 750,
        'effective_from' => '2026-01-01',
    ]);

    $data = $this->getJson('/api/v1/me/leases', apiHeaders($tenant))->assertOk()->json('data.0');

    // The count is KEPT — a released client reads it.
    expect($data['parkingSpots'])->toBe(1)
        // …and the detail is what the invoice's "Parking & rentable items" line could never be
        // checked against. Which bay, at what rate, is the most common billing query there is.
        ->and($data['rentableItems'])->toHaveCount(1)
        ->and($data['rentableItems'][0]['code'])->toBe('P-14')
        ->and((float) $data['rentableItems'][0]['monthlyRate'])->toBe(750.0)
        // A raw pivot string, not a cast — `optional(...)->toDateString()` would answer null here.
        ->and($data['rentableItems'][0]['effectiveFrom'])->toBe('2026-01-01');
});

it('drops a bay the tenant has given back', function () {
    $tenant = makeTenant();
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset), $tenant);

    $released = RentableItem::create([
        'asset_id' => $asset->id, 'code' => 'P-01',
        'type' => RentableItem::TYPE_PARKING, 'status' => 'available',
    ]);
    $lease->rentableItems()->attach($released->id, [
        'monthly_rate' => 500,
        'effective_from' => '2026-01-01',
        // A release is recorded by CLOSING the holding, not by detaching it.
        'effective_to' => '2026-06-30',
    ]);

    $data = $this->getJson('/api/v1/me/leases', apiHeaders($tenant))->assertOk()->json('data.0');

    // The tenant is not paying for it, so it must not appear among the things they are paying for.
    expect($data['rentableItems'])->toBe([])
        // …and the COUNT beside it must agree. It counted every holding ever recorded, so a
        // released bay produced `parkingSpots: 1` next to an empty list — one payload contradicting
        // itself, which is worse than either answer alone.
        ->and($data['parkingSpots'])->toBe(0);
});

it('shows EVERY shop on a lease that opens on two, and the area the rent was priced on', function () {
    $tenant = makeTenant();
    $asset = makeAsset();
    $master = makeUnit($asset, ['code' => 'A-01', 'area_sqm' => 120]);
    $second = makeUnit($asset, ['code' => 'A-02', 'area_sqm' => 80]);

    $lease = makeLease($master, $tenant);
    $lease->units()->sync([
        $master->id => ['is_master' => true],
        $second->id => ['is_master' => false],
    ]);

    $data = $this->getJson('/api/v1/me/leases', apiHeaders($tenant))->assertOk()->json('data.0');

    // `unit` stays the MASTER, unchanged — a released client reads that shape.
    expect($data['unit']['code'])->toBe('A-01')
        // …but the premises are both shops, and the AREA is the combined one. The rent on this
        // same card was derived from it (rate x totalAreaSqmOn()), so publishing only the master's
        // 120 put two figures on one screen that could not reconcile.
        ->and(collect($data['units'])->pluck('code')->sort()->values()->all())->toBe(['A-01', 'A-02'])
        ->and((float) $data['totalAreaSqm'])->toBe(200.0);
});

it('streams the signed lease, and 404s a lease that is not yours', function () {
    Storage::fake('local');

    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant);
    $lease->addMedia(UploadedFile::fake()->create('signed-lease.pdf', 10, 'application/pdf'))
        ->toMediaCollection(Lease::DOCUMENTS_COLLECTION);

    // The flag the client gates the button on.
    expect($this->getJson('/api/v1/me/leases', apiHeaders($tenant))->json('data.0.hasDocument'))->toBeTrue();

    $this->get("/api/v1/me/leases/{$lease->id}/document", apiHeaders($tenant))->assertOk();

    // Somebody else's contract is a 404, never a 403 — the whole-surface rule against existence
    // enumeration. Paired with the success above so a scope that hid everything cannot pass.
    $other = makeLease(makeUnit(makeAsset()), makeTenant());
    $this->get("/api/v1/me/leases/{$other->id}/document", apiHeaders($tenant))->assertNotFound();
});

it('refuses the paperwork of a DRAFT lease', function () {
    Storage::fake('local');

    $tenant = makeTenant();
    $draft = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'draft']);
    $draft->addMedia(UploadedFile::fake()->create('unsigned.pdf', 10, 'application/pdf'))
        ->toMediaCollection(Lease::DOCUMENTS_COLLECTION);

    // A draft is terms still being written. The lease PICKER was fixed for this on 2026-09-02; a
    // document route that skipped `visibleToTenant()` would reopen the hole through another door.
    $this->get("/api/v1/me/leases/{$draft->id}/document", apiHeaders($tenant))->assertNotFound();
});

it('404s when the operator has uploaded nothing', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant);

    // Not an empty 200: that reads as a corrupt file rather than as "there is no document".
    $this->get("/api/v1/me/leases/{$lease->id}/document", apiHeaders($tenant))->assertNotFound();
});

it('sends a whole money figure as a JSON integer — the cast a Dart client must make', function () {
    $tenant = makeTenant();
    makeLease(makeUnit(makeAsset()), $tenant, [
        'base_rent_monthly' => 10000,      // whole
        'service_charge_monthly' => 2000.5, // not
    ]);

    $data = $this->getJson('/api/v1/me/leases', apiHeaders($tenant))->assertOk()->json('data.0');

    // Not a defect and not new — `json_encode(10000.0)` is `10000`, so PHP has been doing this on
    // every money field of this API since it shipped. It is pinned because it is invisible from the
    // PHP side and fatal on the client one: Dart's `json['baseRentMonthly'] as double` throws on an
    // int, and the fix is `(json['baseRentMonthly'] as num).toDouble()`.
    expect($data['baseRentMonthly'])->toBeInt()
        ->and($data['serviceChargeMonthly'])->toBeFloat();
});
