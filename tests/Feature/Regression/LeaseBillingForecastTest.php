<?php

/*
|--------------------------------------------------------------------------
| What will this lease bill, period by period? (2026-08-16)
|--------------------------------------------------------------------------
| Four screens described a lease's money and none answered this. The Charge schedule holds the
| RULES — one dated row per amount, because storing the months as well as the rule would store the
| same fact twice — and was therefore read as a payment plan and found wanting. Rent Roll is today.
| Billing Run Preview is one period across every lease. The Invoices tab is history.
|
| The forecast computes nothing of its own: every row is `MonthlyBillingService::planInvoiceForLease()`,
| the method the real run persists verbatim. These tests exist to keep that true — a forecast with
| its own arithmetic would diverge first on the cases that matter most (a proration edge, a cycle
| boundary, an escalation step), and would do it silently.
*/

use App\Filament\Admin\RelationManagers\BillingForecastRelationManager;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Charge;
use App\Models\Lease;
use App\Services\ChargeScheduleService;
use App\Services\LeaseBillingForecastService;
use App\Services\LeaseCreationService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'HW']);
    $this->unit = makeUnit($this->asset, ['code' => 'HW-01', 'status' => 'vacant', 'area_sqm' => 120]);
    CarbonImmutable::setTestNow('2026-09-15');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function forecastLease(object $ctx, array $attrs = []): Lease
{
    $lease = makeLease($ctx->unit, makeTenant(), array_merge([
        'commencement_date' => '2026-10-01',
        'expiry_date' => '2031-09-30',
        'term_months' => 60,
        'base_rent_monthly' => 72000,
        'service_charge_monthly' => 9000,
        'has_marketing_levy' => false,
        'escalation_type' => 'none',
        'escalation_rate' => 0,
    ], $attrs));

    LeaseCreationService::seedStandardCharges($lease, rent: 72000, service: 9000);

    return $lease->fresh();
}

it('groups a quarterly lease into cycles, never listing the months a cycle already covers', function () {
    $lease = forecastLease($this, ['billing_frequency' => 'quarterly']);

    $forecast = app(LeaseBillingForecastService::class)->forecast($lease);

    $periods = collect($forecast['rows'])
        ->map(fn ($r) => $r['period_start']->format('Y-m').'→'.$r['period_end']->format('Y-m'))
        ->take(3)
        ->all();

    expect($periods)->toBe(['2026-10→2026-12', '2027-01→2027-03', '2027-04→2027-06'])
        ->and($forecast['cycle_months'])->toBe(3);
});

it('agrees with the billing engine to the piastre, because it IS the billing engine', function () {
    $lease = forecastLease($this, ['billing_frequency' => 'quarterly']);

    $row = app(LeaseBillingForecastService::class)->forecast($lease)['rows'][0];

    $plan = app(MonthlyBillingService::class)->planInvoiceForLease(
        $lease, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'), prorate: true,
    );

    expect($row['total'])->toBe($plan['total'])
        ->and($row['subtotal'])->toBe($plan['subtotal'])
        ->and($row['vat_amount'])->toBe($plan['vat_amount']);
});

it('shows a contracted escalation stepping up in the period it takes effect', function () {
    $lease = forecastLease($this, [
        'billing_frequency' => 'quarterly',
        'escalation_type' => 'fixed_amount',
        'escalation_amount' => 4000,
    ]);

    app(ChargeScheduleService::class)->projectTermEscalations($lease);

    $rows = collect(app(LeaseBillingForecastService::class)->forecast($lease->fresh())['rows'])
        ->keyBy(fn ($r) => $r['period_start']->format('Y-m'));

    // 72,000 × 3 vs 76,000 × 3 — the step lands on the October anniversary, not before it.
    expect($rows['2027-07']['subtotal'])->toBe(243000.00)
        ->and($rows['2027-10']['subtotal'])->toBe(255000.00);
});

it('reports an already-invoiced period at what it ACTUALLY billed, naming the invoice', function () {
    $lease = forecastLease($this);

    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-10-01'), prorate: true)['invoice'];

    // Re-price the lease AFTER invoicing. A forecast that re-planned the past would now report
    // October at the new rent and read as a discrepancy that does not exist.
    app(ChargeScheduleService::class)->setAmount(
        $lease, 'base_rent', 90000, CarbonImmutable::parse('2026-11-01'), ['name' => 'Base Rent'], Charge::ORIGIN_MANUAL,
    );

    $row = collect(app(LeaseBillingForecastService::class)->forecast($lease->fresh())['rows'])
        ->firstWhere(fn ($r) => $r['period_start']->format('Y-m') === '2026-10');

    expect($row['invoice_number'])->toBe($invoice->number)
        ->and($row['total'])->toBe((float) $invoice->total);
});

it('never forecasts past the end of the term', function () {
    $lease = forecastLease($this, [
        'commencement_date' => '2026-10-01',
        'expiry_date' => '2027-03-31',
        'term_months' => 6,
    ]);

    $forecast = app(LeaseBillingForecastService::class)->forecast($lease);

    expect($forecast['to']->format('Y-m'))->toBe('2027-03')
        ->and(collect($forecast['rows'])->last()['period_start']->format('Y-m'))->toBe('2027-03');
});

it('caps a long monthly lease and says so rather than listing sixty rows', function () {
    $forecast = app(LeaseBillingForecastService::class)->forecast(forecastLease($this));

    expect($forecast['rows'])->toHaveCount(LeaseBillingForecastService::MAX_ROWS)
        ->and($forecast['truncated'])->toBeTrue();
});

it('opens at the first billable month for a lease that has not started', function () {
    $lease = forecastLease($this, [
        'commencement_date' => '2027-06-01',
        'expiry_date' => '2030-05-31',
    ]);

    expect(app(LeaseBillingForecastService::class)->forecast($lease)['from']->format('Y-m'))
        ->toBe('2027-06');
});

it('flags a lease that is not active, because none of it will bill until it is', function () {
    $draft = forecastLease($this, ['status' => 'draft']);

    expect(app(LeaseBillingForecastService::class)->forecast($draft)['lease_is_active'])->toBeFalse();
});

// ── The screen ─────────────────────────────────────────────────────────────────────────────────

it('renders as a tab on the lease, one row per invoice the lease will raise', function () {
    $lease = forecastLease($this, ['billing_frequency' => 'quarterly']);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    // The tab is a relation manager whose table has NO query — `Table::records()` supplies the rows
    // and `hasQuery()` is `! $dataSource`, so the named relation is never touched. If that ever
    // stops holding, this renders nothing (or the charge rows) rather than the forecast.
    $component = Livewire::test(BillingForecastRelationManager::class, [
        'ownerRecord' => $lease,
        'pageClass' => EditLease::class,
    ])->assertOk();

    // Read the table's own records rather than grepping rendered markup: what matters is that the
    // rows reaching the table are the forecast's (the data source), not the `charges` the named
    // relation would have returned had `hasQuery()` ever gone back to true.
    $records = collect($component->instance()->getTableRecords());

    expect($records)->toHaveCount(8)
        ->and($records->first()['period'])->toBe('Oct–Dec 2026')
        // rent 72,000×3 + service 9,000×3 = 243,000 net, + 3,780 VAT on the service charge alone.
        ->and($records->first()['total'])->toBe('246,780.00')
        ->and($records->last()['period'])->toBe('Jul–Sep 2028');

    Filament::setTenant(null, isQuiet: true);
});

it('reads in Arabic for an Arabic reader — period and lines both', function () {
    $lease = forecastLease($this, ['billing_frequency' => 'quarterly']);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    app()->setLocale('ar');

    $records = collect(Livewire::test(BillingForecastRelationManager::class, [
        'ownerRecord' => $lease,
        'pageClass' => EditLease::class,
    ])->instance()->getTableRecords());

    $first = $records->first();

    // `format()` emits English month names whatever the locale — the trap this pins. The period and
    // the charge-type labels must both come back in Arabic script.
    expect($first['period'])->not->toContain('Oct')
        ->and($first['period'])->toMatch('/\p{Arabic}/u')
        ->and((string) $first['lines'])->toMatch('/\p{Arabic}/u');

    app()->setLocale('en');
    Filament::setTenant(null, isQuiet: true);
});

it('is listed among the lease tabs', function () {
    expect(LeaseResource::getRelations())->toContain(BillingForecastRelationManager::class);
});

it('is refused to a user without leases.view — the amounts a tenancy bills are commercial', function () {
    $lease = forecastLease($this);

    // Assert the predicate the action gates on directly: `mountAction` refuses a hidden action for
    // an unrelated reason, so it would go green whether or not the gate existed (CLAUDE.md).
    $this->actingAs(makeUser('viewer'));
    expect(auth()->user()->can('leases.view'))->toBeTrue();

    $noAccess = makeUser('hr');
    $this->actingAs($noAccess);
    expect(auth()->user()->can('leases.view'))->toBeFalse()
        ->and($lease->exists)->toBeTrue();
});
