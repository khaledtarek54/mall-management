<?php

/*
|--------------------------------------------------------------------------
| A fiscal year that could only start in January (CFG-04)
|--------------------------------------------------------------------------
| `FiscalCalendar::ensureYear()` hardcoded 1 January – 31 December, and said so in its own docblock:
| "a calendar year is assumed (Jan–Dec); a fiscal year starting in another month is a future option."
|
| The reports were already honest — they read `fiscal_years.starts_on` and fall back to 1 January
| only when no row exists — so the data model supported a July year all along and NOTHING could
| create one. An entity on a July–June year would run every income statement, every year-end close
| and every period-close gate on somebody else's calendar, fixable only by a deploy.
|
| The second half is the one with teeth: moving the start month RE-DATES the periods, so a document
| posted into an open period can land inside a closed one — or an entry the accountant has closed
| and reported becomes editable again. That is refused, not warned about.
*/

use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Services\Accounting\FiscalCalendar;
use App\Settings\AccountingSettings;
use App\Support\FiscalYearStart;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(fn () => test()->seed(RolesPermissionsSeeder::class));

function startFiscalYearIn(int $month): void
{
    $settings = app(AccountingSettings::class);
    $settings->fiscal_year_start_month = $month;
    $settings->save();
}

it('still builds a calendar year when nothing is configured', function () {
    // The default, and the reason this is safe to introduce under a live database: a January start
    // must behave exactly as the hardcoded version did.
    $year = app(FiscalCalendar::class)->ensureYear(2026);

    expect($year->starts_on->toDateString())->toBe('2026-01-01')
        ->and($year->ends_on->toDateString())->toBe('2026-12-31');
});

it('builds a July year when the operator says the books start in July', function () {
    startFiscalYearIn(7);

    $year = app(FiscalCalendar::class)->ensureYear(2026);

    // Named for the year it STARTS in — stated in the setting's docblock because the convention
    // genuinely varies and both readings are defensible.
    expect($year->starts_on->toDateString())->toBe('2026-07-01')
        ->and($year->ends_on->toDateString())->toBe('2027-06-30');
});

it('walks the twelve periods forward from the start, not from January', function () {
    // The trap: `endOfYear()` on a July start gives 31 December and a silent six-month "year" that
    // still ties out. And period 1 must be the first month the entity trades in — every close
    // report orders by period_no, so a January-first ordering would read the year backwards.
    startFiscalYearIn(7);

    $year = app(FiscalCalendar::class)->ensureYear(2026);
    $periods = AccountingPeriod::where('fiscal_year_id', $year->id)->orderBy('period_no')->get();

    expect($periods)->toHaveCount(12)
        ->and($periods->first()->starts_on->toDateString())->toBe('2026-07-01')
        ->and($periods->last()->starts_on->toDateString())->toBe('2027-06-01')
        ->and($periods->last()->ends_on->toDateString())->toBe('2027-06-30');
});

it('covers every day of the year with no gap and no overlap', function () {
    // A gap is a date that belongs to no period, which the posting-date guard treats as MISSING and
    // therefore allows — so an entry would post into a month nobody can close.
    startFiscalYearIn(4);

    $year = app(FiscalCalendar::class)->ensureYear(2026);
    $periods = AccountingPeriod::where('fiscal_year_id', $year->id)->orderBy('period_no')->get();

    expect($periods->first()->starts_on->toDateString())->toBe($year->starts_on->toDateString())
        ->and($periods->last()->ends_on->toDateString())->toBe($year->ends_on->toDateString());

    foreach ($periods->sliding(2) as $pair) {
        [$earlier, $later] = [$pair->first(), $pair->last()];

        expect($earlier->ends_on->addDay()->toDateString())
            ->toBe($later->starts_on->toDateString(), 'periods must abut exactly');
    }
});

it('lets a fresh installation choose its year before anything is posted', function () {
    // The moment this setting is actually used, and the moment it is free: `atriom:install` seeds a
    // calendar and nothing is in it. Keying the guard on POSTED ENTRIES rather than on "a fiscal
    // year exists" is what keeps that possible.
    app(FiscalCalendar::class)->ensureYear(2026);

    expect(fn () => FiscalYearStart::assertChangeable(7))->not->toThrow(DomainException::class);
});

it('refuses to move the year once an entry is posted', function () {
    app(FiscalCalendar::class)->ensureYear(2026);

    JournalEntry::create([
        'asset_id' => makeAsset()->id,
        'entry_date' => '2026-03-15',
        'description' => 'posted',
        'status' => 'posted',
        'source_type' => 'manual',
    ]);

    expect(fn () => FiscalYearStart::assertChangeable(7))->toThrow(DomainException::class);

    // The control: setting it to the value it already has is not a change, and must not refuse —
    // otherwise pressing Save on the settings page would break once anything was posted.
    expect(fn () => FiscalYearStart::assertChangeable(1))->not->toThrow(DomainException::class);
});

it('ignores a draft entry, which has re-dated nothing', function () {
    // A draft posts to no period, so it cannot be stranded by moving the calendar. Refusing on one
    // would lock the setting for an install that had merely opened a form.
    JournalEntry::create([
        'asset_id' => makeAsset()->id,
        'entry_date' => '2026-03-15',
        'description' => 'draft',
        'status' => 'draft',
        'source_type' => 'manual',
    ]);

    expect(fn () => FiscalYearStart::assertChangeable(7))->not->toThrow(DomainException::class);
});

it('falls back to January rather than refusing to build a calendar at all', function () {
    // A mistyped month must not leave the system unable to open a period. Clamped, like the ageing
    // buckets — the settings screen is where the mistake gets corrected.
    foreach ([0, 13, -1, 99] as $bad) {
        startFiscalYearIn($bad);

        expect(FiscalYearStart::month())->toBe(1, "month {$bad} should clamp to January");
    }
});

it('is idempotent, so re-running the calendar changes nothing', function () {
    startFiscalYearIn(7);

    app(FiscalCalendar::class)->ensureYear(2026);
    app(FiscalCalendar::class)->ensureYear(2026);

    expect(FiscalYear::where('year', 2026)->count())->toBe(1)
        ->and(AccountingPeriod::whereHas('fiscalYear', fn ($q) => $q->where('year', 2026))->count())->toBe(12);
});
