<?php

/*
|--------------------------------------------------------------------------
| The forecast must not show a charge the run will not raise (2026-08-28)
|--------------------------------------------------------------------------
| Reported from the panel: a one-off ended through "End charge" — `is_active` genuinely set to 0 —
| kept appearing in October's forecast. The billing run ignored it correctly; only the screen was
| wrong, which is worse than a wrong figure, because it is a figure nobody can reconcile against the
| invoice when it arrives.
|
| `MonthlyBillingService` narrows to active charges with `loadMissing()`, which means "load it IF it
| is not loaded" — so whoever loads the relation FIRST decides what the planner sees. The forecast
| loaded it UNFILTERED one call earlier, and the planner then reused that collection.
|
| The trap is that both lines look correct on their own. The planner's `loadMissing` is right (it
| must not re-query for every one of a thousand leases in a run), and a bare `loadMissing('charges')`
| reads as a harmless eager-load. Only the ORDER makes them wrong, and nothing in either file says
| the other exists.
*/

use App\Models\Charge;
use App\Services\ChargeScheduleService;
use App\Services\LeaseBillingForecastService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset, ['area_sqm' => 110]), makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2026-09-01',
        'expiry_date' => '2029-08-31',
        'base_rent_monthly' => 44000,
    ]);

    // The forecast reads CHARGE ROWS, not `leases.base_rent_monthly` — a lease with a rent column
    // and no rent row bills nothing, which is what the third test caught.
    app(ChargeScheduleService::class)->setAmount(
        $this->lease, 'base_rent', 44000, CarbonImmutable::parse('2026-09-01'),
    );
    app(ChargeScheduleService::class)->setAmount(
        $this->lease, 'service_charge', 14000, CarbonImmutable::parse('2026-09-01'),
    );

    // A one-off that is later stopped — the reported case.
    $this->oneOff = Charge::create([
        'lease_id' => $this->lease->id,
        'name' => 'Service charge shortfall',
        'type' => 'other',
        'amount' => 3000,
        'frequency' => 'one_time',
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
    ]);
});

/** The charge types the forecast says a month will carry. */
function forecastTypes($lease, string $month): array
{
    return collect(app(LeaseBillingForecastService::class)->forecast($lease->fresh())['rows'])
        ->first(fn (array $r) => CarbonImmutable::instance($r['period_start'])->format('Y-m') === $month)['items'] ?? [];
}

it('drops a stopped charge from the forecast', function () {
    expect(collect(forecastTypes($this->lease, '2026-10'))->pluck('type'))->toContain('other');

    $this->oneOff->update(['is_active' => false]);

    expect(collect(forecastTypes($this->lease, '2026-10'))->pluck('type'))->not->toContain('other');
});

it('agrees with what the billing run would actually raise', function () {
    // The property that matters, and the one the two `loadMissing` calls broke between them: the
    // screen and the run must name the same charges. Asserting the forecast alone would pass on a
    // forecast that had drifted the other way.
    $this->oneOff->update(['is_active' => false]);

    $planned = collect(app(MonthlyBillingService::class)->planInvoiceForLease(
        $this->lease->fresh(),
        CarbonImmutable::parse('2026-10-01'),
        CarbonImmutable::parse('2026-10-31'),
        prorate: true,
    )['items'])->pluck('type')->sort()->values();

    $forecast = collect(forecastTypes($this->lease, '2026-10'))->pluck('type')->sort()->values();

    expect($forecast->all())->toBe($planned->all());
});

it('still shows every ACTIVE charge', function () {
    // The control. A filter that dropped everything would satisfy both tests above and leave the
    // tab empty.
    $types = collect(forecastTypes($this->lease, '2026-10'))->pluck('type');

    expect($types)->toContain('base_rent')
        ->and($types)->toContain('service_charge')
        ->and($types)->toContain('other');
});
