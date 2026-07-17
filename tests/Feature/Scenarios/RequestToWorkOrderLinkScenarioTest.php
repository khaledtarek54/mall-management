<?php

use App\Models\MaintenanceWorkOrder;
use App\Models\TenantRequest;
use App\Models\Vendor;
use App\Services\RaiseCorrectiveMaintenanceService;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * The module 11 → 26 seam: a tenant reports a fault (TenantRequest); staff raise a corrective
 * work order (MaintenanceWorkOrder) to fix it, linked back to the request.
 *
 * This link did not exist in either direction — the closest was `source_item_id` (a CM off a
 * failed PPM check, a different origin). It is a real gap on its own, and the precondition for
 * FR-USR-06's "a request may be completed with an uploaded image **or a linked work order**".
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->svc = app(RaiseCorrectiveMaintenanceService::class);
    $this->asset = makeAsset(['code' => 'LNK']);
    $this->unit = makeUnit($this->asset, ['code' => 'U-1']);
    $this->tenant = makeTenant();
    makeLease($this->unit, $this->tenant);
});

function reportedFault(array $attrs = []): TenantRequest
{
    return makeMaintenanceRequest(array_merge([
        'unit_id' => test()->unit->id,
        'tenant_id' => test()->tenant->id,
        'title' => 'AC leaking into the shop',
        'description' => 'Water pooling under the display units',
        'category' => 'hvac',
        'priority' => 'high',
    ], $attrs));
}

it('raises a work order that carries the fault\'s location and links back', function () {
    $request = reportedFault();

    $wo = $this->svc->fromTenantRequest($request, [
        'execution_type' => 'internal',
        'assigned_to_user_id' => makeUser('technician', [$this->asset->id])->id,
    ]);

    expect($wo->work_order_type)->toBe(MaintenanceWorkOrder::TYPE_CM);
    expect($wo->tenant_request_id)->toBe($request->id);
    // WHERE the work is comes from the request — facts about the fault, not the engineer's choices.
    expect($wo->asset_id)->toBe($this->asset->id);
    expect($wo->unit_id)->toBe($this->unit->id);
    expect($wo->category)->toBe('hvac');
    // …and its wording defaults to the tenant's own, so an engineer isn't retyping the complaint.
    expect($wo->title)->toBe('AC leaking into the shop');
    expect((string) $wo->priority)->toBe('high');
});

it('reads back from the request, both directions', function () {
    $request = reportedFault();
    $wo = $this->svc->fromTenantRequest($request, ['execution_type' => 'internal']);

    expect($request->fresh()->workOrders->pluck('id')->all())->toBe([$wo->id]);
    expect($request->fresh()->hasLinkedWorkOrder())->toBeTrue();
    expect($wo->tenantRequest->is($request))->toBeTrue();
});

it('lets one request spawn several work orders', function () {
    // A flood needs plumbing AND electrical — one ticket, two jobs.
    $request = reportedFault();
    $this->svc->fromTenantRequest($request, ['execution_type' => 'internal', 'title' => 'Stop the leak', 'category' => 'plumbing']);
    $this->svc->fromTenantRequest($request, ['execution_type' => 'internal', 'title' => 'Dry out the wiring', 'category' => 'electrical']);

    expect($request->fresh()->workOrders)->toHaveCount(2);
});

it('carries an explicit description, or falls back to the tenant\'s words', function () {
    $request = reportedFault();

    $explicit = $this->svc->fromTenantRequest($request, ['execution_type' => 'internal', 'description' => 'Replace the condensate pump.']);
    expect($explicit->description)->toBe('Replace the condensate pump.');

    $fallback = $this->svc->fromTenantRequest($request, ['execution_type' => 'internal']);
    expect($fallback->description)->toBe('Water pooling under the display units');
});

it('respects the internal/external XOR', function () {
    $request = reportedFault();

    $internal = $this->svc->fromTenantRequest($request, [
        'execution_type' => 'internal',
        'assigned_to_user_id' => makeUser('technician', [$this->asset->id])->id,
        'vendor_id' => Vendor::create(['name' => 'V', 'category' => 'hvac', 'status' => 'active'])->id, // supplied but must be dropped
    ]);
    expect($internal->vendor_id)->toBeNull();
    expect($internal->assigned_to_user_id)->not->toBeNull();
});

it('deleting the request nulls the link but keeps the work order', function () {
    // The facility work is a real event with its own cost + GL trail. Deleting the tenant's ticket
    // must not erase it — the link is provenance, not ownership (nullOnDelete).
    $request = reportedFault();
    $wo = $this->svc->fromTenantRequest($request, ['execution_type' => 'internal']);

    $request->forceDelete();

    $wo->refresh();
    expect($wo->exists)->toBeTrue();
    expect($wo->tenant_request_id)->toBeNull();
});

it('raises against the tenant\'s own property, never leaks to another', function () {
    // The work order's asset_id is read from the request's unit — so a job can only ever be raised
    // against the property the fault was reported on. (The no-unit guard in the service is
    // future-proofing for Phase 9, when staff-channel requests may have no unit; tenant_requests.
    // unit_id is NOT NULL today, so that state is unreachable and not contrived here.)
    $otherAsset = makeAsset(['code' => 'OTH']);
    $request = reportedFault();

    $wo = $this->svc->fromTenantRequest($request, ['execution_type' => 'internal']);

    expect($wo->asset_id)->toBe($this->asset->id);
    expect($wo->asset_id)->not->toBe($otherAsset->id);
});

it('coerces a blank priority field to the request\'s, never an empty enum', function () {
    // NOT-NULL coercion (the reachable risk): a cleared priority SELECT sends '' in $data. `??`
    // would write '' into the WO's priority enum; filled() falls back to the request's priority.
    $request = reportedFault(['priority' => 'high']);

    $wo = $this->svc->fromTenantRequest($request, ['execution_type' => 'internal', 'priority' => '']);

    expect((string) $wo->priority)->toBe('high');
});
