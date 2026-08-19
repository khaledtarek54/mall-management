<?php

/*
|--------------------------------------------------------------------------
| "What does the portfolio bill next year?" had no screen (2026-08-19)
|--------------------------------------------------------------------------
| `benchmarks/yardi/01` §334 describes Forecast Manager as a "lease-by-lease revenue projection
| including speculative renewals and re-lets", and §205 notes the forecast is computable the day a
| lease is signed — true here only because `ChargeScheduleService` writes the whole rent ladder at
| signing rather than one current amount.
|
| Atriom could already answer it for ONE lease (`LeaseBillingForecastService`, on the lease's own
| tab). What it could not do was add them up, which is the question a finance lead, an owner and a
| budget review all actually ask.
|
| **The speculative half is deliberately absent**, and these tests pin that as much as the
| arithmetic: assumed renewals and re-lets need a renewal probability and a market rent, neither of
| which this system holds. A guessed figure on a revenue chart is indistinguishable from contracted
| income, and this is a page an owner may be shown.
*/

use App\Filament\Admin\Pages\RevenueForecast;
use App\Models\Lease;
use App\Services\PortfolioRevenueForecastService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
});

/**
 * A lease with ONE rent charge, on its own unit.
 *
 * Named apart from `LeaseBillingForecastTest`'s `forecastLease` deliberately: that one builds a
 * fully-charged lease on a shared `$ctx->unit` at fixed amounts, which is right for testing one
 * lease's cycles and wrong for aggregation, where each lease needs its own unit and its own rent.
 * Two files declaring one helper name is a FATAL redeclaration on a single-process run and is
 * invisible under `--parallel` — `TestHelperUniquenessConformanceTest` caught this one.
 */
function portfolioLease($ctx, float $rent, array $attrs = []): Lease
{
    $lease = makeLease(makeUnit($ctx->asset), null, array_merge([
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2030-12-31',
        'base_rent_monthly' => $rent,
        'service_charge_monthly' => 0,
        'has_marketing_levy' => false,
    ], $attrs));

    $lease->charges()->create([
        'type' => 'base_rent',
        'name' => 'Base Rent',
        'amount' => $rent,
        'frequency' => 'monthly',
        'start_date' => '2026-01-01',
        'is_active' => true,
        'vat_applicable' => false,
        'vat_rate' => 0,
    ]);

    return $lease;
}

it('adds up what every lease will bill, month by month', function () {
    portfolioLease($this, 10000);
    portfolioLease($this, 25000);

    $result = app(PortfolioRevenueForecastService::class)
        ->forecast($this->asset->id, CarbonImmutable::parse('2026-03-01'), 3);

    expect($result['leases'])->toBe(2)
        ->and($result['months'])->toHaveCount(3);

    // 35,000 a month across the two leases, for each of the three months.
    foreach ($result['months'] as $month) {
        expect($month['total'])->toBe(35000.0)
            ->and($month['leases'])->toBe(2);
    }

    expect($result['total'])->toBe(105000.0);
});

/**
 * Broken down by charge type, because the question a finance lead asks of a forecast is not "how
 * much?" but "how much of it is rent?" — a single total cannot be reconciled against a budget that
 * is itself split by account.
 */
it('splits the forecast by charge type', function () {
    $lease = portfolioLease($this, 10000);
    $lease->charges()->create([
        'type' => 'service_charge',
        'name' => 'Service Charge',
        'amount' => 2000,
        'frequency' => 'monthly',
        'start_date' => '2026-01-01',
        'is_active' => true,
        'vat_applicable' => false,
        'vat_rate' => 0,
    ]);

    $result = app(PortfolioRevenueForecastService::class)
        ->forecast($this->asset->id, CarbonImmutable::parse('2026-03-01'), 1);

    expect($result['by_type']['base_rent'])->toBe(10000.0)
        ->and($result['by_type']['service_charge'])->toBe(2000.0)
        ->and($result['total'])->toBe(12000.0);
});

/**
 * NET of tax. VAT is collected for the state, not earned — including it would overstate every
 * figure on the page by the standard rate, on a report an owner may be shown.
 */
it('forecasts revenue net of tax', function () {
    $lease = makeLease(makeUnit($this->asset), null, [
        'commencement_date' => '2026-01-01', 'expiry_date' => '2030-12-31',
        'base_rent_monthly' => 0, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
    ]);
    $lease->charges()->create([
        'type' => 'service_charge', 'name' => 'Service Charge', 'amount' => 1000,
        'frequency' => 'monthly', 'start_date' => '2026-01-01', 'is_active' => true,
        'vat_applicable' => true, 'vat_rate' => 14,
    ]);

    $result = app(PortfolioRevenueForecastService::class)
        ->forecast($this->asset->id, CarbonImmutable::parse('2026-03-01'), 1);

    // 1,000 — not 1,140.
    expect($result['months'][0]['total'])->toBe(1000.0);
});

/**
 * **Contracted income only.** A lease that ends inside the window stops contributing from the month
 * after it ends — the forecast must not carry it on as if it will renew, because assuming a renewal
 * is exactly the speculative half this deliberately does not attempt.
 */
it('stops forecasting a lease after its term ends', function () {
    portfolioLease($this, 10000, ['expiry_date' => '2026-04-30']);
    portfolioLease($this, 5000);

    $result = app(PortfolioRevenueForecastService::class)
        ->forecast($this->asset->id, CarbonImmutable::parse('2026-03-01'), 4);

    $byPeriod = collect($result['months'])->keyBy('period');

    expect($byPeriod['2026-03']['total'])->toBe(15000.0)
        ->and($byPeriod['2026-04']['total'])->toBe(15000.0)
        // May: the first lease has ended and is NOT assumed to renew.
        ->and($byPeriod['2026-05']['total'])->toBe(5000.0)
        ->and($byPeriod['2026-05']['leases'])->toBe(1);
});

/** A lease that is not yet active is not contracted income either. */
it('excludes a lease that is not active', function () {
    portfolioLease($this, 10000, ['status' => 'pending_approval']);

    $result = app(PortfolioRevenueForecastService::class)
        ->forecast($this->asset->id, CarbonImmutable::parse('2026-03-01'), 1);

    expect($result['leases'])->toBe(0)
        ->and($result['total'])->toBe(0.0);
});

/** Property isolation: a forecast for one mall must not carry another mall's income. */
it('is scoped to the selected property', function () {
    portfolioLease($this, 10000);

    $other = makeAsset();
    $otherLease = makeLease(makeUnit($other), null, [
        'commencement_date' => '2026-01-01', 'expiry_date' => '2030-12-31',
        'base_rent_monthly' => 99000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
    ]);
    $otherLease->charges()->create([
        'type' => 'base_rent', 'name' => 'Base Rent', 'amount' => 99000, 'frequency' => 'monthly',
        'start_date' => '2026-01-01', 'is_active' => true, 'vat_applicable' => false, 'vat_rate' => 0,
    ]);

    $mine = app(PortfolioRevenueForecastService::class)
        ->forecast($this->asset->id, CarbonImmutable::parse('2026-03-01'), 1);

    expect($mine['total'])->toBe(10000.0);

    // The control: the other mall's income exists, it is simply not in THIS answer. A scope that
    // returned nothing would satisfy the assertion above just as well.
    $theirs = app(PortfolioRevenueForecastService::class)
        ->forecast($other->id, CarbonImmutable::parse('2026-03-01'), 1);

    expect($theirs['total'])->toBe(99000.0);
});

/**
 * A month is INVOICED only when every lease in it has been billed. One un-billed lease makes the
 * whole month a projection — labelling a part-billed month as settled fact is how a forecast gets
 * read as a fact.
 */
it('calls a month projected while any lease in it is unbilled', function () {
    portfolioLease($this, 10000);
    portfolioLease($this, 5000);

    $result = app(PortfolioRevenueForecastService::class)
        ->forecast($this->asset->id, CarbonImmutable::parse('2026-03-01'), 1);

    expect($result['months'][0]['actual'])->toBeFalse();
});

/** The horizon is the operator's, and a longer one covers more months. */
it('honours the requested horizon', function () {
    portfolioLease($this, 10000);

    $service = app(PortfolioRevenueForecastService::class);
    $from = CarbonImmutable::parse('2026-03-01');

    expect($service->forecast($this->asset->id, $from, 6)['months'])->toHaveCount(6)
        ->and($service->forecast($this->asset->id, $from, 12)['months'])->toHaveCount(12);
});

/**
 * The page itself, driven. A forecast nobody can open is the failure this project keeps finding —
 * and the service alone would satisfy every test above.
 */
it('renders the forecast page with the months on it', function () {
    portfolioLease($this, 10000);
    Filament::setTenant($this->asset);

    Livewire::test(RevenueForecast::class)
        ->assertOk()
        // The caveat has to be on the page, not only in the docs: a chart is read before its
        // documentation, and "contracted only" is what stops this being mistaken for a target.
        ->assertSee('Contracted only');
});

/** The CSV is the artefact a finance lead actually reconciles against a budget. */
it('exports a column per charge type', function () {
    portfolioLease($this, 10000);
    Filament::setTenant($this->asset);

    $csv = Livewire::test(RevenueForecast::class)->instance()->reportCsv();

    expect($csv['filename'])->toStartWith('revenue-forecast-')
        ->and($csv['headers'])->toContain('Base Rent')
        ->and($csv['headers'])->toContain('Basis')
        ->and($csv['rows'])->not->toBeEmpty();
});

/** Reports are permission-gated, and this one is no exception. */
it('is withheld from a role without reports access', function () {
    $this->actingAs(makeUser('technician', [$this->asset->id]));

    expect(RevenueForecast::canAccess())->toBeFalse();
});

/**
 * The horizon is CLAMPED, not trusted.
 *
 * `horizon` is a public Livewire property and Livewire takes what the payload says, not what the
 * `Select` rendered — so a crafted request could ask for 600 months and make this plan an invoice
 * per lease per month six hundred times over. Nothing leaks; it is simply work nobody asked for,
 * which is the cheapest kind of denial of service to hand someone. Found in review, not by a
 * failing test.
 */
it('clamps an absurd horizon rather than trusting the payload', function () {
    portfolioLease($this, 10000);

    $service = app(PortfolioRevenueForecastService::class);
    $from = CarbonImmutable::parse('2026-03-01');

    $result = $service->forecast($this->asset->id, $from, 600);

    expect(CarbonImmutable::parse($result['to'])->diffInMonths(CarbonImmutable::parse($result['from'])))
        ->toBeLessThan(PortfolioRevenueForecastService::MAX_HORIZON_MONTHS + 1);

    // And a nonsensical low value still returns one month rather than none.
    expect($service->forecast($this->asset->id, $from, 0)['months'])->toHaveCount(1);
});

/**
 * Property isolation through the real clamp, as a RESTRICTED user — the other isolation test passes
 * an asset id, which only proves the argument is honoured. This proves the scope bites when nothing
 * is picked at all.
 */
it('clamps a restricted user to their own properties when no property is picked', function () {
    portfolioLease($this, 10000);

    $other = makeAsset();
    $theirs = makeLease(makeUnit($other), null, [
        'commencement_date' => '2026-01-01', 'expiry_date' => '2030-12-31',
        'base_rent_monthly' => 50000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
    ]);
    $theirs->charges()->create([
        'type' => 'base_rent', 'name' => 'Base Rent', 'amount' => 50000, 'frequency' => 'monthly',
        'start_date' => '2026-01-01', 'is_active' => true, 'vat_applicable' => false, 'vat_rate' => 0,
    ]);

    // A manager assigned to ONE mall, asking for "everything".
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    $result = app(PortfolioRevenueForecastService::class)
        ->forecast(null, CarbonImmutable::parse('2026-03-01'), 1);

    expect($result['total'])->toBe(10000.0);
});
