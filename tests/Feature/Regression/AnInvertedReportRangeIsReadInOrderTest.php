<?php

use App\Models\Expense;
use App\Models\TenantSalesDeclaration;
use App\Services\Reports\ReportService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;

/**
 * **A report range typed backwards is read in order, not answered with EGP 0.00** (SW-184).
 *
 * `ReportFilters::from()`/`to()` were plain date pickers with no bound on each other, and with `from`
 * after `to` every range report degrades silently: `weeklySpend()`'s cursor loop never runs, both
 * `whereBetween` clauses match nothing, and `WeeklySpend::getSubheading()` renders
 * "EGP 0.00 · EGP 0.00 · EGP 0.00" — an empty table under three figures that read as a finding, in
 * the export and the scheduled email as well as on screen.
 *
 * Every case pairs the inverted call with the window AS MEANT, so a fix that emptied both would not
 * pass.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-09-04');
    $this->asset = makeAsset(['code' => 'RG']);

    Expense::create([
        'asset_id' => $this->asset->id,
        'expense_date' => '2026-08-12',
        'description' => 'Generator service',
        'category' => 'maintenance',
        'amount' => 17500,
        'paid_from' => 'cash',
    ]);

    $this->lease = makeLease(makeUnit($this->asset, ['code' => 'RG-01']), null, [
        'status' => 'active',
        'commencement_date' => '2025-01-01',
        'expiry_date' => '2030-12-31',
        'has_percentage_rent' => true,
    ])->fresh();

    $invoice = makeInvoice($this->lease, [
        'period_start' => '2026-02-01', 'period_end' => '2026-02-28', 'status' => 'issued',
    ]);
    $invoice->items()->delete();
    $invoice->items()->create([
        'description' => 'Rent', 'type' => 'base_rent',
        'amount' => 24000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 24000,
    ]);

    TenantSalesDeclaration::create([
        'lease_id' => $this->lease->id,
        'period_start' => '2026-02-01', 'period_end' => '2026-02-28',
        'declared_sales' => 100000, 'status' => 'submitted', 'declared_at' => '2026-03-01',
    ]);
});

it('orders a span and leaves a half-stated one alone', function () {
    $early = CarbonImmutable::parse('2026-08-01');
    $late = CarbonImmutable::parse('2026-08-31');

    expect(ReportPeriod::orderedSpan($late, $early))->toBe([$early, $late])
        // The control: a window already in order is returned untouched.
        ->and(ReportPeriod::orderedSpan($early, $late))->toBe([$early, $late])
        // A half-stated window has no order to fix — the same rule advanceSpan() already applies.
        ->and(ReportPeriod::orderedSpan(null, $late))->toBe([null, $late])
        ->and(ReportPeriod::orderedSpan($early, null))->toBe([$early, null]);
});

it('reports the same weekly spend whichever way round the two dates were typed', function () {
    $service = app(ReportService::class);

    $asMeant = $service->weeklySpend(CarbonImmutable::parse('2026-08-03'), CarbonImmutable::parse('2026-08-30'));
    $inverted = $service->weeklySpend(CarbonImmutable::parse('2026-08-30'), CarbonImmutable::parse('2026-08-03'));

    expect($asMeant['totals']['total'])->toBe(17500.0)
        ->and($asMeant['weeks'])->not->toBeEmpty()
        // Inverted, this used to be an empty week list under a total of 0.00.
        ->and($inverted['weeks'])->toEqual($asMeant['weeks'])
        ->and($inverted['totals'])->toEqual($asMeant['totals'])
        // …and the payload names the window it actually read, so nothing downstream prints the
        // dates as they were typed.
        ->and($inverted['from'])->toBe($asMeant['from'])
        ->and($inverted['to'])->toBe($asMeant['to']);
});

it('reads an inverted occupancy-cost window in order too', function () {
    $service = app(ReportService::class);
    $early = CarbonImmutable::parse('2026-01-01');
    $late = CarbonImmutable::parse('2026-03-31');

    $asMeant = $service->occupancyCost($early, $late, $this->asset->id)->sole();
    $inverted = $service->occupancyCost($late, $early, $this->asset->id)->sole();

    // The control first: inverted, this read 0.00 of cost against 0.00 of sales and a null ratio,
    // which on this screen is indistinguishable from a tenant who has declared nothing.
    expect($asMeant['occupancy_cost'])->toBe(24000.0)
        ->and($asMeant['occupancy_cost_pct'])->toBe(24.0)
        ->and($inverted)->toEqual($asMeant);
});
