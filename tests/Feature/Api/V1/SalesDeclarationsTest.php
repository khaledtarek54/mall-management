<?php

use App\Models\TenantSalesDeclaration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function makePercentageLease($tenant)
{
    return makeLease(makeUnit(makeAsset()), $tenant, [
        'has_percentage_rent' => true,
        'percentage_rent_rate' => 5,
        'percentage_rent_threshold' => 100000,
        'percentage_rent_calculation_type' => 'artificial',
    ]);
}

/** A submitted, not-yet-reviewed declaration (no figure yet — mirrors the API path). */
function makeSubmittedDeclaration($lease, array $overrides = []): TenantSalesDeclaration
{
    return TenantSalesDeclaration::create(array_merge([
        'lease_id' => $lease->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
        'status' => 'submitted',
        'declared_at' => now(),
    ], $overrides));
}

it('lists the tenant\'s sales declarations', function () {
    $tenant = makeTenant();
    $lease = makePercentageLease($tenant);
    makeSubmittedDeclaration($lease);

    $response = $this->getJson('/api/v1/me/sales-declarations', apiHeaders($tenant))->assertOk();
    expect($response->json('meta.total'))->toBe(1);
});

it('creates a declaration from an uploaded sales report (no figure yet)', function () {
    Storage::fake('local');
    $tenant = makeTenant();
    $lease = makePercentageLease($tenant);

    $response = $this->postJson('/api/v1/me/sales-declarations', [
        'lease_id' => $lease->id,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
        'attachments' => [
            UploadedFile::fake()->create('may-sales.pdf', 100, 'application/pdf'),
        ],
    ], apiHeaders($tenant))
        ->assertCreated()
        ->assertJsonPath('data.status', 'submitted')
        // The tenant supplies no figure — staff enter it after reviewing.
        ->assertJsonPath('data.declaredSales', null)
        ->assertJsonPath('data.hasReport', true);

    expect($response->json('data.attachments'))->toHaveCount(1);

    $declaration = TenantSalesDeclaration::first();
    expect($declaration->declared_sales)->toBeNull();
    expect($declaration->getMedia('sales_report'))->toHaveCount(1);
});

it('rejects a declaration with no attached report', function () {
    $tenant = makeTenant();
    $lease = makePercentageLease($tenant);

    $this->postJson('/api/v1/me/sales-declarations', [
        'lease_id' => $lease->id,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
    ], apiHeaders($tenant))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['attachments']);
});

it('rejects a non image/PDF report file', function () {
    Storage::fake('local');
    $tenant = makeTenant();
    $lease = makePercentageLease($tenant);

    $this->postJson('/api/v1/me/sales-declarations', [
        'lease_id' => $lease->id,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
        'attachments' => [UploadedFile::fake()->create('clip.mp4', 200, 'video/mp4')],
    ], apiHeaders($tenant))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['attachments.0']);
});

it('rejects a declaration on a lease without percentage rent', function () {
    Storage::fake('local');
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant); // no percentage rent

    $this->postJson('/api/v1/me/sales-declarations', [
        'lease_id' => $lease->id, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'attachments' => [UploadedFile::fake()->create('sales.pdf', 100, 'application/pdf')],
    ], apiHeaders($tenant))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['leaseId']);
});

it('rejects a duplicate declaration for the same period', function () {
    Storage::fake('local');
    $tenant = makeTenant();
    $lease = makePercentageLease($tenant);
    makeSubmittedDeclaration($lease, ['period_start' => '2026-05-01', 'period_end' => '2026-05-31']);

    $this->postJson('/api/v1/me/sales-declarations', [
        'lease_id' => $lease->id, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'attachments' => [UploadedFile::fake()->create('sales.pdf', 100, 'application/pdf')],
    ], apiHeaders($tenant))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['periodStart']);
});

it('rejects a declaration against another tenant\'s lease', function () {
    Storage::fake('local');
    $tenant = makeTenant();
    $foreignLease = makePercentageLease(makeTenant());

    $this->postJson('/api/v1/me/sales-declarations', [
        'lease_id' => $foreignLease->id, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'attachments' => [UploadedFile::fake()->create('sales.pdf', 100, 'application/pdf')],
    ], apiHeaders($tenant))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['leaseId']);
});

it('exposes the report attachment URL in the show + list responses', function () {
    Storage::fake('local');
    $tenant = makeTenant();
    $lease = makePercentageLease($tenant);
    $declaration = makeSubmittedDeclaration($lease);
    $declaration->addMedia(UploadedFile::fake()->create('report.pdf', 50, 'application/pdf'))
        ->toMediaCollection('sales_report');

    $show = $this->getJson("/api/v1/me/sales-declarations/{$declaration->id}", apiHeaders($tenant))->assertOk();
    expect($show->json('data.attachments'))->toHaveCount(1);
    expect($show->json('data.attachments.0'))->toHaveKeys(['id', 'name', 'mimeType', 'size', 'url']);
    expect($show->json('data.attachments.0.url'))->toContain("/sales-declarations/{$declaration->id}/attachments/");

    $list = $this->getJson('/api/v1/me/sales-declarations', apiHeaders($tenant))->assertOk();
    expect($list->json('data.0.attachments'))->toHaveCount(1);
});

it('streams a sales report to its owner', function () {
    Storage::fake('local');
    $tenant = makeTenant();
    $lease = makePercentageLease($tenant);
    $declaration = makeSubmittedDeclaration($lease);
    $media = $declaration->addMedia(UploadedFile::fake()->create('private.pdf', 50, 'application/pdf'))
        ->toMediaCollection('sales_report');

    $this->get("/api/v1/me/sales-declarations/{$declaration->id}/attachments/{$media->id}", apiHeaders($tenant))
        ->assertOk();
});

it('404s a foreign tenant requesting a sales report (no cross-tenant disclosure)', function () {
    Storage::fake('local');
    $tenant = makeTenant();
    $lease = makePercentageLease($tenant);
    $declaration = makeSubmittedDeclaration($lease);
    $media = $declaration->addMedia(UploadedFile::fake()->create('private.pdf', 50, 'application/pdf'))
        ->toMediaCollection('sales_report');

    $this->get("/api/v1/me/sales-declarations/{$declaration->id}/attachments/{$media->id}", apiHeaders(makeTenant()))
        ->assertNotFound();
});
