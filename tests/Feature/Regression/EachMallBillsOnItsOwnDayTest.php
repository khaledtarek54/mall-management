<?php

/*
|--------------------------------------------------------------------------
| Each mall bills on its own day, and a manual run bills regardless
|--------------------------------------------------------------------------
| `monthly_billing_day` became a per-property override (M-5). There is one scheduler for the whole
| portfolio, so both money runs fire DAILY and ask whose day it is — and that branch shipped with no
| behavioural test at all: every existing case passes `--period` and takes the manual path.
|
| Three properties this pins, each of which was wrong or untested at some point in this change:
|   * a property bills on ITS day and not on another's;
|   * a day past the end of a short month falls back to that month's last day, rather than the mall
|     silently skipping seven months of the year;
|   * a MANUAL run bills every property whatever the date — the catch-up after a failed billing
|     night, which inferring "scheduled" from a null period had turned into a silent no-op on
|     twenty-nine days in thirty while printing "job dispatched".
*/

use App\Jobs\RunMonthlyBilling;
use App\Support\BillingDay;
use App\Support\PropertySettings;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->first = makeAsset(['name' => 'Mall A']);
    $this->second = makeAsset(['name' => 'Mall B']);
});

/** Give a property its own billing day. */
function billsOnDay(int $assetId, int $day): void
{
    PropertySettings::set('billing.monthly_billing_day', $assetId, $day);
}

it('bills a mall on its own day and not on its neighbour\'s', function () {
    billsOnDay($this->first->id, 1);
    billsOnDay($this->second->id, 25);

    $onTheFirst = BillingDay::propertiesDueOn(CarbonImmutable::parse('2026-03-01'));
    $onThe25th = BillingDay::propertiesDueOn(CarbonImmutable::parse('2026-03-25'));

    expect($onTheFirst)->toHaveKey($this->first->id)
        ->and($onTheFirst)->not->toHaveKey($this->second->id)
        // Both directions: a rule that returned everything every day would satisfy the first half.
        ->and($onThe25th)->toHaveKey($this->second->id)
        ->and($onThe25th)->not->toHaveKey($this->first->id);
});

it('bills a month-end mall on the last day of a short month', function () {
    // A property set to the 31st must still bill in February. Unclamped it would skip seven months
    // of the year — silently, because nothing anywhere reports a run that did not happen.
    billsOnDay($this->first->id, 31);

    expect(BillingDay::propertiesDueOn(CarbonImmutable::parse('2026-02-28')))
        ->toHaveKey($this->first->id);

    // …and not on some other February day.
    expect(BillingDay::propertiesDueOn(CarbonImmutable::parse('2026-02-27')))
        ->not->toHaveKey($this->first->id);

    // In a month that HAS a 31st, it bills on the 31st and not on the 30th.
    expect(BillingDay::propertiesDueOn(CarbonImmutable::parse('2026-03-31')))->toHaveKey($this->first->id)
        ->and(BillingDay::propertiesDueOn(CarbonImmutable::parse('2026-03-30')))->not->toHaveKey($this->first->id);
});

it('clamps a nonsense day rather than skipping the mall for ever', function () {
    // The column is operator-editable. A run that silently skips a mall is worse than one that bills
    // it on a neighbouring day.
    billsOnDay($this->first->id, 0);
    billsOnDay($this->second->id, 99);

    expect(BillingDay::dueDayFor($this->first->id, 31))->toBe(1)
        ->and(BillingDay::dueDayFor($this->second->id, 31))->toBe(31);
});

it('bills every property on a MANUAL run, whatever the date', function () {
    // The catch-up after a failed billing night. Keyed on an explicit flag, never on "was a period
    // given" — that inference made `billing:run-monthly --queue` a no-op on 29 days in 30 while
    // printing "job dispatched" and logging "run complete".
    billsOnDay($this->first->id, 1);
    billsOnDay($this->second->id, 25);

    CarbonImmutable::setTestNow('2026-03-14');   // nobody's billing day

    expect(BillingDay::propertiesDueOn(CarbonImmutable::now()))->toBe([],
        'The premise: on this date the scheduled sweep bills nothing.');

    $job = new RunMonthlyBilling;

    // A manual job does NOT take the due-today branch, so it reaches the service for every property.
    expect($job->dueTodayOnly)->toBeFalse();

    // …and the scheduled one does.
    expect((new RunMonthlyBilling(dueTodayOnly: true))->dueTodayOnly)->toBeTrue();
});
