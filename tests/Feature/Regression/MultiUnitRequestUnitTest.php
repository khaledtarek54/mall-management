<?php

/*
|--------------------------------------------------------------------------
| A tenant can report a fault in ANY unit they lease — and only those
|--------------------------------------------------------------------------
| A multi-unit lease keeps its additional units in the `lease_unit` pivot and only the MASTER in
| `leases.unit_id`. Every tenant-facing path looked at the column alone, so a tenant's own extra
| units were unreachable:
|
|   - mobile API  → 422 "the selected unit id is invalid" on their own shop
|   - portal      → the unit was not even in the dropdown, and had a crafted submit reached the
|                   service it would have fallen through to `activeLeases()->first()` and filed the
|                   request against the WRONG unit — wrong crew, wrong area supervisors, and the
|                   tenant sees someone else's unit code on their own request
|
| Real case that surfaced it: Cilantro leases A-01 + C-09 and could only report faults for A-01.
|
| The second half of this file matters as much as the first: widening a clamp is exactly when a
| cross-tenant hole gets introduced, so the "another tenant's unit" cases are asserted here too.
*/

use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Services\TenantRequestService;
use Laravel\Sanctum\Sanctum;

/** A tenant on one lease spanning two units — the shape the column-only lookup could not express. */
function multiUnitTenant(): array
{
    $asset = makeAsset();
    $master = makeUnit($asset, ['code' => 'M-'.uniqid()]);
    $extra = makeUnit($asset, ['code' => 'X-'.uniqid()]);
    $tenant = makeTenant();

    $lease = makeLease($master, $tenant, ['status' => 'active']);
    $lease->units()->syncWithoutDetaching([$extra->id]);

    return [$tenant, $master, $extra, $asset];
}

function postRequest(int $unitId): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/me/requests', [
        'type' => 'maintenance',
        'category' => 'electrical',
        'title' => 'Ceiling light out',
        'description' => 'The ceiling light has stopped working entirely.',
        'unit_id' => $unitId,
    ]);
}

it('accepts a request against an additional unit of the tenants own lease', function () {
    [$tenant, $master, $extra] = multiUnitTenant();
    Sanctum::actingAs($tenant, ['*'], 'tenant-api');

    expect(postRequest($master->id)->status())->toBe(201)
        ->and(postRequest($extra->id)->status())->toBe(201, 'a tenant must be able to report a fault in their own second unit');
});

it('files the request against the unit the tenant named, not the leases master', function () {
    // The portal bug. Before the fix the extra unit resolved to no lease, fell through to the
    // tenant's first active lease, and took THAT lease's master unit — so a fault in the second
    // shop arrived labelled as the first.
    [$tenant, $master, $extra] = multiUnitTenant();

    $request = app(TenantRequestService::class)->create([
        'request_type' => 'maintenance',
        'category' => 'electrical',
        'title' => 'Ceiling light out',
        'description' => 'The ceiling light has stopped working entirely.',
        'unit_id' => $extra->id,
    ], $tenant);

    expect($request->unit_id)->toBe($extra->id, 'the request must name the unit the tenant reported')
        ->and($request->unit_id)->not->toBe($master->id);
});

it('still refuses another tenants unit over the API', function () {
    // Widening the clamp is where a cross-tenant hole would be introduced.
    [$tenant] = multiUnitTenant();
    [, $otherMaster, $otherExtra] = multiUnitTenant();

    Sanctum::actingAs($tenant, ['*'], 'tenant-api');

    expect(postRequest($otherMaster->id)->status())->toBe(422)
        ->and(postRequest($otherExtra->id)->status())->toBe(422, 'another tenant\'s ADDITIONAL unit must be refused too');
});

it('never files against another tenants unit even when validation is bypassed', function () {
    // The service is the choke point for the portal, which has no server-side rule of its own —
    // a crafted Livewire submit reaches this directly. It must clamp, not trust.
    [$tenant, $master] = multiUnitTenant();
    [, , $foreignExtra] = multiUnitTenant();

    $request = app(TenantRequestService::class)->create([
        'request_type' => 'maintenance',
        'category' => 'electrical',
        'title' => 'Ceiling light out',
        'description' => 'The ceiling light has stopped working entirely.',
        'unit_id' => $foreignExtra->id,          // not this tenant's
    ], $tenant);

    expect($request->unit_id)->toBe($master->id, 'a foreign unit must collapse to the tenant\'s own lease, never persist')
        ->and($request->unit_id)->not->toBe($foreignExtra->id);
});

it('lists every leased unit in the portal picker, not just masters', function () {
    // The dropdown is what makes the fix reachable for a portal user; the service fix alone
    // would leave the extra unit unselectable.
    [$tenant, $master, $extra] = multiUnitTenant();

    $options = $tenant->leases()
        ->with('units')
        ->get()
        ->flatMap(fn ($lease) => $lease->units)
        ->unique('id')
        ->pluck('code', 'id')
        ->all();

    expect(array_keys($options))->toContain($master->id)
        ->and(array_keys($options))->toContain($extra->id);
});

it('leaves a single-unit lease resolving exactly as before', function () {
    // The common case must not move. Omitting unit_id still derives from the active lease.
    $unit = makeUnit(makeAsset());
    $tenant = makeTenant();
    makeLease($unit, $tenant, ['status' => 'active']);

    $request = app(TenantRequestService::class)->create([
        'request_type' => 'maintenance',
        'category' => 'electrical',
        'title' => 'Ceiling light out',
        'description' => 'The ceiling light has stopped working entirely.',
    ], $tenant);

    expect($request->unit_id)->toBe($unit->id)
        ->and($request)->toBeInstanceOf(TenantRequest::class);
});
