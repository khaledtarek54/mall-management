<?php

/*
|--------------------------------------------------------------------------
| A tenant runs out of lodged cheques while the lease runs on (2026-08-19)
|--------------------------------------------------------------------------
| Egyptian practice is that a tenant lodges a YEAR of post-dated cheques against a lease that
| usually runs longer. So running dry mid-term is not an edge case, it is the normal shape of the
| arrangement — and nothing in the system could see it.
|
| The reason it is invisible is worth stating, because it is why a new sweep was needed rather
| than a filter on an existing screen: right up until the month the money stops, everything looks
| correct. Every lodged cheque clears on its date, the register is green, `pdc:scan-maturing`
| reports nothing because nothing is late. The failure is the ABSENCE of a row, and no query over
| the rows that exist can find it.
|
| `pdc:scan-coverage` asks the opposite question: for each active lease, how far do the cheques
| still awaiting collection reach, and does the term run past that?
|
| No benchmark — the Western tools this project measures against do not model post-dated cheques
| at all (gap analysis §0). Judged against Egyptian practice.
*/

use App\Models\Lease;
use App\Models\PostDatedCheque;
use App\Notifications\ChequeCoverageEndingNotification;
use App\Services\ScanChequeCoverageService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    // Someone has to be there to be told. `AssetStaffRecipients` unions the property team with
    // every super_admin, so this is the minimum that makes a fan-out observable.
    $this->manager = makeUser('manager', [$this->asset->id]);
    Notification::fake();
});

/** A lease with cheques lodged up to (but not beyond) `$lastChequeDate`. */
function leaseCoveredTo(
    $ctx,
    string $lastChequeDate,
    string $expiry = '2027-12-31',
    string $status = PostDatedCheque::STATUS_HELD,
): Lease {
    $unit = makeUnit($ctx->asset);
    $lease = makeLease($unit, null, ['expiry_date' => $expiry]);

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
        'status' => $status,
    ]);

    return $lease;
}

it('reports a lease whose last lodged cheque falls short of the term', function () {
    $lease = leaseCoveredTo($this, '2026-10-01', expiry: '2027-12-31');

    $result = app(ScanChequeCoverageService::class)
        ->run(60, CarbonImmutable::parse('2026-09-01'));

    expect($result['ending'])->toBe(1)
        ->and($result['leases'][0]['lease_id'])->toBe($lease->id)
        ->and($result['leases'][0]['covered_to'])->toBe('2026-10-01')
        // The size of the ask is the actionable half — "get more cheques" without a number is
        // a reminder, not an instruction.
        ->and($result['leases'][0]['uncovered_months'])->toBeGreaterThan(12);

    Notification::assertSentTimes(ChequeCoverageEndingNotification::class, 1);
});

/**
 * The control. Every refusal above would pass just as happily if the sweep reported EVERY lease,
 * so a tenant whose cheques cover the whole term must come back clean.
 */
it('says nothing about a lease covered past its expiry', function () {
    leaseCoveredTo($this, '2028-01-01', expiry: '2027-12-31');

    $result = app(ScanChequeCoverageService::class)
        ->run(60, CarbonImmutable::parse('2026-09-01'));

    expect($result['ending'])->toBe(0);
    Notification::assertNothingSent();
});

/**
 * The horizon is what keeps this alert worth reading. A lease that runs dry in two years is a
 * true statement and a useless one — send it now and the operator learns to ignore the alert
 * before the month it matters.
 */
it('stays quiet while the last cheque is beyond the horizon', function () {
    leaseCoveredTo($this, '2027-06-01', expiry: '2027-12-31');

    $result = app(ScanChequeCoverageService::class)
        ->run(60, CarbonImmutable::parse('2026-09-01'));

    expect($result['ending'])->toBe(0);
    Notification::assertNothingSent();
});

/**
 * A CLEARED cheque is the happy outcome and still must not count towards coverage — the whole
 * question is what is lodged for the months AHEAD, and a banked cheque answers nothing about
 * them. Counting it would make a lease look covered by the very instrument that was consumed,
 * which is precisely the failure this sweep exists to catch.
 */
it('does not count a cleared cheque as forward coverage', function () {
    leaseCoveredTo($this, '2026-10-01', expiry: '2027-12-31', status: PostDatedCheque::STATUS_CLEARED);

    $result = app(ScanChequeCoverageService::class)
        ->run(60, CarbonImmutable::parse('2026-09-01'));

    // No AWAITING cheque at all, so this lease has no cheque arrangement to run out — and a lease
    // with no lodged cheques is deliberately not reported (see below).
    expect($result['ending'])->toBe(0);
});

/**
 * A lease with no cheques at all is a tenant who pays by transfer. Alerting on those would fire
 * for most of the portfolio on the first run, and an alert that is usually noise is an alert that
 * gets filtered into a folder nobody opens.
 */
it('ignores a lease that never lodged a cheque', function () {
    $unit = makeUnit($this->asset);
    makeLease($unit, null, ['expiry_date' => '2027-12-31']);

    $result = app(ScanChequeCoverageService::class)
        ->run(60, CarbonImmutable::parse('2026-09-01'));

    expect($result['scanned'])->toBe(0)
        ->and($result['ending'])->toBe(0);
});

/** An ended tenancy is not something to chase cheques for. */
it('ignores a lease that is no longer active', function () {
    $lease = leaseCoveredTo($this, '2026-10-01', expiry: '2027-12-31');
    $lease->forceFill(['status' => 'terminated'])->saveQuietly();

    $result = app(ScanChequeCoverageService::class)
        ->run(60, CarbonImmutable::parse('2026-09-01'));

    expect($result['ending'])->toBe(0);
});

it('is reachable from the console', function () {
    leaseCoveredTo($this, '2026-10-01', expiry: '2027-12-31');

    $this->artisan('pdc:scan-coverage', ['--days' => 60, '--date' => '2026-09-01'])
        ->assertExitCode(0);

    Notification::assertSentTimes(ChequeCoverageEndingNotification::class, 1);
});

/**
 * A cheque arrangement is one mall's relationship with its tenant. A manager pinned to another
 * property can do nothing about it, and telling them is how an alert becomes background noise for
 * the people who CAN act.
 *
 * Paired with the control above rather than asserted alone: a fan-out that reached nobody would
 * satisfy this refusal just as well as a correctly scoped one.
 */
it('tells the lease property\'s team and not another mall\'s', function () {
    $otherAsset = makeAsset();
    $otherManager = makeUser('manager', [$otherAsset->id]);

    leaseCoveredTo($this, '2026-10-01', expiry: '2027-12-31');

    app(ScanChequeCoverageService::class)->run(60, CarbonImmutable::parse('2026-09-01'));

    Notification::assertSentTo($this->manager, ChequeCoverageEndingNotification::class);
    Notification::assertNotSentTo($otherManager, ChequeCoverageEndingNotification::class);
});
