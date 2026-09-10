<?php

use App\Models\Tenant;
use App\Models\TenantRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function makeMaintenance(Tenant $tenant, array $attrs = []): TenantRequest
{
    return TenantRequest::create(array_merge([
        'reference' => TenantRequest::generateReference(),
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

    $response = $this->getJson('/api/v1/me/requests', apiHeaders($tenant))->assertOk();

    expect($response->json('meta.total'))->toBe(1);
});

it('creates a maintenance request via the service path', function () {
    $tenant = makeTenant();
    makeLease(makeUnit(makeAsset()), $tenant); // active lease → resolves unit

    $this->postJson('/api/v1/me/requests', [
        'title' => 'AC not cooling',
        'description' => 'The unit AC stopped cooling yesterday.',
        'category' => 'hvac',
        'priority' => 'high',
    ], apiHeaders($tenant))
        ->assertCreated()
        ->assertJsonPath('data.title', 'AC not cooling')
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.priority', 'high');

    $this->assertDatabaseHas('tenant_requests', [
        'tenant_id' => $tenant->id, 'title' => 'AC not cooling', 'channel' => 'portal',
    ]);
});

it('creates a request with image + PDF attachments', function () {
    Storage::fake('local');
    $tenant = makeTenant();
    makeLease(makeUnit(makeAsset()), $tenant);

    // Multipart upload (post, not postJson) so the files reach the request.
    $response = $this->post('/api/v1/me/requests', [
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

    $request = TenantRequest::firstWhere('title', 'Leaking pipe');
    expect($request->getMedia('attachments'))->toHaveCount(2);
});

it('rejects a non image/PDF attachment', function () {
    Storage::fake('local');
    $tenant = makeTenant();
    makeLease(makeUnit(makeAsset()), $tenant);

    $this->post('/api/v1/me/requests', [
        'title' => 'Broken AC',
        'description' => 'See the clip.',
        'category' => 'hvac',
        'attachments' => [UploadedFile::fake()->create('clip.mp4', 200, 'video/mp4')],
    ], array_merge(apiHeaders($tenant), ['Accept' => 'application/json']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['attachments.0']);
});

it('rejects more than five attachments', function () {
    Storage::fake('local');
    $tenant = makeTenant();
    makeLease(makeUnit(makeAsset()), $tenant);

    $this->post('/api/v1/me/requests', [
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

    $this->postJson('/api/v1/me/requests', [
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

    $response = $this->getJson("/api/v1/me/requests/{$request->id}", apiHeaders($tenant))->assertOk();

    expect($response->json('data.comments'))->toHaveCount(1);
    expect($response->json('data.comments.0.body'))->toBe('Public note');
});

it('adds a public comment', function () {
    $tenant = makeTenant();
    $request = makeMaintenance($tenant);

    $this->postJson("/api/v1/me/requests/{$request->id}/comments", [
        'body' => 'Any update?',
    ], apiHeaders($tenant))->assertCreated();

    $this->assertDatabaseHas('tenant_request_comments', [
        'tenant_request_id' => $request->id, 'body' => 'Any update?', 'is_internal' => false,
    ]);
});

it('syncs attachment URLs to the app in the show + list responses', function () {
    Storage::fake('local'); // attachments live on the private 'local' disk now
    $tenant = makeTenant();
    $request = makeMaintenance($tenant);
    $request->addMedia(UploadedFile::fake()->image('damage.jpg'))->toMediaCollection('attachments');

    // Response keys are camelCased by the CamelCaseResponseKeys middleware to
    // match the Flutter app (mime_type → mimeType).
    $show = $this->getJson("/api/v1/me/requests/{$request->id}", apiHeaders($tenant))->assertOk();
    expect($show->json('data.attachments'))->toHaveCount(1);
    expect($show->json('data.attachments.0'))->toHaveKeys(['id', 'name', 'mimeType', 'size', 'url']);
    expect($show->json('data.attachments.0.name'))->toContain('damage');
    // URL is the authenticated, tenant-scoped stream route — NOT a public file URL.
    expect($show->json('data.attachments.0.url'))->toContain("/requests/{$request->id}/attachments/");

    $list = $this->getJson('/api/v1/me/requests', apiHeaders($tenant))->assertOk();
    expect($list->json('data.0.attachments'))->toHaveCount(1);
});

it('streams an attachment to its owner (H2)', function () {
    Storage::fake('local');
    $tenant = makeTenant();
    $request = makeMaintenance($tenant);
    $media = $request->addMedia(UploadedFile::fake()->image('private.jpg'))->toMediaCollection('attachments');

    $this->get("/api/v1/me/requests/{$request->id}/attachments/{$media->id}", apiHeaders($tenant))
        ->assertOk();
});

it('404s a foreign tenant requesting an attachment (H2 — no cross-tenant disclosure)', function () {
    Storage::fake('local');
    $owner = makeTenant();
    $request = makeMaintenance($owner);
    $media = $request->addMedia(UploadedFile::fake()->image('private.jpg'))->toMediaCollection('attachments');

    // A different tenant's token must not reach it (request isn't theirs).
    $this->get("/api/v1/me/requests/{$request->id}/attachments/{$media->id}", apiHeaders(makeTenant()))
        ->assertNotFound();
});

it('shows the tenant proof of the fix — the resolution evidence, apart from what they reported (SW-249)', function () {
    // SW-246 gave a maintenance request a `resolution_evidence` collection (what the operator
    // attached when resolving) and showed it in the PORTAL; the app got nothing, because the
    // resource serialised `attachments` by name. Two collections, two keys: "what you reported"
    // and "what was done" must not be merged into one list the app cannot tell apart.
    Storage::fake('local');
    $tenant = makeTenant();
    $request = makeMaintenance($tenant, ['status' => 'resolved', 'resolution_notes' => 'Replaced the ballast.']);
    $request->addMedia(UploadedFile::fake()->image('before.jpg'))->toMediaCollection('attachments');
    $evidence = $request->addMedia(UploadedFile::fake()->image('after.jpg'))->toMediaCollection('resolution_evidence');

    $show = $this->getJson("/api/v1/me/requests/{$request->id}", apiHeaders($tenant))->assertOk();

    expect($show->json('data.attachments'))->toHaveCount(1)
        ->and($show->json('data.attachments.0.name'))->toContain('before')
        ->and($show->json('data.resolutionEvidence'))->toHaveCount(1)
        ->and($show->json('data.resolutionEvidence.0'))->toHaveKeys(['id', 'name', 'mimeType', 'size', 'url'])
        ->and($show->json('data.resolutionEvidence.0.name'))->toContain('after')
        ->and($show->json('data.resolutionEvidence.0.url'))->toContain("/requests/{$request->id}/attachments/{$evidence->id}");

    // The URL the resource hands out must actually serve the file — the stream endpoint used to
    // look in `attachments` alone, so a resolution photo's URL would have 404'd its own owner.
    $this->get("/api/v1/me/requests/{$request->id}/attachments/{$evidence->id}", apiHeaders($tenant))
        ->assertOk();

    // The list carries it too, and a request that owes no evidence carries an empty list, not a
    // missing key — the app decodes a fixed shape.
    $list = $this->getJson('/api/v1/me/requests', apiHeaders($tenant))->assertOk();
    expect($list->json('data.0.resolutionEvidence'))->toHaveCount(1);

    makeMaintenance($tenant, ['title' => 'Nothing owed']);
    $list = $this->getJson('/api/v1/me/requests', apiHeaders($tenant))->assertOk();
    expect(collect($list->json('data'))->firstWhere('title', 'Nothing owed')['resolutionEvidence'])->toBe([]);
});

it('404s a foreign tenant fetching resolution evidence, and a file from no tenant-visible collection', function () {
    Storage::fake('local');
    $owner = makeTenant();
    $request = makeMaintenance($owner, ['status' => 'resolved']);
    $evidence = $request->addMedia(UploadedFile::fake()->image('after.jpg'))->toMediaCollection('resolution_evidence');

    $this->get("/api/v1/me/requests/{$request->id}/attachments/{$evidence->id}", apiHeaders(makeTenant()))
        ->assertNotFound();

});

it('404s a file from a collection the tenant is not shown, even on their own request', function () {
    // The collection is part of the gate. The first cut of this file called the clause
    // unexercisable — "a file in an unregistered collection has no disk to be served from" —
    // and the review measured the opposite: medialibrary's default disk is `public`, fail-open
    // (the MediaPrivacy trap CLAUDE.md records), so a media row in a collection the tenant is
    // not shown streamed 200 by id without the clause. That is the only security clause in the
    // change, and it bites — ONE request per case, because Sanctum's guard memoises the first
    // user it resolves and a second bearer token in the same test is served as the first tenant,
    // which made the first cut of this tooth pass for the wrong reason.
    Storage::fake('local');
    Storage::fake('public');
    $owner = makeTenant();
    $request = makeMaintenance($owner, ['status' => 'resolved']);
    $internal = $request->addMedia(UploadedFile::fake()->image('internal.jpg'))->toMediaCollection('operator_only');

    $this->get("/api/v1/me/requests/{$request->id}/attachments/{$internal->id}", apiHeaders($owner))
        ->assertNotFound();
});

it('carries both file lists on every endpoint that returns a request — the app decodes a fixed shape', function () {
    // `cancel`, `confirm`, `dispute` and `rate` returned the resource with `unit` loaded and not
    // `media`, so both keys were ABSENT on exactly the responses a client replaces its local model
    // with — found by the review of SW-249 against a doc sentence saying "always present".
    Storage::fake('local');
    $tenant = makeTenant();

    $resolved = makeMaintenance($tenant, ['status' => 'resolved', 'resolution_notes' => 'Done.']);
    $resolved->addMedia(UploadedFile::fake()->image('after.jpg'))->toMediaCollection('resolution_evidence');

    $confirm = $this->postJson("/api/v1/me/requests/{$resolved->id}/confirm", [], apiHeaders($tenant))->assertOk();
    expect($confirm->json('data.resolutionEvidence'))->toHaveCount(1)
        ->and($confirm->json('data.attachments'))->toBe([]);

    $rate = $this->postJson("/api/v1/me/requests/{$resolved->id}/rate", ['rating' => 5], apiHeaders($tenant))->assertOk();
    expect($rate->json('data'))->toHaveKeys(['attachments', 'resolutionEvidence']);

    $open = makeMaintenance($tenant, ['status' => 'acknowledged']);
    $cancel = $this->postJson("/api/v1/me/requests/{$open->id}/cancel", [], apiHeaders($tenant))->assertOk();
    expect($cancel->json('data'))->toHaveKeys(['attachments', 'resolutionEvidence']);
});

it('cancels a not-yet-started request', function () {
    $tenant = makeTenant();
    $request = makeMaintenance($tenant, ['status' => 'acknowledged']);

    $this->postJson("/api/v1/me/requests/{$request->id}/cancel", [], apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

it('refuses to cancel a request that is already in progress', function () {
    $tenant = makeTenant();
    $request = makeMaintenance($tenant, ['status' => 'in_progress']);

    $this->postJson("/api/v1/me/requests/{$request->id}/cancel", [], apiHeaders($tenant))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    expect($request->fresh()->status)->toBe('in_progress');
});

it('returns 404 for another tenant\'s request', function () {
    $tenant = makeTenant();
    $request = makeMaintenance(makeTenant());

    $this->getJson("/api/v1/me/requests/{$request->id}", apiHeaders($tenant))->assertNotFound();
});
