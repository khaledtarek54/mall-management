<?php

/*
|--------------------------------------------------------------------------
| The confirmation control existed only on the desktop portal (2026-08-20)
|--------------------------------------------------------------------------
| Found in the step-4 review. `/api/v1` and the portal are the SAME SURFACE with different
| renderers — CLAUDE.md states it as "fix both or neither" — and step 4 shipped confirm/dispute to
| the portal alone. It matters beyond consistency: the mobile app is where a shop manager actually
| is, so a control that lives only on a desktop screen is one most tenants would never use.
|
| The rules are the service's, so these endpoints and the portal refuse identically.
*/

use App\Models\Tenant;
use App\Models\TenantRequest;

function makeConfirmableRequest(Tenant $tenant, string $status = 'resolved'): TenantRequest
{
    return TenantRequest::create([
        'reference' => TenantRequest::generateReference(),
        'tenant_id' => $tenant->id,
        'unit_id' => makeUnit(makeAsset())->id,
        'request_type' => 'maintenance',
        'status' => $status,
        'priority' => 'high',
        'category' => 'plumbing',
        'title' => 'Blocked drain',
        'description' => 'Water backing up in the kitchen.',
        'resolution_notes' => 'Drain rodded and flushed.',
        'submitted_at' => now()->subDays(2),
        'resolved_at' => now(),
    ]);
}

it('closes the request when the tenant confirms it', function () {
    $tenant = makeTenant();
    $request = makeConfirmableRequest($tenant);

    $this->postJson("/api/v1/me/requests/{$request->id}/confirm", [], apiHeaders($tenant))
        ->assertOk();

    expect($request->fresh()->status)->toBe('closed')
        ->and($request->fresh()->confirmedByTenant())->toBeTrue();
});

it('sends a disputed request back to the operator', function () {
    $tenant = makeTenant();
    $request = makeConfirmableRequest($tenant);

    $this->postJson("/api/v1/me/requests/{$request->id}/dispute", [
        'reason' => 'It flooded again the next morning.',
    ], apiHeaders($tenant))->assertOk();

    expect($request->fresh()->status)->toBe('in_progress')
        ->and($request->comments()->latest('id')->value('body'))->toContain('flooded again');
});

/** "Not fixed" alone sends an engineer back knowing no more than the first time. */
it('refuses a dispute with no reason', function () {
    $tenant = makeTenant();
    $request = makeConfirmableRequest($tenant);

    $this->postJson("/api/v1/me/requests/{$request->id}/dispute", [], apiHeaders($tenant))
        ->assertStatus(422);

    expect($request->fresh()->status)->toBe('resolved');
});

/**
 * The same refusal as the portal, from the same service — confirming is a control BEFORE closure,
 * so a request that is already closed is past the point of it.
 */
it('refuses to confirm a request that is not resolved', function () {
    $tenant = makeTenant();
    $request = makeConfirmableRequest($tenant, 'closed');

    $this->postJson("/api/v1/me/requests/{$request->id}/confirm", [], apiHeaders($tenant))
        ->assertStatus(422);
});

/**
 * Cross-tenant isolation: a foreign id 404s rather than 403s, so the API never confirms that
 * somebody else's request exists.
 */
it('never reaches another tenant\'s request', function () {
    $mine = makeTenant();
    $theirs = makeConfirmableRequest(makeTenant());

    $this->postJson("/api/v1/me/requests/{$theirs->id}/confirm", [], apiHeaders($mine))
        ->assertNotFound();

    $this->postJson("/api/v1/me/requests/{$theirs->id}/dispute", [
        'reason' => 'Trying it on.',
    ], apiHeaders($mine))->assertNotFound();

    expect($theirs->fresh()->status)->toBe('resolved');
});

/**
 * The app decides whether to show the confirm / "not fixed" prompt from `canConfirm`, exactly as it
 * shows the cancel button from `canCancel`. If the flag stops mirroring the server's guard the
 * prompt silently stops appearing — and nothing else would fail.
 *
 * Narrower than `canRate` on purpose: rating stays open on a closed request (feedback after the
 * fact), confirming does not (a control before closure).
 */
it('tells the app when the confirm prompt should appear', function () {
    $tenant = makeTenant();
    $resolved = makeConfirmableRequest($tenant);
    $closed = makeConfirmableRequest($tenant, 'closed');

    $flags = collect($this->getJson('/api/v1/me/requests', apiHeaders($tenant))->json('data'))
        ->keyBy('id');

    expect($flags[$resolved->id]['canConfirm'])->toBeTrue()
        ->and($flags[$resolved->id]['canRate'])->toBeTrue()
        // Closed: still rateable, no longer confirmable.
        ->and($flags[$closed->id]['canConfirm'])->toBeFalse()
        ->and($flags[$closed->id]['canRate'])->toBeTrue();
});
