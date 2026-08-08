<?php

use App\Models\Charge;
use App\Models\Lease;
use App\Services\MonthlyBillingService;
use App\Support\Vat;
use Carbon\CarbonImmutable;

/**
 * Backfilling the rent ladder onto leases that predate schedule projection.
 *
 * Projection runs at lease creation, so every lease signed before it shipped still carries a
 * single open-ended rent row — and its Charge schedule reads "no further steps scheduled" while
 * the contract says a 7% increase is due next March. That is worse than showing nothing: it is an
 * answer, and it is wrong.
 *
 * The rows this writes are the same rows the escalation sweep would create anyway, on the same
 * dates, from the same `next_escalation_date` anchor — written now, where they can be reviewed,
 * instead of on the night they take effect.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function legacyLease(array $attrs = []): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, array_merge([
        'status' => 'active',
        'commencement_date' => '2026-03-01',
        'expiry_date' => '2029-03-01',
        'base_rent_monthly' => 66000,
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 7,
        'next_escalation_date' => '2027-03-01',
        'has_marketing_levy' => false,
    ], $attrs));

    // The pre-schedule world: one open-ended row, no start_date, no ladder.
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_SEED, 'amount' => 66000, 'currency' => 'EGP',
        'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT,
        'is_active' => true,
    ]);

    return $lease->fresh();
}

it('writes nothing on a dry run', function () {
    CarbonImmutable::setTestNow('2026-08-08');
    $lease = legacyLease();

    $this->artisan('atriom:project-lease-schedules')->assertSuccessful();

    expect(Charge::where('lease_id', $lease->id)->count())->toBe(1);
});

it('backfills the ladder from the lease\'s own next anniversary, not from commencement', function () {
    CarbonImmutable::setTestNow('2026-08-08');
    $lease = legacyLease();

    $this->artisan('atriom:project-lease-schedules', ['--commit' => true])->assertSuccessful();

    $rent = Charge::where('lease_id', $lease->id)->where('type', 'base_rent')
        ->orderBy('start_date')->get();

    expect($rent)->toHaveCount(4)
        // The original row is closed the day before the first contracted step.
        ->and((float) $rent[0]->amount)->toBe(66000.0)
        ->and($rent[0]->end_date->toDateString())->toBe('2027-02-28')
        ->and((float) $rent[1]->amount)->toBe(70620.0)          // 66,000 + 7%
        ->and($rent[1]->start_date->toDateString())->toBe('2027-03-01')
        ->and((float) $rent[2]->amount)->toBe(75563.4)
        ->and($rent[2]->start_date->toDateString())->toBe('2028-03-01');
});

it('never re-dates a month that has already been billed', function () {
    CarbonImmutable::setTestNow('2026-08-08');
    $lease = legacyLease();

    $this->artisan('atriom:project-lease-schedules', ['--commit' => true])->assertSuccessful();

    // A month before the first step still bills the rent that was in force then.
    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-08-01'))['invoice'];

    expect((float) $invoice->items()->where('type', 'base_rent')->sole()->amount)->toBe(66000.0);
});

it('is idempotent — a second run adds nothing', function () {
    CarbonImmutable::setTestNow('2026-08-08');
    $lease = legacyLease();

    $this->artisan('atriom:project-lease-schedules', ['--commit' => true])->assertSuccessful();
    $after = Charge::where('lease_id', $lease->id)->count();

    $this->artisan('atriom:project-lease-schedules', ['--commit' => true])->assertSuccessful();

    expect(Charge::where('lease_id', $lease->id)->count())->toBe($after);
});

it('leaves a lease with no escalation clause alone', function () {
    CarbonImmutable::setTestNow('2026-08-08');
    $lease = legacyLease(['escalation_type' => 'none', 'escalation_rate' => 0, 'next_escalation_date' => null]);

    $this->artisan('atriom:project-lease-schedules', ['--commit' => true])->assertSuccessful();

    expect(Charge::where('lease_id', $lease->id)->count())->toBe(1);
});

it('agrees with what the escalation sweep would have produced', function () {
    // The backfill must not invent a different answer from the sweep it front-runs. Two identical
    // leases: one laddered up front, one left to the sweep. Same amount, same date.
    CarbonImmutable::setTestNow('2026-08-08');
    $projected = legacyLease();
    $swept = legacyLease();

    $this->artisan('atriom:project-lease-schedules', ['--lease' => $projected->id, '--commit' => true])
        ->assertSuccessful();

    CarbonImmutable::setTestNow('2027-03-01');
    app(\App\Services\RentEscalationService::class)->runForToday(CarbonImmutable::parse('2027-03-01'));

    $billing = app(MonthlyBillingService::class);
    $march = CarbonImmutable::parse('2027-03-01');

    expect((float) $billing->generateForLease($projected->fresh(), $march)['invoice']
        ->items()->where('type', 'base_rent')->sole()->amount)
        ->toBe((float) $billing->generateForLease($swept->fresh(), $march)['invoice']
            ->items()->where('type', 'base_rent')->sole()->amount)
        ->and((float) $projected->fresh()->base_rent_monthly)->toBe(70620.0);
});
