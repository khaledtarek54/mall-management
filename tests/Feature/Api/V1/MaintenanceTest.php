<?php

use App\Models\MaintenanceRequest;
use App\Models\Tenant;

function makeMaintenance(Tenant $tenant, array $attrs = []): MaintenanceRequest
{
    return MaintenanceRequest::create(array_merge([
        'reference' => MaintenanceRequest::generateReference(),
        'tenant_id' => $tenant->id,
        'unit_id' => makeUnit(makeAsset())->id,
        'status' => 'submitted',
        'priority' => 'medium',
        'category' => 'electrical',
        'title' => 'Flickering lights',
        'description' => 'The lights flicker in the evening.',
        'submitted_at' => now(),
        'target_resolution_at' => now()->addDays(3),
    ], $attrs));
}

it('lists the tenant\'s maintenance requests', function () {
    $tenant = makeTenant();
    makeMaintenance($tenant);
    makeMaintenance(makeTenant()); // foreign — excluded

    $response = $this->getJson('/api/v1/me/maintenance-requests', apiHeaders($tenant))->assertOk();

    expect($response->json('meta.total'))->toBe(1);
});

it('creates a maintenance request via the service path', function () {
    $tenant = makeTenant();
    makeLease(makeUnit(makeAsset()), $tenant); // active lease → resolves unit

    $this->postJson('/api/v1/me/maintenance-requests', [
        'title' => 'AC not cooling',
        'description' => 'The unit AC stopped cooling yesterday.',
        'category' => 'hvac',
        'priority' => 'high',
    ], apiHeaders($tenant))
        ->assertCreated()
        ->assertJsonPath('data.title', 'AC not cooling')
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.priority', 'high');

    $this->assertDatabaseHas('maintenance_requests', [
        'tenant_id' => $tenant->id, 'title' => 'AC not cooling', 'channel' => 'portal',
    ]);
});

it('validates the create payload', function () {
    $tenant = makeTenant();

    $this->postJson('/api/v1/me/maintenance-requests', [
        'category' => 'not-a-category',
    ], apiHeaders($tenant))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['title', 'description', 'category']);
});

it('shows a request and hides internal comments', function () {
    $tenant = makeTenant();
    $request = makeMaintenance($tenant);
    $request->comments()->create(['author_type' => $tenant->getMorphClass(), 'author_id' => $tenant->id, 'body' => 'Public note', 'is_internal' => false]);
    $request->comments()->create(['author_type' => $tenant->getMorphClass(), 'author_id' => $tenant->id, 'body' => 'Secret', 'is_internal' => true]);

    $response = $this->getJson("/api/v1/me/maintenance-requests/{$request->id}", apiHeaders($tenant))->assertOk();

    expect($response->json('data.comments'))->toHaveCount(1);
    expect($response->json('data.comments.0.body'))->toBe('Public note');
});

it('adds a public comment', function () {
    $tenant = makeTenant();
    $request = makeMaintenance($tenant);

    $this->postJson("/api/v1/me/maintenance-requests/{$request->id}/comments", [
        'body' => 'Any update?',
    ], apiHeaders($tenant))->assertCreated();

    $this->assertDatabaseHas('maintenance_request_comments', [
        'maintenance_request_id' => $request->id, 'body' => 'Any update?', 'is_internal' => false,
    ]);
});

it('cancels a not-yet-started request', function () {
    $tenant = makeTenant();
    $request = makeMaintenance($tenant, ['status' => 'acknowledged']);

    $this->postJson("/api/v1/me/maintenance-requests/{$request->id}/cancel", [], apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

it('refuses to cancel a request that is already in progress', function () {
    $tenant = makeTenant();
    $request = makeMaintenance($tenant, ['status' => 'in_progress']);

    $this->postJson("/api/v1/me/maintenance-requests/{$request->id}/cancel", [], apiHeaders($tenant))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    expect($request->fresh()->status)->toBe('in_progress');
});

it('returns 404 for another tenant\'s request', function () {
    $tenant = makeTenant();
    $request = makeMaintenance(makeTenant());

    $this->getJson("/api/v1/me/maintenance-requests/{$request->id}", apiHeaders($tenant))->assertNotFound();
});
