<?php

use App\Models\RecurringExpense;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Lang;

/**
 * SW-068 — **a standing cost that can never book is the one this screen exists to remember.**
 *
 * Four columns state a recurring schedule's window — `starts_on`, `ends_on`, `day_of_month` and
 * `frequency` — and three of them interact, so it is easy to state one that contains no scheduled
 * day at all. Nothing refused it. The row saved, sat in the register showing an amount and a
 * frequency, and `expenses:generate-recurring` skipped it every night with nothing on any screen to
 * say so. On a real-estate tax instalment or a civil-defence licence renewal that silence IS the
 * risk: the cost the operator set the schedule up to stop forgetting is the one it quietly forgets.
 *
 * Measured at HEAD (e3154f27) by calling `nextDueOn()` with a two-year horizon. All four were
 * saveable through the form, and all four answered null for ever:
 *
 *   `ends_on` 2026-09-01 before `starts_on` 2026-10-01     => null
 *   monthly,   day 1, starts 2026-09-20, ends 2026-09-30   => null
 *   quarterly, day 1, starts 2026-09-20, ends 2026-11-30   => null
 *   annually,  day 1, starts 2026-09-20, ends 2027-01-01   => null
 *
 * The last three are the shape `firstScheduledDay()`'s "the first period may not fall before the
 * schedule begins" rule creates: the first cursor steps a whole PERIOD forward, past an end date
 * that looked generous when it was typed. The first predates that rule and is plain nonsense.
 *
 * **The guard asks the same first day the walk does.** `nextDueOn()` starts at
 * `firstScheduledDay()` and `everBooks()` asks whether the window still contains it — one
 * definition of where a series begins, so *"when does it book next"* and *"can it book at all"*
 * cannot answer differently. Differentially tested against the pre-refactor walk over 51,840
 * frequency × start × end × day × stamp × horizon × active combinations: zero divergences.
 *
 * **And it must not lock anything.** `everBooks()` reads the four terms and neither `is_active` nor
 * `last_generated_on`, and the guard fires only when the WINDOW moves — so a schedule that has run
 * its course, and a dud row written before this shipped, both stay renameable, re-priceable and
 * switch-off-able. Refusing every save of such a row would take the operator's own escape away.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'NVB']);

    // `maintenance` is in the `recurring_expenses.category` FLOOR, so no catalogue seeding is
    // needed and the fixture cannot fail on reference data instead of on the thing under test.
    $this->terms = fn (array $overrides = []): array => array_merge([
        'asset_id' => $this->asset->id,
        'description' => 'Real-estate tax instalment',
        'category' => 'maintenance',
        'amount' => 48000,
        'frequency' => RecurringExpense::MONTHLY,
        'day_of_month' => 1,
        'starts_on' => '2026-09-01',
    ], $overrides);
});

it('refuses a window that contains no scheduled day at all', function (string $frequency, int $day, string $starts, string $ends) {
    expect(fn () => RecurringExpense::create(($this->terms)([
        'frequency' => $frequency,
        'day_of_month' => $day,
        'starts_on' => $starts,
        'ends_on' => $ends,
    ])))->toThrow(DomainException::class);

    expect(RecurringExpense::count())->toBe(0);
})->with([
    'end date before the start date' => [RecurringExpense::MONTHLY, 1, '2026-10-01', '2026-09-01'],
    'monthly, the first booking steps past the end' => [RecurringExpense::MONTHLY, 1, '2026-09-20', '2026-09-30'],
    'quarterly, a whole quarter past the end' => [RecurringExpense::QUARTERLY, 1, '2026-09-20', '2026-11-30'],
    'annually, a whole year past the end' => [RecurringExpense::ANNUALLY, 1, '2026-09-20', '2027-01-01'],
]);

it('accepts the windows that do book, including the one that books exactly once', function (array $overrides, string $expected) {
    $schedule = RecurringExpense::create(($this->terms)($overrides));

    expect($schedule->everBooks())->toBeTrue()
        ->and($schedule->nextDueOn(CarbonImmutable::parse('2036-01-01'))?->toDateString())->toBe($expected);
})->with([
    'no end date at all' => [[], '2026-09-01'],
    'the end date IS the first booking' => [['ends_on' => '2026-09-01'], '2026-09-01'],
    'a day clamped to a short month' => [['starts_on' => '2026-02-01', 'day_of_month' => 31], '2026-02-28'],
    'the first period lands after the start' => [['starts_on' => '2026-09-20'], '2026-10-01'],
]);

it('refuses moving a live schedule into a window it can never reach', function () {
    $schedule = RecurringExpense::create(($this->terms)());

    // Ending it BEFORE its first booking is the same defect arrived at by editing rather than by
    // creating — and this is the door an operator actually uses, because the end date is the field
    // that gets revised.
    expect(fn () => $schedule->update(['ends_on' => '2026-08-31']))
        ->toThrow(DomainException::class);

    expect($schedule->fresh()->ends_on)->toBeNull();

    // The control: an end date the series can still reach is ordinary configuration and must save.
    $schedule->update(['ends_on' => '2026-12-31']);

    expect($schedule->fresh()->ends_on->toDateString())->toBe('2026-12-31');
});

it('leaves a schedule already in that state switch-off-able and renameable', function () {
    // Manufacture the pre-guard row the way an install written before this shipped holds one:
    // `saveQuietly()` skips every model event, which is exactly what such a row looks like.
    $dud = new RecurringExpense(($this->terms)(['starts_on' => '2026-10-01', 'ends_on' => '2026-09-01']));
    $dud->saveQuietly();

    expect($dud->everBooks())->toBeFalse();

    // Both escapes, on a row the guard would refuse to CREATE: switch the dud off, and tidy it.
    $dud->update(['is_active' => false]);
    $dud->update(['description' => 'Superseded — see the 2027 assessment']);

    expect($dud->fresh()->is_active)->toBeFalse()
        ->and($dud->fresh()->description)->toBe('Superseded — see the 2027 assessment');
});

it('leaves a schedule that has run its course fully editable', function () {
    $finished = RecurringExpense::create(($this->terms)([
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-06-30',
    ]));

    // The stamp the nightly run writes once the last period is booked. The window is untouched, so
    // the guard stands aside — and this is the state a real schedule spends most of its life in.
    $finished->update(['last_generated_on' => '2026-06-01']);

    expect($finished->fresh()->nextDueOn(CarbonImmutable::parse('2036-01-01')))->toBeNull();

    $finished->update(['amount' => 51000]);

    expect((float) $finished->fresh()->amount)->toBe(51000.0);
});

it('names the day the end date has to clear, in both languages', function () {
    try {
        RecurringExpense::create(($this->terms)(['starts_on' => '2026-09-20', 'ends_on' => '2026-09-30']));
        $this->fail('a schedule that can never book anything was accepted');
    } catch (DomainException $e) {
        // A refusal with no way out is worse than the bug: it must quote the first booking the
        // operator has to make room for, and the end date that is in its way.
        expect($e->getMessage())->toContain('2026-10-01')->toContain('2026-09-30');
    }

    foreach (['en', 'ar'] as $locale) {
        // `fallback: false` — `Lang::has()` falls back to English by default, so the obvious
        // parity check only ever catches a key missing from BOTH catalogues.
        expect(Lang::has('admin.refusals.recurring_schedule_never_books', $locale, fallback: false))
            ->toBeTrue("the refusal is missing from lang/{$locale}");
    }

    // And `Lang::has()` cannot see an English sentence sitting in the right Arabic key.
    expect(__('admin.refusals.recurring_schedule_never_books', [], 'ar'))->toMatch('/\p{Arabic}/u');
});

it('answers when it books next from the same first day it answers whether it books at all', function () {
    $schedule = RecurringExpense::create(($this->terms)(['starts_on' => '2026-09-20']));

    // One definition, two readers: the walk starts here and the guard asks about here.
    expect($schedule->firstScheduledDay()->toDateString())->toBe('2026-10-01')
        ->and($schedule->nextDueOn(CarbonImmutable::parse('2026-12-01'))->toDateString())->toBe('2026-10-01');
});
