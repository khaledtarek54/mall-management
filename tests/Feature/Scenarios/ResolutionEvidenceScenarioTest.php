<?php

use App\Models\TenantRequest;
use App\Services\RaiseCorrectiveMaintenanceService;
use App\Services\TenantRequestService;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * FR-USR-06 (request side) — "require evidence (an uploaded image OR a linked work order) before a
 * request ... can be marked complete."
 *
 * Both are proof the work happened: a photo of the fix, or the facility work order raised to do it
 * (the module 11 → 26 link). Enforced in `TenantRequestService::transition()` so admin, the tenant
 * portal and the mobile API all inherit it — a rule enforced only in one UI is a rule the other
 * channels skip.
 *
 * The work-order side of FR-USR-06 (does completing a WO also need a photo, on top of its checklist
 * gate?) is a separate, unconfirmed scope decision — see docs/CLIENT-QUESTIONS.md.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->svc = app(TenantRequestService::class);
    $this->asset = makeAsset(['code' => 'EVD']);
    $this->unit = makeUnit($this->asset, ['code' => 'U-1']);
    $this->tenant = makeTenant();
    makeLease($this->unit, $this->tenant);
});

function inProgressRequest(): TenantRequest
{
    return makeTenantRequest([
        'unit_id' => test()->unit->id, 'tenant_id' => test()->tenant->id,
        'status' => 'in_progress', 'title' => 'AC down', 'description' => 'No cooling',
    ]);
}

it('refuses to resolve a request with no evidence at all', function () {
    $request = inProgressRequest();

    expect(fn () => $this->svc->transition($request, 'resolved', ['resolution_notes' => 'Fixed']))
        ->toThrow(DomainException::class);
    expect($request->fresh()->status)->toBe('in_progress'); // and nothing changed
});

it('resolves once a photo is attached', function () {
    $request = inProgressRequest();
    $request->addMediaFromString('proof')->usingFileName('done.jpg')->toMediaCollection('attachments');

    $resolved = $this->svc->transition($request, 'resolved', ['resolution_notes' => 'Fixed']);

    expect($resolved->status)->toBe('resolved');
    expect($resolved->resolved_at)->not->toBeNull();
});

it('resolves once a work order is raised for it, with no photo', function () {
    // The "linked work order" half — the whole reason the module 11 → 26 link was built first.
    $request = inProgressRequest();
    expect($request->hasMedia('attachments'))->toBeFalse(); // no photo…

    app(RaiseCorrectiveMaintenanceService::class)->fromTenantRequest($request, ['execution_type' => 'internal']);

    expect($this->svc->transition($request->fresh(), 'resolved')->status)->toBe('resolved'); // …but a linked WO
});

it('lets a resolved request be closed without re-proving evidence', function () {
    // The gate is on RESOLVING (the act of saying "done"), not on the administrative close that
    // follows. A resolved request already cleared it.
    $request = inProgressRequest();
    $request->addMediaFromString('proof')->usingFileName('done.jpg')->toMediaCollection('attachments');
    $this->svc->transition($request, 'resolved');

    expect($this->svc->transition($request->fresh(), 'closed')->status)->toBe('closed');
});

it('does not gate the other transitions', function () {
    // Only resolution needs evidence. Acknowledging or starting work must not.
    $request = makeTenantRequest([
        'unit_id' => $this->unit->id, 'tenant_id' => $this->tenant->id, 'status' => 'submitted',
    ]);

    expect($this->svc->transition($request, 'acknowledged')->status)->toBe('acknowledged');
    expect($this->svc->transition($request->fresh(), 'in_progress')->status)->toBe('in_progress');
});

it('applies the same gate whether the linked work order is open or done', function () {
    // The evidence is that facility work exists on record — its status is irrelevant to whether the
    // tenant's request may be called resolved.
    $request = inProgressRequest();
    $wo = app(RaiseCorrectiveMaintenanceService::class)->fromTenantRequest($request, ['execution_type' => 'internal']);
    $wo->update(['status' => 'done', 'completed_at' => now()]);

    expect($this->svc->transition($request->fresh(), 'resolved')->status)->toBe('resolved');
});
