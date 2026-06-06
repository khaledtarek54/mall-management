<?php

use App\Models\MaintenanceRequest;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

it('creates a request with image + PDF attachments', function () {
    Storage::fake('public');
    $tenant = makeTenant();
    makeLease(makeUnit(makeAsset()), $tenant);

    // Multipart upload (post, not postJson) so the files reach the request.
    $response = $this->post('/api/v1/me/maintenance-requests', [
        'title' => 'Leaking pipe',
        'description' => 'Water under the sink.',
        'category' => 'plumbing',
        'attachments' => [
            UploadedFile::fake()->image('damage.jpg'),
            UploadedFile::fake()->create('report.pdf', 100, 'application/pdf'),
        ],
    ], array_merge(apiHeaders($tenant), ['Accept' => 'application/json']))
        ->assertCreated();

    // URLs come back in the 201 so the app can render them without a re-fetch.
    expect($response->json('data.attachments'))->toHaveCount(2);

    $request = MaintenanceRequest::firstWhere('title', 'Leaking pipe');
    expect($request->getMedia('attachments'))->toHaveCount(2);
});

it('rejects a non image/PDF attachment', function () {
    Storage::fake('public');
    $tenant = makeTenant();
    makeLease(makeUnit(makeAsset()), $tenant);

    $this->post('/api/v1/me/maintenance-requests', [
        'title' => 'Broken AC',
        'description' => 'See the clip.',
        'category' => 'hvac',
        'attachments' => [UploadedFile::fake()->create('clip.mp4', 200, 'video/mp4')],
    ], array_merge(apiHeaders($tenant), ['Accept' => 'application/json']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['attachments.0']);
});

it('rejects more than five attachments', function () {
    Storage::fake('public');
    $tenant = makeTenant();
    makeLease(makeUnit(makeAsset()), $tenant);

    $this->post('/api/v1/me/maintenance-requests', [
        'title' => 'Too many',
        'description' => 'Six photos.',
        'category' => 'other',
        'attachments' => array_map(
            fn ($i) => UploadedFile::fake()->image("photo{$i}.jpg"),
            range(1, 6),
        ),
    ], array_merge(apiHeaders($tenant), ['Accept' => 'application/json']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['attachments']);
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

it('syncs attachment URLs to the app in the show + list responses', function () {
    Storage::fake('public');
    $tenant = makeTenant();
    $request = makeMaintenance($tenant);
    $request->addMedia(UploadedFile::fake()->image('damage.jpg'))->toMediaCollection('attachments');

    // Response keys are camelCased by the CamelCaseResponseKeys middleware to
    // match the Flutter app (mime_type → mimeType).
    $show = $this->getJson("/api/v1/me/maintenance-requests/{$request->id}", apiHeaders($tenant))->assertOk();
    expect($show->json('data.attachments'))->toHaveCount(1);
    expect($show->json('data.attachments.0'))->toHaveKeys(['id', 'name', 'mimeType', 'size', 'url']);
    expect($show->json('data.attachments.0.name'))->toContain('damage');
    expect($show->json('data.attachments.0.url'))->toContain('damage');

    $list = $this->getJson('/api/v1/me/maintenance-requests', apiHeaders($tenant))->assertOk();
    expect($list->json('data.0.attachments'))->toHaveCount(1);
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
