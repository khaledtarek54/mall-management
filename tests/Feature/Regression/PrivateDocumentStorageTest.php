<?php

use App\Models\Lease;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Regression — a lease contract or a tenant's tax card must never land in the webroot.
 *
 * THE BUG (fixed 2026-07-16). `Lease` and `Tenant` implemented HasMedia but registered no
 * collection, so their `documents` collection inherited medialibrary's default disk:
 * `env('MEDIA_DISK', 'public')`. Neither the env var nor a config override existed, so the
 * default won — every signed contract and every retailer's commercial register / tax card
 * was written to the PUBLIC disk and served from a guessable, unauthenticated URL. Three
 * files away, `TenantRequest` correctly pinned `useDisk('local')` and documented exactly
 * why. The default is fail-open, so forgetting looked identical to choosing.
 *
 * WHY THIS EXISTS ALONGSIDE THE CONFORMANCE GATE. MediaPrivacyConformanceTest asserts what
 * the MODEL declares. The Filament form names its collection independently
 * (`SpatieMediaLibraryFileUpload::make('documents')->collection('documents')`), and
 * medialibrary happily creates an unregistered collection on the fly — with the default
 * disk. So a rename on one side and not the other silently restores the exposure while the
 * declaration gate stays green. These tests drive a real upload through the real
 * collection name and assert where the bytes actually landed.
 *
 * @see MediaPrivacyConformanceTest — the structural gate
 */
beforeEach(function () {
    // Fake BOTH disks: `local` so the test doesn't litter storage/app/private, and `public`
    // so assertMissing() is a real check against a real (empty) disk rather than a
    // vacuous assertion about a path nobody wrote to.
    Storage::fake('local');
    Storage::fake('public');
});

it('stores a lease document on the private disk, not the webroot', function () {
    $lease = makeLease(makeUnit(makeAsset()));

    // The collection name is written out rather than referenced through the model's
    // constant on purpose: this must fail against the ORIGINAL code (which had no
    // constant and no registration) by landing the file on the public disk — not error
    // out on a missing constant. It has to exercise the exposure, not depend on the fix.
    $lease->addMedia(UploadedFile::fake()->create('signed-contract.pdf', 64, 'application/pdf'))
        ->toMediaCollection('documents');

    $media = $lease->getMedia('documents')->sole();

    expect($media->disk)->toBe('local', 'a signed contract must not be web-reachable');

    // The bytes are where we think they are, and nowhere near the public disk.
    Storage::disk('local')->assertExists($media->id.'/signed-contract.pdf');
    Storage::disk('public')->assertMissing($media->id.'/signed-contract.pdf');
});

it('stores a tenant document on the private disk, not the webroot', function () {
    $tenant = makeTenant();

    $tenant->addMedia(UploadedFile::fake()->create('tax-card.pdf', 64, 'application/pdf'))
        ->toMediaCollection('documents'); // literal, for the reason above

    $media = $tenant->getMedia('documents')->sole();

    expect($media->disk)->toBe('local', "a retailer's tax card must not be web-reachable");

    Storage::disk('local')->assertExists($media->id.'/tax-card.pdf');
    Storage::disk('public')->assertMissing($media->id.'/tax-card.pdf');
});

it('uses the same collection name the admin form uploads to', function () {
    // The integration the declaration gate can't see: if the form's collection string and
    // the model's registered collection ever drift apart, uploads resume inheriting the
    // public default. Pin both ends to the constant.
    foreach ([
        'Lease' => app_path('Filament/Admin/Resources/Leases/Schemas/LeaseForm.php'),
        'Tenant' => app_path('Filament/Admin/Resources/Tenants/Schemas/TenantForm.php'),
    ] as $label => $form) {
        expect(str_contains(file_get_contents($form), "->collection('documents')"))
            ->toBeTrue("{$label}'s form must upload to the registered 'documents' collection.");
    }

    expect(Lease::DOCUMENTS_COLLECTION)->toBe('documents')
        ->and(Tenant::DOCUMENTS_COLLECTION)->toBe('documents');
});
