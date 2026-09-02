<?php

use App\Filament\Admin\Pages\ArAging;
use App\Models\SavedReport;
use App\Services\Reports\DeliverSavedReportService;
use App\Support\ReportCatalogue;
use App\Support\ReportParameters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Database\Seeders\RolesPermissionsSeeder;
use App\Mail\SavedReportDelivered;
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

it('drops the frozen period and keeps everything else', function () {
    // The parameters as the delivery applies them — which is the whole of the change, and the only
    // place the answer is visible without inflating a CSV.
    $saved = [
        'asOf' => '2026-09-15',
        'bucket' => 'd_31_60',
        ReportParameters::PROPERTY_KEY => $this->asset->id,
    ];

    $applied = Arr::except($saved, ReportCatalogue::reportingPeriodOf(ArAging::class));

    expect($applied)->not->toHaveKey('asOf')
        // The operator's SHAPE survives: the ageing bucket they chose is not a moment.
        ->and($applied)->toHaveKey('bucket')
        ->and($applied['bucket'])->toBe('d_31_60')
        ->and($applied)->toHaveKey(ReportParameters::PROPERTY_KEY);
});

it('leaves the page carrying the period its own mount() derived', function () {
    CarbonImmutable::setTestNow('2026-12-01');

    $page = app(ArAging::class);
    $page->mount();

    // The control: a fresh mount is already on today.
    expect($page->asOf)->toBe('2026-12-01');

    // Applying the delivery-filtered parameters must not move it back to September.
    ReportParameters::apply($page, Arr::except([
        'asOf' => '2026-09-15',
        'bucket' => 'd_31_60',
    ], ReportCatalogue::reportingPeriodOf(ArAging::class)));

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

    foreach (ReportCatalogue::REPORTS as $page => $meta) {
        if (! is_a($page, App\Contracts\DeliverableReport::class, true)) {
            continue;
        }

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

    // The sweep must have found something — an empty catalogue would satisfy both assertions.
    expect(count(ReportCatalogue::REPORTING_PERIOD))->toBeGreaterThan(10);
});
