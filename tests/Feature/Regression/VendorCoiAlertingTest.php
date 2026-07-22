<?php

use App\Models\Vendor;
use App\Models\VendorContract;
use App\Notifications\VendorCoiExpiringNotification;
use Illuminate\Support\Facades\Notification;

/**
 * A vendor's COI used to lapse SILENTLY (module 12).
 *
 * The compliance gate refuses to dispatch a vendor whose cert has expired — correctly — but nothing
 * warned beforehand and nothing explained afterwards. The contractor simply stopped appearing in
 * every picker. These pin the chase: 30 days out, again on lapse, never twice for the same cert,
 * and re-armed automatically by a renewal.
 */
function coiVendor(?string $expiresAt, array $overrides = []): Vendor
{
    return Vendor::create(array_merge([
        'name' => 'Cool Air Services',
        'status' => Vendor::STATUS_ACTIVE,
        'coi_expires_at' => $expiresAt,
    ], $overrides));
}

it('alerts once when a COI is inside the 30-day window, then never re-nags', function () {
    Notification::fake();
    $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
    $vendor = coiVendor(now()->addDays(10)->toDateString());
    VendorContract::create([
        'vendor_id' => $vendor->id,
        'asset_id' => makeAsset()->id,
        'name' => 'HVAC PPM',
        'status' => 'active',
        'start_date' => now()->subYear(),
        'end_date' => now()->addYear(),
        'value' => 100000,
    ]);

    $this->artisan('vendors:scan-coi-expiry')->assertSuccessful();

    expect($vendor->refresh()->coi_alert_stage)->toBe(Vendor::COI_STAGE_EXPIRING)
        ->and($vendor->coi_alert_for->toDateString())->toBe($vendor->coi_expires_at->toDateString());

    // A second run must not re-alert — same stage, same cert date.
    Notification::fake();
    $this->artisan('vendors:scan-coi-expiry')->assertSuccessful();
    Notification::assertNothingSent();
});

it('escalates to a second, different alert once the COI actually lapses', function () {
    $vendor = coiVendor(now()->addDays(5)->toDateString());

    $this->artisan('vendors:scan-coi-expiry')->assertSuccessful();
    expect($vendor->refresh()->coi_alert_stage)->toBe(Vendor::COI_STAGE_EXPIRING);

    // The cert lapses. The operator must hear about it again — the vendor is now un-assignable.
    $vendor->forceFill(['coi_expires_at' => now()->subDay()->toDateString()])->save();

    $this->artisan('vendors:scan-coi-expiry')->assertSuccessful();
    expect($vendor->refresh()->coi_alert_stage)->toBe(Vendor::COI_STAGE_EXPIRED)
        ->and($vendor->isDispatchable())->toBeFalse();
});

it('re-arms the whole cycle when the COI is renewed to a new date', function () {
    $vendor = coiVendor(now()->addDays(3)->toDateString());
    $this->artisan('vendors:scan-coi-expiry')->assertSuccessful();
    expect($vendor->refresh()->coi_alert_stage)->toBe(Vendor::COI_STAGE_EXPIRING);

    // Renewed a year out — nothing to chase, so the stamp must NOT block a future alert
    // when that new cert eventually approaches its own expiry.
    $vendor->forceFill(['coi_expires_at' => now()->addYear()->toDateString()])->save();
    $this->artisan('vendors:scan-coi-expiry')->assertSuccessful();
    expect($vendor->refresh()->coi_alert_stage)->toBe(Vendor::COI_STAGE_EXPIRING); // stale stamp, untouched

    // Wind that renewed cert into its own window: a NEW date ⇒ a fresh alert.
    $vendor->forceFill(['coi_expires_at' => now()->addDays(7)->toDateString()])->save();
    $this->artisan('vendors:scan-coi-expiry')->assertSuccessful();

    expect($vendor->refresh()->coi_alert_for->toDateString())
        ->toBe(now()->addDays(7)->toDateString());
});

it('notifies staff of the properties where the vendor actually works', function () {
    Notification::fake();
    $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
    $asset = makeAsset();
    $manager = makeUser('manager');
    $manager->assignedAssets()->syncWithoutDetaching([$asset->id]);

    $vendor = coiVendor(now()->addDays(2)->toDateString());
    VendorContract::create([
        'vendor_id' => $vendor->id,
        'asset_id' => $asset->id,
        'name' => 'Cleaning',
        'status' => 'active',
        'start_date' => now()->subMonth(),
        'end_date' => now()->addMonths(6),
        'value' => 50000,
    ]);

    $this->artisan('vendors:scan-coi-expiry')->assertSuccessful();

    Notification::assertSentTo($manager, VendorCoiExpiringNotification::class);
});

it('leaves alone a vendor with no cert on file and one comfortably in date', function () {
    $none = coiVendor(null, ['name' => 'No Cert Co']);
    $fine = coiVendor(now()->addMonths(6)->toDateString(), ['name' => 'Well Covered Co']);

    $this->artisan('vendors:scan-coi-expiry')->assertSuccessful();

    expect($none->refresh()->coi_alert_stage)->toBeNull()
        ->and($fine->refresh()->coi_alert_stage)->toBeNull()
        // …and neither is blocked from work: a missing cert is not an expired one.
        ->and($none->isDispatchable())->toBeTrue()
        ->and($fine->isDispatchable())->toBeTrue();
});

it('does not chase a blacklisted vendor — it is already out of every picker', function () {
    $vendor = coiVendor(now()->subDays(5)->toDateString(), ['status' => Vendor::STATUS_BLACKLISTED]);

    $this->artisan('vendors:scan-coi-expiry')->assertSuccessful();

    expect($vendor->refresh()->coi_alert_stage)->toBeNull()
        ->and($vendor->coiAlertStage())->toBeNull();
});
