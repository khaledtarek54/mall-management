<?php

use App\Models\AccountingPeriod;
use App\Models\Charge;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Lease;
use App\Models\StraightLineRentAdjustment;
use App\Services\Accounting\AccountResolver;
use App\Services\ChargeScheduleService;
use App\Services\MonthlyBillingService;
use App\Services\PostStraightLineRentService;
use App\Services\StraightLineRentService;
use App\Settings\BillingSettings;
use App\Support\MorphMap;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Straight-line rent recognition (phase 5, story RA-02 — EAS 49 / IFRS 16).
 *
 * A stepped or abated lease bills a different amount most years; the standards say the lessor
 * recognises the total consideration evenly across the term regardless. The P&L shows a flat rent,
 * the tenant is invoiced the contracted ladder, and the running difference sits in Deferred Rent.
 *
 * **Two claims carry this whole story, and both are asserted below:**
 *   1. **Invoices are byte-identical with the setting on and off.** "The books changed but the bills
 *      did not" is the entire promise, and it is what makes shipping this before the accountant's
 *      ruling safe.
 *   2. **The adjustments sum to zero over a full term** and Deferred Rent unwinds to nil. Any error
 *      in the averaging shows up here as a residue.
 */
afterEach(function () {
    CarbonImmutable::setTestNow();
    app(BillingSettings::class)->straight_line_rent_enabled = false;
});

beforeEach(function () {
    test()->seed(ChartOfAccountsSeeder::class);
    test()->seed(AccountMappingSeeder::class);
});

/** A 3-year lease that steps up every year — the shape straight-lining exists for. */
function steppedLease(array $attrs = []): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, array_merge([
        'status' => 'active',
        'commencement_date' => '2028-01-01',
        'expiry_date' => '2030-12-31',
        'base_rent_monthly' => 100000,
        'has_marketing_levy' => false,
        'escalation_type' => 'none',
    ], $attrs));

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_SEED, 'amount' => 100000, 'currency' => 'EGP',
        'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT,
        'start_date' => '2028-01-01', 'is_active' => true,
    ]);

    $schedule = app(ChargeScheduleService::class);
    $schedule->setAmount($lease->fresh(), 'base_rent', 110000, CarbonImmutable::parse('2029-01-01'),
        ['name' => 'Base Rent', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT], Charge::ORIGIN_ESCALATION);
    $schedule->setAmount($lease->fresh(), 'base_rent', 120000, CarbonImmutable::parse('2030-01-01'),
        ['name' => 'Base Rent', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT], Charge::ORIGIN_ESCALATION);

    return $lease->fresh();
}

it('averages the whole contracted ladder over the term', function () {
    CarbonImmutable::setTestNow('2028-01-15');
    $lease = steppedLease();

    $schedule = app(StraightLineRentService::class)->scheduleFor($lease);

    // 12 × 100,000 + 12 × 110,000 + 12 × 120,000 = 3,960,000 over 36 months.
    expect($schedule['months'])->toBe(36)
        ->and($schedule['total'])->toBe(3960000.0)
        ->and($schedule['monthly'])->toBe(110000.0);
});

it('spreads a rent-free fit-out period across the term instead of leaving it where it fell', function () {
    // The other half of what straight-lining is for: three free months are a discount on the WHOLE
    // term, not a hole in the first quarter.
    CarbonImmutable::setTestNow('2028-01-15');
    $lease = steppedLease(['rent_commencement_date' => '2028-04-01', 'fit_out_scope' => Lease::FIT_OUT_RENT_ONLY]);

    $schedule = app(StraightLineRentService::class)->scheduleFor($lease);

    // Three months contribute nothing to the numerator but still count in the denominator.
    expect($schedule['total'])->toBe(3660000.0)          // 3,960,000 − 3 × 100,000
        ->and($schedule['months'])->toBe(36)
        ->and($schedule['monthly'])->toBe(101666.67);
});

it('recognises more than it bills early and less later, netting to zero over the term', function () {
    // The residue check. Any error in the averaging shows up as a term that does not close out.
    CarbonImmutable::setTestNow('2028-01-15');
    app(BillingSettings::class)->straight_line_rent_enabled = true;
    $lease = steppedLease();

    $service = app(StraightLineRentService::class);
    $total = 0.0;

    for ($m = CarbonImmutable::parse('2028-01-01'); $m->lte(CarbonImmutable::parse('2030-12-01')); $m = $m->addMonth()) {
        $total += $service->adjustmentFor($lease, $m);
    }

    expect(round($total, 2))->toBe(0.0)
        // Year one bills 100,000 and recognises 110,000 — the landlord's P&L is ahead of its AR.
        ->and($service->adjustmentFor($lease, CarbonImmutable::parse('2028-06-01')))->toBe(10000.0)
        ->and($service->adjustmentFor($lease, CarbonImmutable::parse('2029-06-01')))->toBe(0.0)
        ->and($service->adjustmentFor($lease, CarbonImmutable::parse('2030-06-01')))->toBe(-10000.0);
});

it('bills byte-identical invoices whether the setting is on or off', function () {
    // THE claim. If this ever fails, the feature is not shippable behind a switch at all.
    CarbonImmutable::setTestNow('2028-06-05');

    $snapshot = function (bool $enabled): array {
        app(BillingSettings::class)->straight_line_rent_enabled = $enabled;

        $lease = steppedLease();
        app(PostStraightLineRentService::class)->postForMonth(CarbonImmutable::parse('2028-06-01'));
        app(MonthlyBillingService::class)->generateForLease($lease->fresh(), CarbonImmutable::parse('2028-06-01'));

        return Invoice::where('lease_id', $lease->id)->with('items')->get()
            ->map(fn (Invoice $i) => [
                'subtotal' => (float) $i->subtotal,
                'vat_amount' => (float) $i->vat_amount,
                'total' => (float) $i->total,
                'balance' => (float) $i->balance,
                'items' => $i->items->map(fn ($it) => [
                    $it->type, (float) $it->amount, (float) $it->vat_rate, (float) $it->vat_amount,
                ])->all(),
            ])->all();
    };

    expect($snapshot(true))->toEqual($snapshot(false));
});

it('posts nothing at all while the setting is off', function () {
    CarbonImmutable::setTestNow('2028-06-05');
    $lease = steppedLease();

    $stats = app(PostStraightLineRentService::class)->postForMonth(CarbonImmutable::parse('2028-06-01'));

    expect($stats['enabled'])->toBeFalse()
        ->and($stats['posted'])->toBe(0)
        ->and(StraightLineRentAdjustment::count())->toBe(0);
});

it('posts Dr Deferred Rent / Cr Rental Income through the real sweep, and balances', function () {
    CarbonImmutable::setTestNow('2028-06-05');
    app(BillingSettings::class)->straight_line_rent_enabled = true;
    $lease = steppedLease();

    app(PostStraightLineRentService::class)->postForMonth(CarbonImmutable::parse('2028-06-01'));
    Artisan::call('accounting:sync-ledger', ['--all' => true]);

    $adjustment = StraightLineRentAdjustment::where('lease_id', $lease->id)->sole();

    expect((float) $adjustment->adjustment_amount)->toBe(10000.0)
        // Dated in the month it RECOGNISES, not the day the job ran.
        ->and($adjustment->entry_date->toDateString())->toBe('2028-06-30');

    $entry = JournalEntry::where('source_type', MorphMap::alias(StraightLineRentAdjustment::class))
        ->where('source_id', $adjustment->id)->where('status', 'posted')->sole();

    $lines = JournalLine::where('journal_entry_id', $entry->id)->get();
    $resolver = app(AccountResolver::class);
    $assetId = $lease->unit->asset_id;

    expect(round((float) $lines->sum('debit'), 2))->toBe(10000.0)
        ->and(round((float) $lines->sum('credit'), 2))->toBe(10000.0)
        // Recognising MORE than billed grows the asset and grows revenue.
        ->and((float) $lines->firstWhere('ledger_account_id', $resolver->id('deferred_rent', $assetId))->debit)->toBe(10000.0)
        ->and((float) $lines->firstWhere('ledger_account_id', $resolver->id('rent_revenue', $assetId))->credit)->toBe(10000.0);
});

it('flips the entry the other way once the ladder overtakes the average', function () {
    CarbonImmutable::setTestNow('2030-06-05');
    app(BillingSettings::class)->straight_line_rent_enabled = true;
    $lease = steppedLease();

    app(PostStraightLineRentService::class)->postForMonth(CarbonImmutable::parse('2030-06-01'));
    Artisan::call('accounting:sync-ledger', ['--all' => true]);

    $adjustment = StraightLineRentAdjustment::where('lease_id', $lease->id)->sole();
    $entry = JournalEntry::where('source_id', $adjustment->id)->where('source_type', MorphMap::alias(StraightLineRentAdjustment::class))->sole();
    $lines = JournalLine::where('journal_entry_id', $entry->id)->get();
    $resolver = app(AccountResolver::class);
    $assetId = $lease->unit->asset_id;

    expect((float) $adjustment->adjustment_amount)->toBe(-10000.0)
        ->and((float) $lines->firstWhere('ledger_account_id', $resolver->id('rent_revenue', $assetId))->debit)->toBe(10000.0)
        ->and((float) $lines->firstWhere('ledger_account_id', $resolver->id('deferred_rent', $assetId))->credit)->toBe(10000.0);
});

it('runs twice without double-posting a month', function () {
    // It is on a schedule, and a scheduler that cannot be safely re-run after a failure is one
    // nobody dares re-run.
    CarbonImmutable::setTestNow('2028-06-05');
    app(BillingSettings::class)->straight_line_rent_enabled = true;
    $lease = steppedLease();

    app(PostStraightLineRentService::class)->postForMonth(CarbonImmutable::parse('2028-06-01'));
    app(PostStraightLineRentService::class)->postForMonth(CarbonImmutable::parse('2028-06-01'));

    expect(StraightLineRentAdjustment::where('lease_id', $lease->id)->count())->toBe(1);
});

it('re-derives forward only, leaving a closed month exactly as it was', function () {
    // An amendment moves the average. Months already recognised stay recognised — restating a
    // signed-off period is what both the standards and the posting-date guards forbid.
    CarbonImmutable::setTestNow('2028-06-05');
    app(BillingSettings::class)->straight_line_rent_enabled = true;
    $lease = steppedLease();

    foreach (['2028-01-01', '2028-02-01'] as $m) {
        app(PostStraightLineRentService::class)->postForMonth(CarbonImmutable::parse($m));
    }

    // January is signed off; February is not. The period has to be created here — the sweep's
    // `ensureFiscalYears()` has not run in this test, and with NO period the date is not closed
    // (a missing period and a closed one are opposites; see PostingDate::assertOpen).
    $year = FiscalYear::create([
        'year' => 2028, 'starts_on' => '2028-01-01', 'ends_on' => '2028-12-31', 'status' => 'open',
    ]);
    AccountingPeriod::create([
        'fiscal_year_id' => $year->id, 'period_no' => 1,
        'starts_on' => '2028-01-01', 'ends_on' => '2028-01-31', 'status' => 'closed',
    ]);

    $reversed = app(PostStraightLineRentService::class)->reverseFrom($lease, CarbonImmutable::parse('2028-01-01'));

    expect($reversed)->toBe(1)
        ->and(StraightLineRentAdjustment::where('lease_id', $lease->id)->whereDate('period', '2028-01-01')->exists())
        ->toBeTrue()
        ->and(StraightLineRentAdjustment::where('lease_id', $lease->id)->whereDate('period', '2028-02-01')->exists())
        ->toBeFalse();
});

it('re-derives a reversed month without colliding with its own tombstone', function () {
    // Found while building. A reversed month is soft-deleted but still occupies
    // `unique(lease_id, period)`, so re-deriving it — the entire point of the forward-only path —
    // hit a constraint violation on its own reversal. Restoring the row is also the right
    // accounting: the poster re-posts the entry it had voided instead of leaving an orphan.
    CarbonImmutable::setTestNow('2028-06-05');
    app(BillingSettings::class)->straight_line_rent_enabled = true;
    $lease = steppedLease();

    app(PostStraightLineRentService::class)->postForMonth(CarbonImmutable::parse('2028-06-01'));
    app(PostStraightLineRentService::class)->reverseFrom($lease, CarbonImmutable::parse('2028-06-01'));

    expect(StraightLineRentAdjustment::where('lease_id', $lease->id)->count())->toBe(0);

    // The term changes, so the average moves — and re-deriving must simply work.
    app(PostStraightLineRentService::class)->postForMonth(CarbonImmutable::parse('2028-06-01'));

    expect(StraightLineRentAdjustment::where('lease_id', $lease->id)->count())->toBe(1)
        ->and(StraightLineRentAdjustment::withTrashed()->where('lease_id', $lease->id)->count())->toBe(1)
        ->and((float) StraightLineRentAdjustment::where('lease_id', $lease->id)->sole()->adjustment_amount)
        ->toBe(10000.0);
});

it('recognises nothing for a lease whose term it cannot know', function () {
    // Averaging a term with no end would be inventing one.
    CarbonImmutable::setTestNow('2028-06-05');
    $lease = steppedLease();
    $lease->charges()->delete();

    expect(app(StraightLineRentService::class)->scheduleFor($lease->fresh()))->toBeNull()
        ->and(app(StraightLineRentService::class)->adjustmentFor($lease->fresh(), CarbonImmutable::parse('2028-06-01')))
        ->toBeNull();
});
