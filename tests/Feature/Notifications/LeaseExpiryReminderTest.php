<?php

use App\Notifications\LeaseExpiryApproachingNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Phase 2b — lease-expiry renewal reminder (email + bell + mobile push)
|--------------------------------------------------------------------------
| leases:remind-expiring reminds the tenant once per active lease whose
| expiry_date falls within the reminder window (default 90 days), stamping
| leases.expiry_reminder_notified_at. Renewals are new lease rows, so each
| lease reminds for its own expiry.
*/

function expiringLease(array $attrs = [])
{
    return makeLease(makeUnit(makeAsset()), null, array_merge([
        'status' => 'active',
        'expiry_date' => now()->addDays(30), // inside the 90-day window
    ], $attrs));
}

it('reminds the tenant about an active lease approaching expiry and stamps it', function () {
    Notification::fake();
    $lease = expiringLease();

    expect($lease->expiry_reminder_notified_at)->toBeNull();

    $this->artisan('leases:remind-expiring')
        ->expectsOutputToContain('Reminded tenants on 1 of 1 expiring lease(s).')
        ->assertExitCode(0);

    Notification::assertSentTo($lease->tenant, LeaseExpiryApproachingNotification::class);
    expect($lease->refresh()->expiry_reminder_notified_at)->not->toBeNull();
});

it('is idempotent — re-running does not remind the same lease twice', function () {
    Notification::fake();
    $lease = expiringLease();

    $this->artisan('leases:remind-expiring')->assertExitCode(0);
    $firstStamp = $lease->refresh()->expiry_reminder_notified_at;

    $this->artisan('leases:remind-expiring')
        ->expectsOutputToContain('No leases approaching expiry.')
        ->assertExitCode(0);

    Notification::assertSentToTimes($lease->tenant, LeaseExpiryApproachingNotification::class, 1);
    expect($lease->refresh()->expiry_reminder_notified_at->equalTo($firstStamp))->toBeTrue();
});

it('reminds for a lease expiring today (inclusive lower boundary)', function () {
    Notification::fake();
    $lease = expiringLease(['expiry_date' => now()]);

    $this->artisan('leases:remind-expiring')
        ->expectsOutputToContain('Reminded tenants on 1 of 1 expiring lease(s).')
        ->assertExitCode(0);

    Notification::assertSentTo($lease->tenant, LeaseExpiryApproachingNotification::class);
});

it('does NOT remind for a lease expiring beyond the reminder window', function () {
    Notification::fake();
    $lease = expiringLease(['expiry_date' => now()->addDays(120)]); // outside 90 days

    $this->artisan('leases:remind-expiring')
        ->expectsOutputToContain('No leases approaching expiry.')
        ->assertExitCode(0);

    Notification::assertNothingSent();
    expect($lease->refresh()->expiry_reminder_notified_at)->toBeNull();
});

it('does NOT remind for a non-active lease even if it is inside the window', function () {
    Notification::fake();
    $lease = expiringLease(['status' => 'terminated']);

    $this->artisan('leases:remind-expiring')
        ->expectsOutputToContain('No leases approaching expiry.')
        ->assertExitCode(0);

    Notification::assertNothingSent();
});

it('does NOT remind for a lease that has already expired', function () {
    Notification::fake();
    $lease = expiringLease(['expiry_date' => now()->subDays(5)]); // already past

    $this->artisan('leases:remind-expiring')
        ->expectsOutputToContain('No leases approaching expiry.')
        ->assertExitCode(0);

    Notification::assertNothingSent();
});

it('LeaseExpiryApproachingNotification routes through mail, database and push', function () {
    $lease = expiringLease();
    $via = (new LeaseExpiryApproachingNotification($lease))->via($lease->tenant);

    expect($via)->toEqualCanonicalizing(['mail', 'database', 'push']);
});

it('writes the notification rows through the real queue path (no fake)', function () {
    $lease = expiringLease();
    makeTenantUser($lease->tenant); // notifyPortal fans to Tenant + this portal login

    $this->artisan('leases:remind-expiring')->assertExitCode(0);

    expect(DB::table('notifications')->where('data', 'like', '%lease_expiry_approaching%')->count())->toBe(2);
});
