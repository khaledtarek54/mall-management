<?php

use App\Models\Floor;

/**
 * `unit.floor` must reach the mobile client as a STRING, on every endpoint that carries it.
 *
 * It was a plain `units.floor` string column until the Floor register replaced it. Nothing in the
 * API changed — and that was the bug: `$unit->floor` stopped being a column and became a RELATION,
 * so three endpoints (lease, tenant request, invoice) silently began serialising a whole `Floor`
 * object where the Dart client expects a scalar. Nothing failed here, because no test asserted the
 * TYPE of anything on the wire — only presence and values.
 *
 * That is the trap this project has already recorded once: a mobile contract breaks by shape, not by
 * status code, and a green suite proves nothing about either. These assert the type.
 */
function floorFixture(): array
{
    $asset = makeAsset();
    $floor = Floor::create(['asset_id' => $asset->id, 'code' => 'G', 'name' => 'Ground floor', 'level' => 0]);
    $tenant = makeTenant();
    $lease = makeLease(makeUnit($asset, ['floor_id' => $floor->id]), $tenant, ['status' => 'active']);

    return [$tenant, $lease];
}

it('sends the floor as a string on the lease endpoint', function () {
    [$tenant, $lease] = floorFixture();

    $floor = $this->getJson('/api/v1/me/leases', apiHeaders($tenant))
        ->assertOk()
        ->json('data.0.unit.floor');

    // The code, not the Floor model — and a string, not an array.
    expect($floor)->toBeString()->toBe('G');
});

it('sends the floor as a string on the invoice endpoint', function () {
    [$tenant, $lease] = floorFixture();
    makeInvoice($lease);

    $floor = $this->getJson('/api/v1/me/invoices', apiHeaders($tenant))
        ->assertOk()
        ->json('data.0.lease.unit.floor');

    expect($floor)->toBeString()->toBe('G');
});

it('sends null rather than an object when a unit has no floor', function () {
    // The other half of the contract: a nullable scalar, never an empty object. A client that
    // type-checks `String?` throws on `{}` just as surely as on a populated object.
    $tenant = makeTenant();
    makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);

    $floor = $this->getJson('/api/v1/me/leases', apiHeaders($tenant))
        ->assertOk()
        ->json('data.0.unit.floor');

    expect($floor)->toBeNull();
});
