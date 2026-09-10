<?php

/*
|--------------------------------------------------------------------------
| A scan reports its finding even when the message cannot be delivered (SW-244)
|--------------------------------------------------------------------------
| Found on the staging soak, 2026-09-10, five days in: `pdc:scan-coverage` had been failing with
| exit code 1 every Monday and the scheduler logged an error naming nothing an operator could act on.
|
| The transport was answering `403 Forbidden` (a broken mail token — an environment condition), and
| **35 of this app's 38 notifications send inline**, so the exception unwound the whole command. Three
| things went with it, in increasing order of seriousness: the command returned non-zero; the leases
| behind the first one were never notified; and — because the service recorded its finding AFTER the
| delivery loop — `OpsLog` never wrote `pdc.coverage_ending` at all.
|
| That last one is the defect. This scan reports which tenants are about to run OUT of lodged
| cheques, and its own docblock says why it had to exist: **the failure is the ABSENCE of a row**, so
| no query over the rows that DO exist can find it. Erasing the ops-log record therefore does not
| degrade the signal, it removes the only copy — and the daily soak report shows nothing missing,
| because nothing happened.
|
| Two changes, and the first is the one that matters:
|   1. the finding is recorded BEFORE anything is delivered — delivery is best effort, the finding
|      is the product;
|   2. delivery goes through `App\Support\BestEffortNotification`, so one lease's recipients failing
|      neither stops the lease behind it nor fails the command.
|
| Every refusal here is paired with a control that must still DELIVER, because a "fix" that simply
| stopped sending notifications would satisfy all four failure assertions.
*/

use App\Models\Lease;
use App\Models\PostDatedCheque;
use App\Models\WorkPermit;
use App\Notifications\ChequeCoverageEndingNotification;
use App\Services\AssetStaffRecipients;
use App\Services\ScanChequeCoverageService;
use App\Services\WorkPermitService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->manager = makeUser('manager', [$this->asset->id]);
});

/** A lease whose lodged cheques stop short of its term — the shape this scan exists to find. */
function sw244LeaseRunningOut($ctx, string $lastChequeDate = '2026-10-01', string $expiry = '2027-12-31'): Lease
{
    $lease = makeLease(makeUnit($ctx->asset), null, ['expiry_date' => $expiry]);

    PostDatedCheque::create([
        'reference' => 'PDC-'.uniqid(),
        'asset_id' => $ctx->asset->id,
        'tenant_id' => $lease->tenant_id,
        'lease_id' => $lease->id,
        'cheque_number' => (string) random_int(100000, 999999),
        'bank_name' => 'CIB',
        'amount' => 25000,
        'currency' => 'EGP',
        'cheque_date' => $lastChequeDate,
        'received_date' => '2026-01-05',
        'status' => PostDatedCheque::STATUS_HELD,
    ]);

    return $lease;
}

it('records the finding even when the notification cannot be delivered', function () {
    sw244LeaseRunningOut($this);

    Notification::shouldReceive('send')->andThrow(new RuntimeException('[status code] 403 Forbidden'));

    $result = null;
    $ops = captureOpsLog(function () use (&$result) {
        $result = app(ScanChequeCoverageService::class)->run();
    });

    // The scan COMPLETED — it did not unwind on the delivery failure.
    expect($result['ending'])->toBe(1);

    // …and the finding itself is on the record, which is the whole point of this scan.
    expect(collect($ops)->pluck('message'))->toContain('pdc.coverage_ending');

    // The delivery failure is reported too — as a WARNING, because a broken transport is an
    // environment condition and the daily check reads ERROR lines.
    $failure = collect($ops)->firstWhere('message', 'notification.delivery_failed');
    expect($failure)->not->toBeNull()
        ->and($failure['level'])->toBe('warning')
        ->and($failure['context']['scan'])->toBe('pdc:scan-coverage');
});

it('records the finding even when the run DIES resolving who to tell', function () {
    // The catch covers DELIVERY. Resolving the recipients happens before it and is deliberately
    // left loud — a directory that cannot answer is a fault, not an environment condition. So this
    // is the failure that proves the ORDER: the run still dies, and the finding is already on the
    // record when it does. With the ops-log line back after the delivery loop, it is not.
    sw244LeaseRunningOut($this);

    $this->app->bind(AssetStaffRecipients::class, function () {
        $broken = Mockery::mock(AssetStaffRecipients::class);
        $broken->shouldReceive('for')->andThrow(new RuntimeException('staff directory unavailable'));

        return $broken;
    });

    $died = null;
    $ops = captureOpsLog(
        fn () => app(ScanChequeCoverageService::class)->run(),
        allowFailure: true,
        caught: $died,
    );

    // Both halves, or this pins nothing: the run really DID die here (the review proved that
    // asserting only the log line passes even with the order reverted AND the resolution guarded)…
    expect($died)->toBeInstanceOf(RuntimeException::class);

    // …and the finding was already on the record when it did.
    expect(collect($ops)->pluck('message'))->toContain('pdc.coverage_ending');
});

it('survives an ERROR, not just an Exception — which is what Throwable buys', function () {
    // The docblock's longest paragraph defends catching `Throwable`, and narrowing it to
    // `\Exception` passed every other case here: both of them throw a RuntimeException.
    sw244LeaseRunningOut($this);

    Notification::shouldReceive('send')->andThrow(new TypeError('Argument #1 must be of type Notifiable'));

    $result = null;
    $ops = captureOpsLog(function () use (&$result) {
        $result = app(ScanChequeCoverageService::class)->run();
    });

    expect($result['ending'])->toBe(1)
        ->and(collect($ops)->firstWhere('message', 'notification.delivery_failed')['context']['exception'])
        ->toBe(TypeError::class);
});

it('still tells the second lease when the first one fails', function () {
    sw244LeaseRunningOut($this);
    sw244LeaseRunningOut($this, '2026-11-01');

    // Mockery verifies the count on teardown: one throw must not swallow the lease behind it.
    Notification::shouldReceive('send')->twice()->andThrow(new RuntimeException('transport down'));

    $result = null;
    captureOpsLog(function () use (&$result) {
        $result = app(ScanChequeCoverageService::class)->run();
    });

    expect($result['ending'])->toBe(2);
});

it('exits SUCCESS rather than failing the scheduled run', function () {
    sw244LeaseRunningOut($this);

    Notification::shouldReceive('send')->andThrow(new RuntimeException('[status code] 403 Forbidden'));

    captureOpsLog(function () {
        $this->artisan('pdc:scan-coverage')->assertSuccessful();
    });
});

it('the permit scan survives a delivery failure too', function () {
    // Reverting `ScanOpenWorkPermitsCommand` to a raw `Notification::send()` turned nothing red
    // until this existed — every other case here drives the cheque scan.
    $second = makeAsset(['code' => 'PW2']);
    makeUser('operations', [$second->id]);

    Date::setTestNow(CarbonImmutable::parse('2026-09-01 10:00'));

    foreach ([$this->asset, $second] as $asset) {
        $permit = WorkPermit::create([
            'asset_id' => $asset->id,
            'type' => WorkPermit::TYPE_HOT_WORK,
            'description' => 'Welding a bracket in the roof plant room.',
            'conditions' => 'Fire watch present. Extinguisher on site. Gas test before start.',
            'valid_from' => CarbonImmutable::parse('2026-09-01 09:00'),
            'valid_to' => CarbonImmutable::parse('2026-09-01 13:00'),
        ]);

        app(WorkPermitService::class)->issue($permit);
    }

    // A day later both windows have passed unclosed, and both malls' recipients fail.
    Date::setTestNow(CarbonImmutable::parse('2026-09-02 09:00'));

    Notification::shouldReceive('send')->twice()->andThrow(new RuntimeException('transport down'));

    captureOpsLog(function () {
        $this->artisan('facility:scan-open-permits')->assertSuccessful();
    });

    Date::setTestNow();
});

it('still delivers the notification when nothing is wrong — the control', function () {
    $lease = sw244LeaseRunningOut($this);

    Notification::fake();

    $result = app(ScanChequeCoverageService::class)->run();

    expect($result['ending'])->toBe(1);

    Notification::assertSentTo($this->manager, ChequeCoverageEndingNotification::class);
});
