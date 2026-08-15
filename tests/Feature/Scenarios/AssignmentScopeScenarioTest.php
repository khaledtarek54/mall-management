<?php

use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Filament\Admin\Resources\FacilityWorkOrders\FacilityWorkOrderResource;
use App\Models\FacilityWorkOrder;
use App\Support\AssignmentScope;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * FR-USR-04 — "Every user shall see only the requests/work orders assigned to them, **filtered by
 * role and assignment**."
 *
 * "Every user" is not literal, and the FRD's own role table says so: an Admin has "full access for
 * their assigned mall", a Coordinator "manages assignment and oversight", and an **In-house
 * Technician** "sees only work assigned to them". The role decides whether the filter applies —
 * a coordinator who could only see their own work could not assign anything.
 *
 * This is the system's SECOND scoping primitive. `TenantScope` answers "which properties"; this
 * answers "which rows within them". Both apply, independently.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'ASG']);
    $this->tech = makeUser('technician', [$this->asset->id]);
    $this->otherTech = makeUser('technician', [$this->asset->id]);
    $this->coordinator = makeUser('operations', [$this->asset->id]);
});

function assignableWorkOrder(array $attrs = []): FacilityWorkOrder
{
    return FacilityWorkOrder::create(array_merge([
        'asset_id' => test()->asset->id, 'work_order_type' => 'cm', 'execution_type' => 'internal',
        'description' => 'd', 'title' => 'Fix it', 'category' => 'plumbing', 'scheduled_for' => '2026-07-01',
    ], $attrs));
}

function visibleWorkOrderIds(): array
{
    return FacilityWorkOrderResource::getEloquentQuery()->pluck('id')->sort()->values()->all();
}

/* ---- the requirement ---------------------------------------------------- */

it('shows a technician only the work orders assigned to them', function () {
    $mine = assignableWorkOrder(['assigned_to_user_id' => $this->tech->id]);
    $theirs = assignableWorkOrder(['assigned_to_user_id' => $this->otherTech->id]);

    $this->actingAs($this->tech);

    expect(visibleWorkOrderIds())->toBe([$mine->id]);
    expect(visibleWorkOrderIds())->not->toContain($theirs->id);
});

it('hides unassigned work from a technician', function () {
    // "Sees only work assigned to them" — a job nobody has handed out is not theirs; it is the
    // coordinator's to assign.
    assignableWorkOrder(['assigned_to_user_id' => null]);
    $this->actingAs($this->tech);

    expect(visibleWorkOrderIds())->toBe([]);
});

it('shows a coordinator everything, because dispatch is oversight', function () {
    // You cannot assign work you cannot see. This is the half of FR-USR-04 that a literal reading
    // ("EVERY user sees only their own") would break.
    $a = assignableWorkOrder(['assigned_to_user_id' => $this->tech->id]);
    $b = assignableWorkOrder(['assigned_to_user_id' => null]);

    $this->actingAs($this->coordinator);

    expect(visibleWorkOrderIds())->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});

it('does not narrow the read-only oversight roles', function () {
    // REGRESSION: `view_all` does not end in `.view`, and viewer/owner are granted by exactly that
    // suffix match — so adding the permission silently restricted them to "work assigned to me",
    // which for an auditor is an empty screen. Caught by checking rather than assuming.
    $workOrder = assignableWorkOrder(['assigned_to_user_id' => $this->tech->id]);

    foreach (['viewer', 'owner', 'manager', 'super_admin'] as $role) {
        $this->actingAs(makeUser($role, [$this->asset->id]));
        // NB: toContain() is variadic — a second argument is another EXPECTED VALUE, not a
        // message. Passing one here silently asserted the array contained the message string.
        expect(in_array($workOrder->id, visibleWorkOrderIds(), true))
            ->toBeTrue("{$role} must not be narrowed to its own assignments");
    }
});

/* ---- it is a constraint, not a filter ----------------------------------- */

it('hides another technician\'s work order from the record page, not just the list', function () {
    // The point of doing this in getEloquentQuery(): Filament resolves a record through the same
    // query, so guessing a URL 404s instead of handing over somebody else's job. A table filter
    // would have protected the list and nothing else.
    $theirs = assignableWorkOrder(['assigned_to_user_id' => $this->otherTech->id]);
    $this->actingAs($this->tech);

    expect(FacilityWorkOrderResource::getEloquentQuery()->find($theirs->id))->toBeNull();
});

/* ---- it composes with property scoping ---------------------------------- */

it('still hides a job in a property the technician cannot see', function () {
    // Both scopes apply, independently. Being ASSIGNED a job does not grant access to the mall it
    // sits in — assignment scoping narrows, it never widens.
    //
    // This must go through `scopedResourceQuery()` rather than `getEloquentQuery()` alone: property
    // scoping lives in `scopeEloquentQueryToTenant()`, which Filament only calls when a tenant is
    // set. Asserting it off the bare query would have proved nothing about the composition.
    $otherAsset = makeAsset(['code' => 'OTH']);
    $foreign = FacilityWorkOrder::create([
        'asset_id' => $otherAsset->id, 'work_order_type' => 'cm', 'execution_type' => 'internal',
        'description' => 'd', 'title' => 'Other mall', 'category' => 'plumbing',
        'scheduled_for' => '2026-07-01', 'assigned_to_user_id' => $this->tech->id, // assigned to them!
    ]);
    $mine = assignableWorkOrder(['assigned_to_user_id' => $this->tech->id]);

    $this->actingAs($this->tech); // scoped to $this->asset only

    $visible = asTenant($this->asset, fn () => scopedResourceQuery(FacilityWorkOrderResource::class)->pluck('id')->all());

    expect($visible)->toContain($mine->id);          // their own job, in their own mall
    expect($visible)->not->toContain($foreign->id);  // their own job, in a mall they cannot see
});

/* ---- the same rule on the other surface --------------------------------- */

it('applies to tenant requests too, on their differently-named column', function () {
    // FR-USR-04 says "requests/work orders". tenant_requests uses `assigned_to`;
    // facility_work_orders uses `assigned_to_user_id`. One primitive, two columns — which is
    // why the rule is not copied into each resource.
    // On the technician's OWN property — makeTenantRequest() otherwise builds its own asset,
    // and property scoping would then hide the request for the right reason but the wrong one,
    // proving nothing about assignment.
    $unit = makeUnit($this->asset, ['code' => 'U-ASG']);
    $tenant = makeTenant();
    $mine = makeTenantRequest(['unit_id' => $unit->id, 'tenant_id' => $tenant->id, 'assigned_to' => $this->tech->id]);
    $theirs = makeTenantRequest(['unit_id' => $unit->id, 'tenant_id' => $tenant->id, 'assigned_to' => $this->otherTech->id]);

    $this->actingAs($this->tech);
    $visible = TenantRequestResource::getEloquentQuery()->pluck('id')->all();

    expect($visible)->toContain($mine->id);
    expect($visible)->not->toContain($theirs->id);
});

/* ---- the primitive itself ----------------------------------------------- */

it('fails closed for a user with nothing, and for nobody at all', function () {
    // A user with no permissions, or an unauthenticated request, must see their own work (nothing)
    // rather than everyone's.
    expect(AssignmentScope::isRestricted(null, 'facility'))->toBeTrue();

    $nobody = makeUser('technician', [$this->asset->id]);
    $nobody->syncPermissions([]);
    expect(AssignmentScope::isRestricted($nobody->fresh(), 'facility'))->toBeTrue();
});

it('leaves the query untouched for someone who oversees the module', function () {
    expect(AssignmentScope::isRestricted($this->coordinator, 'facility'))->toBeFalse();
    expect(AssignmentScope::isRestricted($this->tech, 'facility'))->toBeTrue();
});

/* ---- the FRD's named roles: coordinator + customer service -------------- */

it('the coordinator role oversees the whole board and may assign', function () {
    // Now a real seeded role (the beforeEach `$this->coordinator` is an `operations` stand-in
    // that predates it). "Manages assignment and oversight" = holds `*.view_all` (not restricted)
    // AND `requests.assign` — the authority the technician deliberately lacks.
    $coordinator = makeUser('coordinator', [$this->asset->id]);

    expect(AssignmentScope::isRestricted($coordinator, 'requests'))->toBeFalse()
        ->and(AssignmentScope::isRestricted($coordinator, 'facility'))->toBeFalse()
        ->and($coordinator->can('requests.assign'))->toBeTrue()
        ->and($coordinator->can('requests.change_status'))->toBeTrue();

    // Sees every work order — assigned to anyone or to no one — so it can hand them out.
    $a = assignableWorkOrder(['assigned_to_user_id' => $this->tech->id]);
    $b = assignableWorkOrder(['assigned_to_user_id' => null]);
    $this->actingAs($coordinator);
    expect(visibleWorkOrderIds())->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});

it('customer service fields any call but has no work authority', function () {
    // Intake desk: sees EVERY request (`view_all`, so it can answer "what's the status of mine?")
    // but may only LOG one — no assign, no status moves, no completing a work order.
    $cs = makeUser('customer_service', [$this->asset->id]);

    expect(AssignmentScope::isRestricted($cs, 'requests'))->toBeFalse()
        ->and($cs->can('requests.create'))->toBeTrue()
        ->and($cs->can('requests.assign'))->toBeFalse()
        ->and($cs->can('requests.change_status'))->toBeFalse()
        ->and($cs->can('facility.complete'))->toBeFalse();
});
