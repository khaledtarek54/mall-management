<?php

use App\Models\Vendor;
use App\Models\VendorDocument;
use App\Models\VendorDocumentType;
use Database\Seeders\VendorDocumentTypeSeeder;

/**
 * **Which lapsed document stops a vendor being sent to site is the operator's ruling — and the
 * mechanism that lets them make it must not be able to un-make the existing one by accident.**
 *
 * `VendorDocument::BLOCKING_TYPES` was an array literal holding one value, and it is the whole of
 * the compliance gate: `Vendor::isDispatchable()` and the assignable-vendor picker both narrow on
 * it. Turning it into a column (`vendor_document_types.blocks_dispatch`) opened exactly one way to
 * break the gate silently — `whereIn('type', [])` matches NOTHING, so a catalogue that answered an
 * empty list would make every vendor with a lapsed certificate dispatchable again, with no error
 * anywhere and every existing test still green (they all seed a COI).
 *
 * Three properties, each pinned with a control that must go the other way:
 *
 * 1. an UNSEEDED catalogue still blocks on insurance — the floor;
 * 2. a type the operator ticks starts blocking — the point of the change;
 * 3. an INACTIVE type still blocks, because `is_active` governs what may be FILED, not whether a
 *    certificate already on file counts.
 *
 * The mutation that must turn this red: make `VendorDocumentType::blockingCodes()` return the
 * queried list unconditionally, dropping the `exists()` floor.
 */
function lapsedDoc(Vendor $vendor, string $type): VendorDocument
{
    return VendorDocument::create([
        'vendor_id' => $vendor->id,
        'type' => $type,
        'expires_on' => now()->subDays(5)->toDateString(),
    ]);
}

function activeVendor(string $name = 'Site Contractor'): Vendor
{
    return Vendor::create(['name' => $name, 'type' => 'contractor', 'status' => 'active']);
}

it('still blocks on insurance when the catalogue has never been seeded', function () {
    expect(VendorDocumentType::query()->count())->toBe(0);

    $vendor = activeVendor();
    lapsedDoc($vendor, VendorDocument::TYPE_INSURANCE_COI);

    expect($vendor->fresh()->isDispatchable())->toBeFalse()
        ->and(Vendor::assignable()->pluck('id'))->not->toContain($vendor->id);

    // The control: an unseeded catalogue must not block EVERYTHING either — a lapsed tax card is a
    // finance problem, and a gate that refused every vendor would satisfy the assertion above.
    $other = activeVendor('Paperwork Behind Ltd');
    lapsedDoc($other, VendorDocument::TYPE_TAX_CARD);

    expect($other->fresh()->isDispatchable())->toBeTrue()
        ->and(Vendor::assignable()->pluck('id'))->toContain($other->id);
});

it('blocks on a type the operator ticks, and stops blocking on one they untick', function () {
    $this->seed(VendorDocumentTypeSeeder::class);

    $vendor = activeVendor();
    lapsedDoc($vendor, VendorDocument::TYPE_TAX_CARD);

    // The shipped ruling: a lapsed tax card is chased, not blocking.
    expect($vendor->fresh()->isDispatchable())->toBeTrue();

    VendorDocumentType::query()->where('code', VendorDocument::TYPE_TAX_CARD)
        ->first()->update(['blocks_dispatch' => true]);

    expect($vendor->fresh()->isDispatchable())->toBeFalse()
        ->and(Vendor::assignable()->pluck('id'))->not->toContain($vendor->id);

    // And the other direction, which is the one an operator will actually reach for after an
    // emergency: unticking it releases the vendor in the same request.
    VendorDocumentType::query()->where('code', VendorDocument::TYPE_TAX_CARD)
        ->first()->update(['blocks_dispatch' => false]);

    expect($vendor->fresh()->isDispatchable())->toBeTrue();
});

it('keeps blocking on a type that has been deactivated', function () {
    $this->seed(VendorDocumentTypeSeeder::class);

    $vendor = activeVendor();
    lapsedDoc($vendor, VendorDocument::TYPE_INSURANCE_COI);

    VendorDocumentType::query()->where('code', VendorDocument::TYPE_INSURANCE_COI)
        ->first()->update(['is_active' => false]);

    // Retiring the type means no NEW insurance certificate can be filed under it. It cannot mean
    // that every uninsured contractor on the books becomes dispatchable — that would turn a tidying
    // action into a liability event.
    expect($vendor->fresh()->isDispatchable())->toBeFalse();

    // The control: it is genuinely gone from the picker, so the deactivation did something.
    expect(VendorDocumentType::options())->not->toHaveKey(VendorDocument::TYPE_INSURANCE_COI);
});

it('agrees between the row question and the query question', function () {
    $this->seed(VendorDocumentTypeSeeder::class);
    VendorDocumentType::query()->where('code', VendorDocument::TYPE_SOCIAL_INSURANCE)
        ->first()->update(['blocks_dispatch' => true]);

    $vendor = activeVendor();
    $doc = lapsedDoc($vendor, VendorDocument::TYPE_SOCIAL_INSURANCE);

    // `isBlocking()` (a row) and `scopeBlocking()` (a set) are one predicate asked two ways. A
    // vendor the picker offers and the dispatch guard then refuses is worse than either being wrong.
    expect($doc->isBlocking())->toBeTrue()
        ->and(VendorDocument::query()->blocking()->pluck('id'))->toContain($doc->id)
        ->and($vendor->fresh()->isDispatchable())->toBeFalse();
});
