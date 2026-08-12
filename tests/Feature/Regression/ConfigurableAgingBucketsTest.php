<?php

/*
|--------------------------------------------------------------------------
| The ageing boundaries are the operator's, and there is only one copy of them
|--------------------------------------------------------------------------
| 30/60/90 was hard-coded, so "show me 45/90/120" was a deploy — and it is a real request: a mall
| whose leases pay quarterly ages nothing meaningfully at 30 days, and these are the first numbers
| an owner reads on the AR report.
|
| The move also closed a duplication that could not have survived contact. The ranges lived in
| `ReportService::AGING_BUCKETS` **and again as literals** inside `agingBucketKey()` — the classifier
| every invoice goes through — under a docblock that said the const "is not allowed to be copied".
| Changing one would have left the summary totals and the drill-down disagreeing about which bucket
| an invoice is in, which reads as a reporting bug and is a policy bug.
|
| So the tests that matter here are not "can I set it" but: **does everything move together.**
*/

use App\Filament\Admin\Pages\ArAging;
use App\Settings\BillingSettings;
use App\Support\AgingBuckets;
use Database\Seeders\RolesPermissionsSeeder;

function ageAt(array $days): void
{
    $settings = app(BillingSettings::class);
    $settings->ar_aging_bucket_days = $days;
    $settings->save();
}

beforeEach(fn () => test()->seed(RolesPermissionsSeeder::class));

it('ages at the boundaries the operator set', function () {
    ageAt([45, 90, 120]);

    expect(AgingBuckets::keyFor(0))->toBe('current')
        ->and(AgingBuckets::keyFor(45))->toBe('d_1_30')
        ->and(AgingBuckets::keyFor(46))->toBe('d_31_60')
        ->and(AgingBuckets::keyFor(90))->toBe('d_31_60')
        ->and(AgingBuckets::keyFor(91))->toBe('d_61_90')
        ->and(AgingBuckets::keyFor(120))->toBe('d_61_90')
        ->and(AgingBuckets::keyFor(121))->toBe('d_90_plus');
});

it('moves the ranges and the labels together', function () {
    // The duplication that used to exist made exactly this divergence possible: a label saying
    // "1–30 days" over a column the classifier was filling at 45. The label is derived from the
    // same boundaries, so it cannot claim a range the report does not use.
    ageAt([45, 90, 120]);

    expect(AgingBuckets::all()['d_1_30'])->toBe([1, 45])
        ->and(AgingBuckets::all()['d_31_60'])->toBe([46, 90])
        ->and(AgingBuckets::all()['d_61_90'])->toBe([91, 120])
        ->and(AgingBuckets::all()['d_90_plus'])->toBe([121, null])
        ->and(AgingBuckets::label('d_1_30'))->toContain('45')
        ->and(AgingBuckets::label('d_90_plus'))->toContain('120');
});

it('keeps the keys stable when the boundaries move', function () {
    // The keys are identifiers — a URL parameter, a saved-view parameter, a colour lookup and a
    // translation key in six places. Renaming `d_1_30` when the first boundary becomes 45 would
    // break bookmarks to say something the label already says.
    ageAt([45, 90, 120]);

    expect(array_keys(AgingBuckets::all()))->toBe(AgingBuckets::KEYS);
});

it('falls back rather than refusing to age at all', function () {
    // An ageing report must not stop rendering because somebody typed the boundaries out of order.
    // Clamped, not thrown — and the settings screen is where the mistake gets fixed.
    foreach ([[90, 60, 30], [30, 30, 60], [0, 60, 90], [-5, 60, 90], [30, 60], []] as $bad) {
        ageAt($bad);

        expect(AgingBuckets::boundaries())->toBe(AgingBuckets::DEFAULTS, 'bad input '.json_encode($bad).' should fall back');
    }
});

it('classifies through one list, not two', function () {
    // The regression this guards. `agingBucketKey()` carried its own copy of 30/60/90; if it still
    // did, this would classify at the old boundaries while `all()` reported the new ones.
    ageAt([10, 20, 30]);

    foreach (AgingBuckets::all() as $key => [$from, $to]) {
        if ($key === AgingBuckets::CURRENT) {
            continue;
        }

        expect(AgingBuckets::keyFor($from))->toBe($key, "day {$from} should open bucket {$key}");

        if ($to !== null) {
            expect(AgingBuckets::keyFor($to))->toBe($key, "day {$to} should close bucket {$key}");
        }
    }
});

it('reaches the AR ageing report itself', function () {
    // End to end: the page's own tabs come from the same helper, so an operator who moves the
    // boundaries sees the report move — which is the whole point of the setting.
    ageAt([45, 90, 120]);

    $buckets = ArAging::buckets();

    expect(array_keys($buckets))->toBe(AgingBuckets::KEYS)
        ->and($buckets['d_1_30'])->toContain('45')
        ->and($buckets['d_90_plus'])->toContain('120');
});

it('uses the configured default payment terms when a lease does not state its own', function () {
    // The other constant this lifted: `?? 7` at twelve call sites, on a number that decides when a
    // receivable becomes overdue and therefore what the ageing shows.
    $settings = app(BillingSettings::class);
    $settings->default_payment_terms_days = 21;
    $settings->save();

    expect(BillingSettings::defaultPaymentTermsDays())->toBe(21);

    // Negative is clamped rather than producing a due date before the issue date.
    $settings->default_payment_terms_days = -3;
    $settings->save();

    expect(BillingSettings::defaultPaymentTermsDays())->toBe(0);
});
