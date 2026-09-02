<?php

use App\Contracts\DeliverableReport;
use App\Filament\Admin\Pages\ArAging;
use App\Filament\Admin\Pages\IncomeStatement;
use App\Filament\Admin\Pages\VatReturn;
use App\Filament\Admin\Pages\VendorScorecard;
use App\Filament\Admin\Pages\WithholdingTaxReturn;
use App\Mail\SavedReportDelivered;
use App\Models\SavedReport;
use App\Services\Reports\DeliverSavedReportService;
use App\Settings\AccountingSettings;
use App\Support\ReportCatalogue;
use App\Support\ReportParameters;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

/**
 * "SEND EVERY MONTH" REPLAYED THE DATE FROZEN AT SAVE TIME, FOR EVER.
 *
 * A `SavedReport` snapshots every declared parameter of its page, `DeliverSavedReportService`
 * re-applies them, and a report page derives its period from `now()` in `mount()`. So the frozen
 * value overwrote the fresh one: September's ageing was emailed to the owner's accountant in
 * October, in November, and every month after that.
 *
 * **Nothing errors and the CSV arrives on time.** The only tell is that the numbers never move,
 * which is the failure a recipient notices last if at all — and the recipients here are routinely
 * outside the business, invited precisely because they have no login and therefore no other way to
 * check.
 *
 * The period is DROPPED at delivery rather than rewritten: `ReportParameters::apply()` skips a key
 * it is not given, so the page keeps the default its own `mount()` just produced. One definition of
 * what "this month" means, on the page that owns the question — the alternative would be a second
 * copy of every report's period arithmetic living in the delivery service.
 *
 * `ReportCatalogue::REPORTING_PERIOD` is which parameters those are, in two camps with a gate over
 * both, so the next deliverable report cannot ship undecided.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Mail::fake();

    $this->asset = makeAsset(['code' => 'RPT']);
    $this->owner = makeUser('super_admin', [$this->asset->id]);
});

it('emails the CURRENT period, three months after the view was saved', function () {
    // End to end, through the real service and the real mail. The as-at date is in the CSV
    // FILENAME — the page puts it there so an exported worklist can be reconciled to the day it was
    // aged at — so the attachment itself says which date was used, and nothing has to inflate a CSV
    // to find out.
    CarbonImmutable::setTestNow('2026-09-15');

    $saved = SavedReport::create([
        'user_id' => $this->owner->id,
        'report' => ReportCatalogue::REPORTS[ArAging::class]['key'],
        'name' => 'Ageing for the auditor',
        'parameters' => [
            'asOf' => '2026-09-15',
            'bucket' => 'd_31_60',
            ReportParameters::PROPERTY_KEY => $this->asset->id,
        ],
        'recipients' => ['auditor@example.test'],
        'frequency' => 'monthly',
    ]);

    // Three months later, the schedule fires again.
    CarbonImmutable::setTestNow('2026-12-01');

    expect(app(DeliverSavedReportService::class)->deliver($saved))->toBeTrue();

    Mail::assertSent(SavedReportDelivered::class, function (SavedReportDelivered $mail): bool {
        // December's ageing, and the bucket the operator chose — the moment moved, the shape did not.
        return str_contains($mail->filename, '2026-12-01')
            && str_contains($mail->filename, 'd_31_60')
            && ! str_contains($mail->filename, '2026-09-15');
    });

    CarbonImmutable::setTestNow();
});

it('moves the period and keeps everything else', function () {
    $applied = ReportPeriod::advance(ArAging::class, [
        'asOf' => '2026-09-15',
        'bucket' => 'd_31_60',
        ReportParameters::PROPERTY_KEY => $this->asset->id,
    ], CarbonImmutable::parse('2026-12-01'));

    expect($applied['asOf'])->toBe('2026-12-01')
        // The operator's SHAPE survives: the ageing bucket they chose is not a moment.
        ->and($applied['bucket'])->toBe('d_31_60')
        ->and($applied)->toHaveKey(ReportParameters::PROPERTY_KEY);
});

it('keeps a MONTHLY ledger period monthly — the repair that dropping it got wrong', function () {
    // **Dropping the period was worse than the staleness it fixed.** A null `period` does not mean
    // "this month" on `ScopesLedgerReport` — it means the whole fiscal year. So a monthly VAT return
    // saved for March came out as the YEAR's cumulative net payable, on a document Egypt files
    // monthly, whose CSV rows carry no period line at all: the wrong amount, looking fresh, which is
    // what makes it likelier to be filed than a stale one.
    $applied = ReportPeriod::advance(VatReturn::class, [
        'year' => 2026,
        'period' => '2026-03',
    ], CarbonImmutable::parse('2026-12-01'));

    // The month just ENDED, which is what a monthly statutory return is filed for — and no page's
    // own mount() can produce it.
    expect($applied['period'])->toBe('2026-11')
        ->and($applied['year'])->toBe(2026);
});

it('keeps a QUARTERLY period quarterly, and a whole-year one whole-year', function () {
    // Form 41 is quarterly, and `SavedReport::isDueOn()` only knows weekly and monthly — so a
    // quarterly return is necessarily on a monthly schedule, which is exactly where dropping the
    // period turned a quarter into a year.
    $quarterly = ReportPeriod::advance(WithholdingTaxReturn::class, [
        'year' => 2026, 'period' => '2026-Q1',
    ], CarbonImmutable::parse('2026-12-01'));

    expect($quarterly['period'])->toBe('2026-Q3')
        ->and($quarterly['year'])->toBe(2026);

    // On a CALENDAR year — the default — a fiscal quarter and Carbon's are the same three months,
    // which is exactly why the difference below was easy to miss.
});

it('advances a quarter of the FISCAL year, not of the calendar', function () {
    // **A scheduled Form 41 was going out on a quarter that had not finished.**
    // `WithholdingTaxReturn::periodOptions()` builds `YYYY-Qn` by stepping three months from
    // `fiscalYearStart()`, so on an April year Q1 is Apr–Jun and Q2 is Jul–Sep. The advance read
    // Carbon's `startOfQuarter()`/`->quarter`, which are CALENDAR quarters: on 15 Aug 2026 it
    // answered `2026-Q2`, and that page renders Q2 as **Jul–Sep 2026 — the quarter still running**.
    // A partial quarter is not a stale report, it is a wrong filing position.
    app(AccountingSettings::class)->fill(['fiscal_year_start_month' => 4])->save();

    // 15 Aug 2026 is inside FY2026's Q2 (Jul–Sep), so the last COMPLETE one is Q1 (Apr–Jun 2026).
    $q = ReportPeriod::advance(WithholdingTaxReturn::class, [
        'year' => 2026, 'period' => '2026-Q4',
    ], CarbonImmutable::parse('2026-08-15'));

    expect($q['period'])->toBe('2026-Q1')
        ->and($q['year'])->toBe(2026);

    // And in JANUARY, which is the tail of the fiscal year that opened the previous April: the last
    // complete quarter is Oct–Dec, i.e. FY2026 Q3 — not "2027-Q4" as a calendar reading gives.
    $tail = ReportPeriod::advance(WithholdingTaxReturn::class, [
        'year' => 2026, 'period' => '2026-Q1',
    ], CarbonImmutable::parse('2027-01-20'));

    expect($tail['period'])->toBe('2026-Q3')
        ->and($tail['year'])->toBe(2026);
});

it('names the FISCAL year a monthly period falls in', function () {
    // Same distinction, cheaper to get wrong: on an April year February 2027 belongs to FY2026, and
    // naming 2027 sends the report to a year whose own picker does not offer that month — so the
    // membership guard in `ScopesLedgerReport` discards the period and the report opens on twelve
    // months nobody asked for.
    app(AccountingSettings::class)->fill(['fiscal_year_start_month' => 4])->save();

    $monthly = ReportPeriod::advance(IncomeStatement::class, [
        'year' => 2026, 'period' => '2026-06',
    ], CarbonImmutable::parse('2027-03-05'));

    expect($monthly['period'])->toBe('2027-02')
        ->and($monthly['year'])->toBe(2026);
});

it('keeps a whole-year period whole-year', function () {

    // Null IS a shape — the whole year — and it moves to the current year rather than becoming a
    // month.
    $annual = ReportPeriod::advance(IncomeStatement::class, [
        'year' => 2025, 'period' => null,
    ], CarbonImmutable::parse('2026-12-01'));

    expect($annual['period'])->toBeNull()
        ->and($annual['year'])->toBe(2026);
});

it('moves a from/to window forward and keeps its LENGTH', function () {
    // Dropping these reset a one-quarter vendor scorecard to the page's hardcoded rolling twelve
    // months — roughly four times the volume. The operator's shape went out with their moment.
    $applied = ReportPeriod::advance(VendorScorecard::class, [
        'from' => '2026-01-01', 'to' => '2026-03-31',
    ], CarbonImmutable::parse('2026-12-01'));

    expect($applied['to'])->toBe('2026-12-01')
        // 89 days, the same span, ending today.
        ->and($applied['from'])->toBe('2026-09-03');
});

it('leaves a period shape it does not understand exactly as saved', function () {
    // Better a stale period a recipient can spot than a confidently rewritten one in a shape nobody
    // here parsed.
    $applied = ReportPeriod::advance(IncomeStatement::class, [
        'year' => 2026, 'period' => 'H1-2026',
    ], CarbonImmutable::parse('2026-12-01'));

    expect($applied['period'])->toBe('H1-2026')
        ->and($applied['year'])->toBe(2026);
});

it('leaves the page carrying the period its own mount() derived', function () {
    CarbonImmutable::setTestNow('2026-12-01');

    $page = app(ArAging::class);
    $page->mount();

    // The control: a fresh mount is already on today.
    expect($page->asOf)->toBe('2026-12-01');

    // Applying the delivery's parameters must not move it back to September.
    ReportParameters::apply($page, ReportPeriod::advance(ArAging::class, [
        'asOf' => '2026-09-15',
        'bucket' => 'd_31_60',
    ]));

    expect($page->asOf)->toBe('2026-12-01')
        ->and($page->bucket)->toBe('d_31_60');

    CarbonImmutable::setTestNow();
});

it('still re-applies the period in the BROWSER — a link is a moment, a schedule is a cadence', function () {
    // The control that matters most: opening a saved view must reproduce exactly what was saved.
    // A fix that dropped the period everywhere would satisfy every assertion above and quietly make
    // every saved view un-reproducible.
    CarbonImmutable::setTestNow('2026-12-01');

    $page = app(ArAging::class);
    $page->mount();

    ReportParameters::apply($page, ['asOf' => '2026-09-15', 'bucket' => 'd_31_60']);

    expect($page->asOf)->toBe('2026-09-15');

    CarbonImmutable::setTestNow();
});

it('classifies every deliverable report — period, or a stated reason for having none', function () {
    // The gate. A report in neither camp is one whose schedule would silently freeze, and the point
    // of the registry is that the next one cannot ship undecided.
    $unclassified = [];
    $stale = [];
    $examined = 0;

    foreach (ReportCatalogue::REPORTS as $page => $meta) {
        if (! is_a($page, DeliverableReport::class, true)) {
            continue;
        }

        $examined++;

        $hasPeriod = array_key_exists($page, ReportCatalogue::REPORTING_PERIOD);
        $hasReason = array_key_exists($page, ReportCatalogue::NO_REPORTING_PERIOD);

        if ($hasPeriod === $hasReason) {
            $unclassified[] = class_basename($page);

            continue;
        }

        // …and every named parameter must still exist on the page. A renamed one silently stops
        // being dropped, which is the original bug back with a green build.
        $declared = array_keys(ReportParameters::parametersOf($page));

        foreach (ReportCatalogue::reportingPeriodOf($page) as $name) {
            if (! in_array($name, $declared, true)) {
                $stale[] = class_basename($page).'::$'.$name;
            }
        }
    }

    expect($unclassified)->toBe([], 'These deliverable reports are in neither REPORTING_PERIOD nor '
        .'NO_REPORTING_PERIOD, so a schedule for them would freeze on the day it was saved: '
        .implode(', ', $unclassified))
        ->and($stale)->toBe([], 'These period parameters no longer exist on their page, so they are '
            .'no longer dropped at delivery: '.implode(', ', $stale));

    // **What the LOOP examined, not what the constant holds.** Counting the registry would stay
    // green if `is_a($page, DeliverableReport::class, true)` ever answered false for everything —
    // the interface renamed, or moved — and the sweep then classifies nothing while both assertions
    // above pass on empty arrays. That is the "a gate can report on a set it has silently stopped
    // collecting" shape this project has recorded three times.
    expect($examined)->toBeGreaterThan(15);
});
