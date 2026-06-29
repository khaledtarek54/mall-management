<?php

use App\Models\MaintenanceRequest;
use App\Models\Tenant;

/**
 * CSAT: a tenant rates their resolved/closed request (1–5 + optional comment).
 * Scoped to the caller; only rateable once resolved/closed.
 */
function makeRateableRequest(Tenant $tenant, string $status = 'resolved'): MaintenanceRequest
{
    return MaintenanceRequest::create([
        'reference' => MaintenanceRequest::generateReference(),
        'tenant_id' => $tenant->id,
        'unit_id' => makeUnit(makeAsset())->id,
        'request_type' => 'maintenance',
        'status' => $status,
        'priority' => 'medium',
        'category' => 'electrical',
        'title' => 'Fixed leak',
        'description' => 'The leak under the sink was repaired.',
        'submitted_at' => now()->subDays(2),
        'resolved_at' => now(),
    ]);
}

it('rates a resolved request', function () {
    $tenant = makeTenant();
    $request = makeRateableRequest($tenant);

    $this->postJson("/api/v1/me/maintenance-requests/{$request->id}/rate", [
        'rating' => 5,
        'comment' => 'Fast and tidy, thank you.',
    ], apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.csatRating', 5)
        ->assertJsonPath('data.csatComment', 'Fast and tidy, thank you.');

    $this->assertDatabaseHas('maintenance_requests', [
        'id' => $request->id, 'csat_rating' => 5,
    ]);
});

it('refuses to rate a request that is not yet resolved', function () {
    $tenant = makeTenant();
    $request = makeRateableRequest($tenant, 'in_progress');

    $this->postJson("/api/v1/me/maintenance-requests/{$request->id}/rate", [
        'rating' => 4,
    ], apiHeaders($tenant))->assertStatus(422);

    expect($request->fresh()->csat_rating)->toBeNull();
});

it('validates the rating is within 1–5', function () {
    $tenant = makeTenant();
    $request = makeRateableRequest($tenant);

    $this->postJson("/api/v1/me/maintenance-requests/{$request->id}/rate", [
        'rating' => 6,
    ], apiHeaders($tenant))
        ->assertStatus(422)
        ->assertJsonValidationErrors('rating');
});

it('404s when rating another tenant\'s request (no cross-tenant access)', function () {
    $tenant = makeTenant();
    $foreign = makeRateableRequest(makeTenant());

    $this->postJson("/api/v1/me/maintenance-requests/{$foreign->id}/rate", [
        'rating' => 5,
    ], apiHeaders($tenant))->assertNotFound();
});

it('lets the tenant overwrite an earlier rating', function () {
    $tenant = makeTenant();
    $request = makeRateableRequest($tenant);

    $this->postJson("/api/v1/me/maintenance-requests/{$request->id}/rate", ['rating' => 2], apiHeaders($tenant))->assertOk();
    $this->postJson("/api/v1/me/maintenance-requests/{$request->id}/rate", ['rating' => 4], apiHeaders($tenant))->assertOk();

    expect($request->fresh()->csat_rating)->toBe(4);
});
