<?php

use App\Models\TenantDocument;
use App\Notifications\TenantDocumentExpiringNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * The tenant compliance chase — Yardi gap-analysis row 92.
 *
 * Atriom chased VENDOR paperwork from module 12b and tracked nothing at all for tenants, which is
 * the wrong way round if you only get one: an uninsured contractor is at least stopped at the
 * dispatch gate, whereas an uninsured RETAILER simply keeps trading. The lease's insurance
 * obligation was written into the contract and then never checked again.
 *
 * Nothing is blocked when one of these lapses — there is no "un-let the shop" action to gate — so
 * the alert IS the mechanism, and these tests are what prove it fires exactly once per stage.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * A real recipient, deliberately.
 *
 * `AssetStaffRecipients` resolves through spatie's `role()` scope, so with no seeded roles and no
 * staff the scan sends to nobody — and every `assertSentTimes(…, 0)` style assertion would pass for
 * the wrong reason. Seeding a manager means "did not alert" is a finding rather than the default.
 */
function chaseStaff(): void
{
    test()->seed(RolesPermissionsSeeder::class);

    // A super_admin specifically. `AssetStaffRecipients::for(null, …)` resolves ONLY super_admins —
    // the property-team roles are matched through `assignedAssets`, so with no asset there is
    // nothing to match them against. Seeding a plain manager here left the scan sending to nobody
    // and every count assertion passing at zero, which is the failure this helper exists to prevent.
    makeUser('super_admin');
}

function tenantDoc(array $attributes = []): TenantDocument
{
    $tenant = makeTenant(['status' => 'active']);

    return TenantDocument::create(array_merge([
        'tenant_id' => $tenant->id,
        'type' => TenantDocument::TYPE_INSURANCE_COI,
        'reference' => 'POL-1',
        'expires_on' => CarbonImmutable::now()->addDays(10)->toDateString(),
    ], $attributes));
}

it('stages a document as expiring inside the window and expired after it', function () {
    CarbonImmutable::setTestNow('2026-06-01');

    expect(tenantDoc(['expires_on' => '2026-06-20'])->alertStage())->toBe(TenantDocument::STAGE_EXPIRING)
        ->and(tenantDoc(['expires_on' => '2026-05-20'])->alertStage())->toBe(TenantDocument::STAGE_EXPIRED)
        // Outside the 30-day window there is nothing to chase.
        ->and(tenantDoc(['expires_on' => '2026-12-31'])->alertStage())->toBeNull()
        // A document with no renewal date is one we hold, not one we nag about.
        ->and(tenantDoc(['expires_on' => null])->alertStage())->toBeNull();
});

it('alerts once and does not re-nag on a re-run', function () {
    Notification::fake();
    chaseStaff();
    CarbonImmutable::setTestNow('2026-06-01');
    $doc = tenantDoc(['expires_on' => '2026-06-20']);

    $this->artisan('tenants:scan-document-expiry')->assertSuccessful();

    expect($doc->fresh()->alert_stage)->toBe(TenantDocument::STAGE_EXPIRING);
    Notification::assertSentTimes(TenantDocumentExpiringNotification::class, 1);

    // The scheduled-scan invariant: idempotent. A second run stamps nothing new.
    $this->artisan('tenants:scan-document-expiry')->assertSuccessful();

    Notification::assertSentTimes(TenantDocumentExpiringNotification::class, 1);
});

it('escalates to expired exactly once when the date passes', function () {
    Notification::fake();
    chaseStaff();
    CarbonImmutable::setTestNow('2026-06-01');
    $doc = tenantDoc(['expires_on' => '2026-06-20']);

    $this->artisan('tenants:scan-document-expiry')->assertSuccessful();

    // Past the date now — a different STAGE for the same expiry, so it must alert again.
    CarbonImmutable::setTestNow('2026-06-21');
    $this->artisan('tenants:scan-document-expiry')->assertSuccessful();

    expect($doc->fresh()->alert_stage)->toBe(TenantDocument::STAGE_EXPIRED);
    Notification::assertSentTimes(TenantDocumentExpiringNotification::class, 2);

    // …and then stops.
    $this->artisan('tenants:scan-document-expiry')->assertSuccessful();
    Notification::assertSentTimes(TenantDocumentExpiringNotification::class, 2);
});

it('re-arms the chase when the certificate is renewed', function () {
    Notification::fake();
    chaseStaff();
    CarbonImmutable::setTestNow('2026-06-01');
    $doc = tenantDoc(['expires_on' => '2026-06-20']);

    $this->artisan('tenants:scan-document-expiry')->assertSuccessful();
    Notification::assertSentTimes(TenantDocumentExpiringNotification::class, 1);

    // Renewed for a year — the stamp is for the OLD expiry, so the new cycle is armed by itself.
    $doc->update(['expires_on' => '2027-06-20']);
    CarbonImmutable::setTestNow('2027-06-01');
    $this->artisan('tenants:scan-document-expiry')->assertSuccessful();

    Notification::assertSentTimes(TenantDocumentExpiringNotification::class, 2);
    expect($doc->fresh()->alert_for->toDateString())->toBe('2027-06-20');
});

it('leaves a former tenant’s paperwork alone', function () {
    Notification::fake();
    chaseStaff();
    CarbonImmutable::setTestNow('2026-06-01');
    $doc = tenantDoc(['expires_on' => '2026-06-20']);
    $doc->tenant->update(['status' => 'inactive']);

    $this->artisan('tenants:scan-document-expiry')->assertSuccessful();

    expect($doc->fresh()->alert_stage)->toBeNull();
    Notification::assertNothingSent();

    // The control — active again, and the same document is chased.
    $doc->tenant->update(['status' => 'active']);
    $this->artisan('tenants:scan-document-expiry')->assertSuccessful();

    expect($doc->fresh()->alert_stage)->toBe(TenantDocument::STAGE_EXPIRING);
});

it('answers whether a tenant is currently insured', function () {
    CarbonImmutable::setTestNow('2026-06-01');
    $doc = tenantDoc(['expires_on' => '2026-12-31']);

    expect($doc->tenant->hasCurrentInsurance())->toBeTrue();

    $doc->update(['expires_on' => '2026-05-01']);

    expect($doc->tenant->fresh()->hasCurrentInsurance())->toBeFalse();

    // A tax card is not insurance — the question is type-specific, not "any document on file".
    $doc->update(['expires_on' => '2026-12-31', 'type' => TenantDocument::TYPE_TAX_CARD]);

    expect($doc->tenant->fresh()->hasCurrentInsurance())->toBeFalse();
});

it('keeps the certificate off the public disk', function () {
    // medialibrary's default disk is fail-open ('public'). A retailer's insurance certificate and
    // tax card are confidential; MediaPrivacyConformanceTest gates this project-wide, and this
    // pins it for the collection itself.
    $doc = tenantDoc();

    expect($doc->getMediaCollection('file')->diskName)->toBe('local');
});
