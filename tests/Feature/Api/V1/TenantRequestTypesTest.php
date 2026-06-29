<?php

use App\Models\Department;

/**
 * Plan 1: a tenant can raise any request type from the mobile app — not just
 * maintenance. The /me/maintenance-requests endpoint now accepts request_type
 * and validates the sub-category per type, while staying backward-compatible
 * with app builds that only send a maintenance category.
 */
it('creates a non-maintenance request with no sub-category and no SLA', function () {
    $tenant = makeTenant();
    makeLease(makeUnit(makeAsset()), $tenant);

    $this->postJson('/api/v1/me/maintenance-requests', [
        'request_type' => 'inquiry',
        'title' => 'What are the mall opening hours during Eid?',
        'description' => 'Please confirm the holiday trading hours.',
    ], apiHeaders($tenant))
        ->assertCreated()
        ->assertJsonPath('data.requestType', 'inquiry')
        ->assertJsonPath('data.category', null)
        ->assertJsonPath('data.targetResolutionAt', null);

    $this->assertDatabaseHas('tenant_requests', [
        'tenant_id' => $tenant->id,
        'request_type' => 'inquiry',
    ]);
});

it('creates a complaint with a valid sub-category and a CR reference', function () {
    $tenant = makeTenant();
    makeLease(makeUnit(makeAsset()), $tenant);

    $response = $this->postJson('/api/v1/me/maintenance-requests', [
        'request_type' => 'complaint',
        'title' => 'Loud music next door',
        'description' => 'The neighbouring unit plays loud music after hours.',
        'category' => 'noise',
    ], apiHeaders($tenant))
        ->assertCreated()
        ->assertJsonPath('data.requestType', 'complaint')
        ->assertJsonPath('data.category', 'noise');

    expect($response->json('data.reference'))->toStartWith('CR-');
});

it('rejects a sub-category that does not belong to the chosen type', function () {
    $tenant = makeTenant();
    makeLease(makeUnit(makeAsset()), $tenant);

    // 'electrical' is a maintenance sub-category, not a complaint one.
    $this->postJson('/api/v1/me/maintenance-requests', [
        'request_type' => 'complaint',
        'title' => 'Mislabelled',
        'description' => 'Should fail validation.',
        'category' => 'electrical',
    ], apiHeaders($tenant))
        ->assertStatus(422)
        ->assertJsonValidationErrors('category');
});

it('defaults to a maintenance request when request_type is omitted (back-compat)', function () {
    $tenant = makeTenant();
    makeLease(makeUnit(makeAsset()), $tenant);

    $response = $this->postJson('/api/v1/me/maintenance-requests', [
        'title' => 'AC not cooling',
        'description' => 'Stopped cooling yesterday.',
        'category' => 'hvac',
    ], apiHeaders($tenant))
        ->assertCreated()
        ->assertJsonPath('data.requestType', 'maintenance');

    expect($response->json('data.reference'))->toStartWith('MR-');
});

it('auto-routes a request to its type default department', function () {
    $accounting = Department::create(['name' => 'Accounting', 'slug' => 'accounting', 'is_active' => true]);
    $tenant = makeTenant();
    makeLease(makeUnit(makeAsset()), $tenant);

    $this->postJson('/api/v1/me/maintenance-requests', [
        'request_type' => 'billing',
        'title' => 'Explain this service charge',
        'description' => 'The latest invoice has a charge I do not recognise.',
    ], apiHeaders($tenant))->assertCreated();

    $this->assertDatabaseHas('tenant_requests', [
        'tenant_id' => $tenant->id,
        'request_type' => 'billing',
        'department_id' => $accounting->id,
    ]);
});
