<?php

use App\Models\Vendor;
use App\Models\VendorContract;
use App\Models\VendorDocument;
use App\Notifications\VendorDocumentExpiringNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Vendor compliance documents used to lapse SILENTLY (module 12/12b).
 *
 * The dispatch gate refuses to send a vendor to site on a lapsed insurance certificate — correctly —
 * but nothing warned beforehand and nothing explained afterwards: the contractor simply stopped
 * appearing in every picker. The statutory Egyptian documents (tax card, commercial register,
 * social insurance) weren't modelled at all. These pin the chase: 30 days out, again on lapse,
 * never twice for the same document, and re-armed automatically by a renewal.
 */
function docVendor(array $overrides = []): Vendor
{
    return Vendor::create(array_merge([
        'name' => 'Cool Air Services',
        'status' => Vendor::STATUS_ACTIVE,
    ], $overrides));
}

function vendorDoc(Vendor $vendor, ?string $expiresOn, string $type = VendorDocument::TYPE_INSURANCE_COI): VendorDocument
{
    return VendorDocument::create([
        'vendor_id' => $vendor->id,
        'type' => $type,
        'expires_on' => $expiresOn,
    ]);
}

it('alerts once when a document is inside the 30-day window, then never re-nags', function () {
    Notification::fake();
    $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
    $vendor = docVendor();
    $document = vendorDoc($vendor, now()->addDays(10)->toDateString());

    $this->artisan('vendors:scan-document-expiry')->assertSuccessful();

    expect($document->refresh()->alert_stage)->toBe(VendorDocument::STAGE_EXPIRING)
        ->and($document->alert_for->toDateString())->toBe($document->expires_on->toDateString());

    // A second run must not re-alert — same stage, same expiry.
    Notification::fake();
    $this->artisan('vendors:scan-document-expiry')->assertSuccessful();
    Notification::assertNothingSent();
});

it('escalates to a second, different alert once the insurance actually lapses', function () {
    $vendor = docVendor();
    $document = vendorDoc($vendor, now()->addDays(5)->toDateString());

    $this->artisan('vendors:scan-document-expiry')->assertSuccessful();
    expect($document->refresh()->alert_stage)->toBe(VendorDocument::STAGE_EXPIRING);

    // The cert lapses. The operator must hear about it again — the vendor is now un-assignable.
    $document->forceFill(['expires_on' => now()->subDay()->toDateString()])->save();

    $this->artisan('vendors:scan-document-expiry')->assertSuccessful();
    expect($document->refresh()->alert_stage)->toBe(VendorDocument::STAGE_EXPIRED)
        ->and($vendor->refresh()->isDispatchable())->toBeFalse();
});

it('re-arms the whole cycle when a document is renewed to a new date', function () {
    $vendor = docVendor();
    $document = vendorDoc($vendor, now()->addDays(3)->toDateString());
    $this->artisan('vendors:scan-document-expiry')->assertSuccessful();
    expect($document->refresh()->alert_stage)->toBe(VendorDocument::STAGE_EXPIRING);

    // Renewed a year out — nothing to chase, so the stale stamp is simply left alone.
    $document->forceFill(['expires_on' => now()->addYear()->toDateString()])->save();
    $this->artisan('vendors:scan-document-expiry')->assertSuccessful();
    expect($document->refresh()->alert_stage)->toBe(VendorDocument::STAGE_EXPIRING);

    // Wind that renewed document into its own window: a NEW date ⇒ a fresh alert.
    $document->forceFill(['expires_on' => now()->addDays(7)->toDateString()])->save();
    $this->artisan('vendors:scan-document-expiry')->assertSuccessful();

    expect($document->refresh()->alert_for->toDateString())
        ->toBe(now()->addDays(7)->toDateString());
});

it('chases a statutory document without ever blocking site work', function () {
    $vendor = docVendor();
    // بطاقة ضريبية — a finance-side compliance problem, not a reason to stop an emergency repair.
    $taxCard = vendorDoc($vendor, now()->subDays(3)->toDateString(), VendorDocument::TYPE_TAX_CARD);

    $this->artisan('vendors:scan-document-expiry')->assertSuccessful();

    expect($taxCard->refresh()->alert_stage)->toBe(VendorDocument::STAGE_EXPIRED)
        ->and($taxCard->isBlocking())->toBeFalse()
        // …and the vendor is still dispatchable, unlike an expired insurance certificate.
        ->and($vendor->refresh()->isDispatchable())->toBeTrue()
        ->and(Vendor::query()->assignable()->pluck('id')->all())->toContain($vendor->id);
});

it('notifies staff of the properties where the vendor actually works', function () {
    Notification::fake();
    $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
    $asset = makeAsset();
    $manager = makeUser('manager');
    $manager->assignedAssets()->syncWithoutDetaching([$asset->id]);

    $vendor = docVendor();
    vendorDoc($vendor, now()->addDays(2)->toDateString());
    VendorContract::create([
        'vendor_id' => $vendor->id,
        'asset_id' => $asset->id,
        'name' => 'Cleaning',
        'status' => 'active',
        'start_date' => now()->subMonth(),
        'end_date' => now()->addMonths(6),
        'value' => 50000,
    ]);

    $this->artisan('vendors:scan-document-expiry')->assertSuccessful();

    Notification::assertSentTo($manager, VendorDocumentExpiringNotification::class);
});

it('leaves alone a document with no expiry and one comfortably in date', function () {
    $vendor = docVendor();
    $perpetual = vendorDoc($vendor, null, VendorDocument::TYPE_COMMERCIAL_REGISTER);
    $fine = vendorDoc($vendor, now()->addMonths(6)->toDateString());

    $this->artisan('vendors:scan-document-expiry')->assertSuccessful();

    expect($perpetual->refresh()->alert_stage)->toBeNull()
        ->and($fine->refresh()->alert_stage)->toBeNull()
        // A vendor with no expiry tracked is not "expired" — it stays dispatchable.
        ->and($vendor->refresh()->isDispatchable())->toBeTrue();
});

it('does not chase a blacklisted vendor — it is already out of every picker', function () {
    $vendor = docVendor(['status' => Vendor::STATUS_BLACKLISTED]);
    $document = vendorDoc($vendor, now()->subDays(5)->toDateString());

    $this->artisan('vendors:scan-document-expiry')->assertSuccessful();

    expect($document->refresh()->alert_stage)->toBeNull();
});
