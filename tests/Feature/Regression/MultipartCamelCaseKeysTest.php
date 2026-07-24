<?php

use App\Models\TenantRequest;
use App\Models\TenantSalesDeclaration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Regression: SnakeCaseRequestKeys only re-cased the JSON bag and the query
 * string, never the multipart/form-data body. The mobile contract is camelCase
 * (docs/api/MOBILE-API.md), so every multipart endpoint was broken for the
 * client we publish:
 *
 *   - POST /me/sales-declarations  ->  422 "The lease id field is required."
 *     for a payload sent exactly as docs/api/SALES-DECLARATION-FILE-UPLOAD.md
 *     instructs (leaseId / periodStart / periodEnd).
 *   - POST /me/requests  ->  `unitId` silently dropped, so the request was filed
 *     against whatever unit the active-lease fallback derived. No error; wrong data.
 *   - POST /me/requests  ->  `requestType` silently dropped, so a non-maintenance
 *     type 422'd on its own valid sub-category.
 *
 * The pre-existing API tests all POST snake_case, so the suite was green over a
 * contract the app could not satisfy. These drive the camelCase wire the app
 * actually sends.
 */
function percentageLeaseFor($tenant)
{
    return makeLease(makeUnit(makeAsset()), $tenant, [
        'has_percentage_rent' => true,
        'percentage_rent_rate' => 5,
        'percentage_rent_threshold' => 100000,
        'percentage_rent_calculation_type' => 'artificial',
    ]);
}

it('accepts a camelCase multipart sales declaration (leaseId / periodStart / periodEnd)', function () {
    Storage::fake('local');
    $tenant = makeTenant();
    $lease = percentageLeaseFor($tenant);

    $this->post('/api/v1/me/sales-declarations', [
        'leaseId' => $lease->id,
        'periodStart' => '2026-05-01',
        'periodEnd' => '2026-05-31',
        'attachments' => [UploadedFile::fake()->image('may-sales.jpg')],
    ], apiHeaders($tenant))->assertCreated();

    $declaration = TenantSalesDeclaration::sole();

    expect($declaration->lease_id)->toBe($lease->id)
        ->and($declaration->period_start->toDateString())->toBe('2026-05-01')
        ->and($declaration->period_end->toDateString())->toBe('2026-05-31')
        ->and($declaration->getMedia('sales_report'))->toHaveCount(1);
});

it('files a request against the unit named by a camelCase multipart unitId', function () {
    $tenant = makeTenant();
    $asset = makeAsset();
    $fallbackUnit = makeUnit($asset);
    $targetUnit = makeUnit($asset);
    // Both leased by this tenant — so the active-lease fallback would resolve to
    // the first one and mask a dropped unitId.
    makeLease($fallbackUnit, $tenant);
    makeLease($targetUnit, $tenant);

    $this->post('/api/v1/me/requests', [
        'title' => 'AC not cooling',
        'description' => 'The unit has been warm since Monday.',
        'category' => 'hvac',
        'priority' => 'high',
        'unitId' => $targetUnit->id,
    ], apiHeaders($tenant))->assertCreated();

    expect(TenantRequest::sole()->unit_id)->toBe($targetUnit->id);
});

it('honours a camelCase multipart requestType so its own sub-categories validate', function () {
    $tenant = makeTenant();
    makeLease(makeUnit(makeAsset()), $tenant);

    // `lease_copy` is a `document` sub-category — invalid under the `maintenance`
    // default the request falls back to when requestType is dropped.
    $this->post('/api/v1/me/requests', [
        'title' => 'Copy of my lease',
        'description' => 'Please send a signed copy.',
        'requestType' => 'document',
        'category' => 'lease_copy',
    ], apiHeaders($tenant))->assertCreated();

    $request = TenantRequest::sole();

    expect($request->request_type->value)->toBe('document')
        ->and($request->category)->toBe('lease_copy');
});

it('still accepts snake_case multipart bodies (no regression for existing clients)', function () {
    Storage::fake('local');
    $tenant = makeTenant();
    $lease = percentageLeaseFor($tenant);

    $this->post('/api/v1/me/sales-declarations', [
        'lease_id' => $lease->id,
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'attachments' => [UploadedFile::fake()->image('june-sales.jpg')],
    ], apiHeaders($tenant))->assertCreated();

    expect(TenantSalesDeclaration::sole()->lease_id)->toBe($lease->id);
});

it('re-cases camelCase uploaded-file field names', function () {
    Storage::fake('local');
    $tenant = makeTenant();
    $lease = percentageLeaseFor($tenant);

    // The files bag is separate from the request bag; merge() never reaches it.
    // `attachments` is already snake, so assert the mechanism on a camel name
    // that must arrive as the snake key validation expects.
    $request = Illuminate\Http\Request::create('/api/v1/me/sales-declarations', 'POST', [
        'leaseId' => $lease->id,
    ], [], [
        'salesReport' => [UploadedFile::fake()->image('report.jpg')],
    ]);

    $middleware = new App\Http\Middleware\SnakeCaseRequestKeys;
    $middleware->handle($request, fn () => new Illuminate\Http\Response);

    expect($request->files->keys())->toContain('sales_report')
        ->and((int) $request->input('lease_id'))->toBe($lease->id);
});
