<?php

use App\Models\Violation;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Violations gained a category + photographic evidence (module 31). A violation classified only by
 * free text couldn't be filtered or reported by kind, and one with no photo isn't defensible. These
 * pin the category field, the private photo collection, and that the evidence stays on the private
 * disk (MediaPrivacyConformanceTest gates the disk; this proves the wiring end-to-end).
 */
function makeViolation(array $overrides = []): Violation
{
    return Violation::create(array_merge([
        'asset_id' => makeAsset()->id,
        'tenant_id' => makeTenant()->id,
        'category' => 'signage',
        'description' => 'Unauthorised banner on the shopfront.',
        'violation_date' => now()->toDateString(),
    ], $overrides));
}

it('records a violation under a category and filters by it', function () {
    makeViolation(['category' => 'signage']);
    makeViolation(['category' => 'safety']);
    makeViolation(['category' => 'safety']);

    expect(Violation::where('category', 'safety')->count())->toBe(2)
        ->and(Violation::where('category', 'signage')->count())->toBe(1)
        // The offered set is the model's — no DB enum, extendable without a migration.
        ->and(Violation::CATEGORIES)->toContain('signage')->toContain('safety')->toContain('other');
});

it('attaches evidence photos on the PRIVATE disk', function () {
    $violation = makeViolation();

    $violation->addMedia(UploadedFile::fake()->image('breach.jpg'))
        ->toMediaCollection(Violation::PHOTOS_COLLECTION);

    $media = $violation->getMedia(Violation::PHOTOS_COLLECTION);

    expect($media)->toHaveCount(1)
        // Confidential — never the fail-open public webroot.
        ->and($media->first()->disk)->toBe('local');
});

it('declares the photos collection on the private disk (media-privacy invariant)', function () {
    $violation = new Violation;
    $violation->registerMediaCollections();

    $collection = collect($violation->getRegisteredMediaCollections())
        ->firstWhere('name', Violation::PHOTOS_COLLECTION);

    expect($collection?->diskName)->toBe('local');
});

it('reports the evidence count per violation without loading files', function () {
    $withPhotos = makeViolation();
    $withPhotos->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection(Violation::PHOTOS_COLLECTION);
    $withPhotos->addMedia(UploadedFile::fake()->image('b.jpg'))->toMediaCollection(Violation::PHOTOS_COLLECTION);
    $noPhotos = makeViolation();

    expect($withPhotos->getMedia(Violation::PHOTOS_COLLECTION))->toHaveCount(2)
        ->and($noPhotos->getMedia(Violation::PHOTOS_COLLECTION)->isEmpty())->toBeTrue();
});
