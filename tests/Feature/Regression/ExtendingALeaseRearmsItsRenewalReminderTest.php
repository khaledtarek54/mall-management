<?php

declare(strict_types=1);

use App\Models\Lease;
use App\Notifications\LeaseExpiryApproachingNotification;
use App\Services\LeaseExtensionService;
use App\Services\LeaseTerminationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/**
 * EXTENDING A LEASE RE-ARMS ITS RENEWAL REMINDER. — SW-048
 *
 * `leases.expiry_reminder_notified_at` is the idempotency stamp `leases:remind-expiring` keys on
 * (`whereNull(...)`), and nothing has ever cleared it. The stamp says *"the tenant has been told
 * about the expiry date this lease carries"* — so the moment the term is EXTENDED it is a
 * statement about a date in the middle of the tenancy, and the renewal conversation is never
 * started again for the rest of it.
 *
 * Traced on HEAD by reading every writer of the column, not by running one — this file is what
 * measures it. A lease reminded 90 days before its 2026-08-31 expiry and extended to 2029-08-31
 * keeps the stamp for three more years and reminds nobody: an ABSENCE, which is the failure class
 * nobody reports. `Lease::RENEWAL_RESET` already describes the column correctly (*"a notification
 * stamp about the original's expiry"*) — it just had no counterpart on the lease that stays.
 *
 * The rule is on the MODEL, not in `LeaseExtensionService`, because `expiry_date` is still an
 * editable field on `LeaseForm` for an un-invoiced lease and `LeaseImporter` writes it too.
 *
 * **FORWARD ONLY**, and that is the control this file carries: `LeaseTerminationService` stamps the
 * termination date onto `expiry_date` and, under notice, leaves the lease ACTIVE — so clearing on a
 * backwards move would send *"your lease is approaching expiry, start the renewal conversation"* to
 * a tenant who has already served notice. That message is outbound and cannot be recalled.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function remindableLease(string $expiry): Lease
{
    return makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active',
        'commencement_date' => '2024-01-01',
        'expiry_date' => $expiry,
        'escalation_type' => 'none',
    ]);
}

it('reminds the tenant again once the term has been extended', function () {
    Notification::fake();
    CarbonImmutable::setTestNow('2026-07-01 08:00:00');

    $lease = remindableLease('2026-08-31');

    $this->artisan('leases:remind-expiring')->assertSuccessful();

    expect($lease->fresh()->expiry_reminder_notified_at)->not->toBeNull();
    Notification::assertSentTimes(LeaseExpiryApproachingNotification::class, 1);

    app(LeaseExtensionService::class)->extend($lease->fresh(), [
        'new_expiry_date' => '2029-08-31',
        'reason' => 'further term agreed',
    ]);

    // The stamp said "we told them about 31/08/2026". That date is no longer the end of anything.
    expect($lease->fresh()->expiry_reminder_notified_at)->toBeNull();

    // …and the reminder really does fire again when the NEW expiry comes into the window, which is
    // the only assertion that proves the feature rather than the column.
    CarbonImmutable::setTestNow('2029-07-01 08:00:00');
    $this->artisan('leases:remind-expiring')->assertSuccessful();

    Notification::assertSentTimes(LeaseExpiryApproachingNotification::class, 2);
    expect($lease->fresh()->expiry_reminder_notified_at)->not->toBeNull();
});

it('re-arms from an ordinary edit too, not only from the extend action', function () {
    CarbonImmutable::setTestNow('2026-07-01 08:00:00');

    $lease = remindableLease('2026-08-31');
    $lease->forceFill(['expiry_reminder_notified_at' => CarbonImmutable::parse('2026-06-01')])->save();

    $lease->update(['expiry_date' => '2027-08-31']);

    expect($lease->fresh()->expiry_reminder_notified_at)->toBeNull();
});

it('keeps the stamp when the tenancy is pulled IN — nobody under notice is asked to renew', function () {
    Notification::fake();
    CarbonImmutable::setTestNow('2026-07-01 08:00:00');

    $lease = remindableLease('2027-12-31');
    $lease->forceFill(['expiry_reminder_notified_at' => CarbonImmutable::parse('2026-06-01')])->save();

    app(LeaseTerminationService::class)->terminate($lease->fresh(), [
        'termination_date' => CarbonImmutable::parse('2026-09-30'),
        'reason' => 'notice given',
    ]);

    $lease = $lease->fresh();

    expect($lease->status)->toBe('active')            // under notice, so the sweep can still see it
        ->and($lease->expiry_date->toDateString())->toBe('2026-09-30')
        ->and($lease->expiry_reminder_notified_at)->not->toBeNull();

    $this->artisan('leases:remind-expiring')->assertSuccessful();

    Notification::assertNothingSent();
});

it('leaves the stamp alone on an edit that does not touch the term', function () {
    CarbonImmutable::setTestNow('2026-07-01 08:00:00');

    $lease = remindableLease('2026-08-31');
    $lease->forceFill(['expiry_reminder_notified_at' => CarbonImmutable::parse('2026-06-01')])->save();

    $lease->update(['notes' => 'Rang the tenant about the renewal.']);

    expect($lease->fresh()->expiry_reminder_notified_at)->not->toBeNull();
});

it('does not re-remind a lease whose reminder is still about its own expiry', function () {
    Notification::fake();
    CarbonImmutable::setTestNow('2026-07-01 08:00:00');

    // The idempotency the stamp exists for, unchanged: two sweeps, one reminder.
    remindableLease('2026-08-31');

    $this->artisan('leases:remind-expiring')->assertSuccessful();
    $this->artisan('leases:remind-expiring')->assertSuccessful();

    Notification::assertSentTimes(LeaseExpiryApproachingNotification::class, 1);
});
