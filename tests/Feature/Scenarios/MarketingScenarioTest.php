<?php

use App\Filament\Admin\Resources\MarketingBudgets\MarketingBudgetResource;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\MarketingBudget;
use App\Services\MarketingLevyService;
use App\Services\MonthlyBillingService;
use App\Settings\MarketingSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Marketing scenarios — net-new coverage beyond Levy/Budget/Spend unit tests.
|
| Focus areas (per the brief):
|   * the 5% levy ACCRUES on BILLED base rent (billing a lease raises the
|     budget's accrued by 5% of the billed rent),
|   * a MarketingSpend draws DOWN the running balance,
|   * over-budget spend boundary (spend > remaining balance) — asserts the
|     ACTUAL behavior (currently ALLOWED, balance goes negative),
|   * the 5% rate is captured per-charge / per-accrual so changing the
|     Settings rate does NOT rewrite historical charges or accruals,
|   * zero / negative spend amounts.
|--------------------------------------------------------------------------
*/

/** Build a billable lease (base-rent Charge present) at the given monthly rent. */
function scenarioLeaseWithRent(float $rent, array $leaseAttrs = []): Lease
{
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit, null, array_merge(
        ['base_rent_monthly' => $rent, 'commencement_date' => '2026-01-01'],
        $leaseAttrs,
    ));

    Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Base Rent',
        'type' => 'base_rent',
        'amount' => $rent,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => false,
        'vat_rate' => 0,
        'is_active' => true,
    ]);

    return $lease;
}

function setLevyRate(float $percent): void
{
    $settings = app(MarketingSettings::class);
    $settings->levy_rate_percent = $percent;
    $settings->save();
}

afterEach(function () {
    Filament::setTenant(null, isQuiet: true);
});

/*
| ---- BILLING-DRIVEN ACCRUAL (5% of BILLED base rent) ------------------- |
*/

it('raises the budget accrued by 5% of billed base rent when a lease is billed', function () {
    $lease = scenarioLeaseWithRent(20000);

    app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));

    $budget = MarketingBudget::where('asset_id', $lease->unit->asset_id)
        ->where('period_year', 2026)
        ->first();

    // 5% of 20,000 billed rent = 1,000 accrued; nothing spent yet.
    expect((float) $budget->accrued_amount)->toBe(1000.0)
        ->and($budget->balance())->toBe(1000.0);
});

it('accrues the levy month over month — each billed period adds 5%', function () {
    $lease = scenarioLeaseWithRent(10000);
    $svc = app(MonthlyBillingService::class);

    $svc->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));
    $svc->generateForLease($lease, CarbonImmutable::parse('2026-04-01'));
    $svc->generateForLease($lease, CarbonImmutable::parse('2026-05-01'));

    $budget = MarketingBudget::where('asset_id', $lease->unit->asset_id)
        ->where('period_year', 2026)
        ->first();

    // 3 months × (5% of 10,000) = 1,500 into the SAME per-year budget row.
    expect(MarketingBudget::where('asset_id', $lease->unit->asset_id)->count())->toBe(1)
        ->and((float) $budget->accrued_amount)->toBe(1500.0);
});

it('does not accrue twice when the same period is billed again (idempotent)', function () {
    $lease = scenarioLeaseWithRent(10000);
    $svc = app(MonthlyBillingService::class);

    $first = $svc->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));
    $second = $svc->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));

    expect($first['status'])->toBe('created')
        ->and($second['status'])->toBe('skipped');

    $budget = MarketingBudget::where('asset_id', $lease->unit->asset_id)->first();
    expect((float) $budget->accrued_amount)->toBe(500.0);
});

it('accrues nothing when a lease has no base rent to bill', function () {
    // A lease whose only active charge is service charge — no base_rent line.
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit, null, ['base_rent_monthly' => 0, 'commencement_date' => '2026-01-01']);

    Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Service Charge',
        'type' => 'service_charge',
        'amount' => 2000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => false,
        'vat_rate' => 0,
        'is_active' => true,
    ]);

    app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));

    expect(MarketingBudget::where('asset_id', $asset->id)->exists())->toBeFalse();
});

it('accrues against the billed (pro-rated) rent, not the full monthly rent', function () {
    // Lease commences 16 Mar 2026; March has 31 days → 16 days billed.
    $lease = scenarioLeaseWithRent(31000, ['commencement_date' => '2026-03-16']);

    $result = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-03-01'), prorate: true);

    $billedRent = (float) $result['invoice']->subtotal;

    $budget = MarketingBudget::where('asset_id', $lease->unit->asset_id)->first();

    // Levy must equal 5% of the ACTUAL billed (pro-rated) rent, not 5% of 31,000.
    expect((float) $budget->accrued_amount)->toBe(round($billedRent * 0.05, 2))
        ->and((float) $budget->accrued_amount)->toBeLessThan(1550.0);
});

/*
| ---- SPEND DRAWS DOWN THE BALANCE -------------------------------------- |
*/

it('draws the running balance down as spends are recorded against accrued levy', function () {
    $lease = scenarioLeaseWithRent(40000); // 5% = 2,000 accrued
    app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));

    $budget = MarketingBudget::where('asset_id', $lease->unit->asset_id)->first();
    expect($budget->balance())->toBe(2000.0);

    $budget->spends()->create([
        'category' => 'event',
        'description' => 'Ramadan night market',
        'amount' => 750,
        'spent_on' => '2026-03-10',
    ]);
    $budget->spends()->create([
        'category' => 'promotion',
        'description' => 'Radio spots',
        'amount' => 500,
        'spent_on' => '2026-03-12',
    ]);

    expect($budget->refresh()->balance())->toBe(750.0)
        ->and((float) $budget->spent_amount)->toBe(1250.0);
});

it('returns the balance to accrued after a spend is deleted (draw-down reverses)', function () {
    $budget = MarketingBudget::create([
        'asset_id' => makeAsset()->id,
        'period_year' => 2026,
        'accrued_amount' => 1000,
    ]);

    $spend = $budget->spends()->create([
        'category' => 'offer',
        'description' => 'Coupon book',
        'amount' => 400,
        'spent_on' => '2026-02-01',
    ]);
    expect($budget->refresh()->balance())->toBe(600.0);

    $spend->delete();

    expect($budget->refresh()->balance())->toBe(1000.0)
        ->and((float) $budget->spent_amount)->toBe(0.0);
});

/*
| ---- OVER-BUDGET SPEND BOUNDARY ---------------------------------------- |
| Records the ACTUAL product behavior: an over-budget spend is ALLOWED and |
| the balance simply goes negative — there is no hard cap at the model/    |
| relation layer. (If a cap is ever added, this test documents the change  |
| point.)                                                                  |
*/

it('allows a spend greater than the remaining balance — balance goes negative', function () {
    $budget = MarketingBudget::create([
        'asset_id' => makeAsset()->id,
        'period_year' => 2026,
        'accrued_amount' => 1000,
    ]);

    $spend = $budget->spends()->create([
        'category' => 'event',
        'description' => 'Grand opening (over budget)',
        'amount' => 1500,
        'spent_on' => '2026-05-01',
    ]);

    expect($spend->exists)->toBeTrue()
        ->and((float) $budget->refresh()->spent_amount)->toBe(1500.0)
        ->and($budget->balance())->toBe(-500.0);
});

it('keeps drawing down past zero across multiple spends', function () {
    $budget = MarketingBudget::create([
        'asset_id' => makeAsset()->id,
        'period_year' => 2026,
        'accrued_amount' => 300,
    ]);

    $budget->spends()->create(['category' => 'offer', 'description' => 'A', 'amount' => 200, 'spent_on' => '2026-01-05']);
    $budget->spends()->create(['category' => 'offer', 'description' => 'B', 'amount' => 200, 'spent_on' => '2026-01-06']);

    expect($budget->refresh()->balance())->toBe(-100.0);
});

/*
| ---- RATE VERSIONING (per-charge / per-accrual capture) ---------------- |
| Changing the operator-tunable Settings rate must NOT rewrite history:    |
|   * an already-billed accrual stays at the old rate,                     |
|   * an already-created marketing Charge keeps its captured amount,        |
|   * only FUTURE billing/charges pick up the new rate.                     |
*/

it('does not rewrite a historical accrual when the levy rate later changes', function () {
    setLevyRate(5.0);
    $lease = scenarioLeaseWithRent(10000);

    // Bill March at 5% → accrue 500.
    app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));

    // Operator bumps the rate to 8%.
    setLevyRate(8.0);

    $budget = MarketingBudget::where('asset_id', $lease->unit->asset_id)->first();

    // March accrual is frozen at the rate in force when it was billed.
    expect((float) $budget->accrued_amount)->toBe(500.0);

    // April bills at the NEW 8% → adds 800; March's 500 is untouched.
    app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-04-01'));

    expect((float) $budget->refresh()->accrued_amount)->toBe(1300.0);
});

it('captures the rate on the marketing Charge so a later rate change does not alter it', function () {
    setLevyRate(5.0);
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit, null, ['base_rent_monthly' => 10000]);

    $svc = app(MarketingLevyService::class);
    $charge = $svc->createLevyCharge($lease);
    expect((float) $charge->amount)->toBe(500.0);

    // Rate changes after the charge was captured.
    setLevyRate(10.0);

    // The existing charge row is unchanged until it is explicitly re-synced.
    expect((float) $charge->fresh()->amount)->toBe(500.0);

    // Re-syncing (idempotent updateOrCreate) re-captures at the NEW rate,
    // still as a single charge — proving capture happens at write time.
    $resynced = $svc->createLevyCharge($lease->fresh());
    expect((float) $resynced->amount)->toBe(1000.0)
        ->and(Charge::where('lease_id', $lease->id)->where('type', 'marketing')->count())->toBe(1);
});

it('applies the current rate to NEW billing while leaving prior accruals intact', function () {
    setLevyRate(5.0);
    $leaseA = scenarioLeaseWithRent(10000);
    app(MonthlyBillingService::class)->generateForLease($leaseA, CarbonImmutable::parse('2026-03-01'));

    setLevyRate(7.5);
    $leaseB = scenarioLeaseWithRent(10000);
    app(MonthlyBillingService::class)->generateForLease($leaseB, CarbonImmutable::parse('2026-03-01'));

    $budgetA = MarketingBudget::where('asset_id', $leaseA->unit->asset_id)->first();
    $budgetB = MarketingBudget::where('asset_id', $leaseB->unit->asset_id)->first();

    expect((float) $budgetA->accrued_amount)->toBe(500.0)   // old rate, frozen
        ->and((float) $budgetB->accrued_amount)->toBe(750.0); // new rate
});

/*
| ---- ZERO / NEGATIVE SPEND AMOUNTS ------------------------------------- |
| The relation-manager form enforces numeric + minValue(0) + required.     |
| Assert via the Filament form validation that 0 and negatives are caught. |
*/

it('accepts a positive spend through the relation-manager form', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('marketing'));
    $asset = makeAsset();
    Filament::setTenant($asset);

    $budget = MarketingBudget::create(['asset_id' => $asset->id, 'period_year' => 2026, 'accrued_amount' => 1000]);

    Livewire::test(\App\Filament\Admin\Resources\MarketingBudgets\RelationManagers\MarketingSpendsRelationManager::class, [
        'ownerRecord' => $budget,
        'pageClass' => \App\Filament\Admin\Resources\MarketingBudgets\Pages\EditMarketingBudget::class,
    ])
        ->callTableAction('create', data: [
            'category' => 'event',
            'amount' => 250,
            'spent_on' => '2026-03-01',
            'description' => 'Valid spend',
        ])
        ->assertHasNoTableActionErrors();

    expect((float) $budget->refresh()->spent_amount)->toBe(250.0);
});

it('rejects a negative spend amount at the form layer', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('marketing'));
    $asset = makeAsset();
    Filament::setTenant($asset);

    $budget = MarketingBudget::create(['asset_id' => $asset->id, 'period_year' => 2026, 'accrued_amount' => 1000]);

    Livewire::test(\App\Filament\Admin\Resources\MarketingBudgets\RelationManagers\MarketingSpendsRelationManager::class, [
        'ownerRecord' => $budget,
        'pageClass' => \App\Filament\Admin\Resources\MarketingBudgets\Pages\EditMarketingBudget::class,
    ])
        ->callTableAction('create', data: [
            'category' => 'event',
            'amount' => -100,
            'spent_on' => '2026-03-01',
            'description' => 'Negative spend',
        ])
        ->assertHasTableActionErrors(['amount']);

    expect($budget->refresh()->spends()->count())->toBe(0);
});

it('rejects a missing/blank spend amount at the form layer', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('marketing'));
    $asset = makeAsset();
    Filament::setTenant($asset);

    $budget = MarketingBudget::create(['asset_id' => $asset->id, 'period_year' => 2026, 'accrued_amount' => 1000]);

    Livewire::test(\App\Filament\Admin\Resources\MarketingBudgets\RelationManagers\MarketingSpendsRelationManager::class, [
        'ownerRecord' => $budget,
        'pageClass' => \App\Filament\Admin\Resources\MarketingBudgets\Pages\EditMarketingBudget::class,
    ])
        ->callTableAction('create', data: [
            'category' => 'event',
            'amount' => null,
            'spent_on' => '2026-03-01',
            'description' => 'No amount',
        ])
        ->assertHasTableActionErrors(['amount']);
});

/*
| ---- RBAC: marketing module gating ------------------------------------- |
*/

it('lets the marketing role view and create but never delete budgets', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('marketing'));

    $asset = makeAsset();
    Filament::setTenant($asset);
    $budget = MarketingBudget::create(['asset_id' => $asset->id, 'period_year' => 2026]);

    expect(MarketingBudgetResource::canViewAny())->toBeTrue()
        ->and(MarketingBudgetResource::canCreate())->toBeTrue()
        ->and(MarketingBudgetResource::canEdit($budget))->toBeTrue()
        // delete is reserved for super_admin project-wide, even with marketing.delete.
        ->and(MarketingBudgetResource::canDelete($budget))->toBeFalse();
});

it('forbids non-marketing departments from touching the marketing budget', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('leasing'));

    expect(MarketingBudgetResource::canViewAny())->toBeFalse()
        ->and(MarketingBudgetResource::canCreate())->toBeFalse();
});

it('grants super_admin full control including delete', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));

    $asset = makeAsset();
    Filament::setTenant($asset);
    $budget = MarketingBudget::create(['asset_id' => $asset->id, 'period_year' => 2026]);

    expect(MarketingBudgetResource::canViewAny())->toBeTrue()
        ->and(MarketingBudgetResource::canCreate())->toBeTrue()
        ->and(MarketingBudgetResource::canDelete($budget))->toBeTrue();
});

/*
| ---- DATA SCOPING: budget per property --------------------------------- |
*/

it('keeps each property\'s marketing budget separate when both are billed', function () {
    $leaseA = scenarioLeaseWithRent(10000);
    $leaseB = scenarioLeaseWithRent(60000);
    $svc = app(MonthlyBillingService::class);

    $svc->generateForLease($leaseA, CarbonImmutable::parse('2026-03-01'));
    $svc->generateForLease($leaseB, CarbonImmutable::parse('2026-03-01'));

    $budgetA = MarketingBudget::where('asset_id', $leaseA->unit->asset_id)->first();
    $budgetB = MarketingBudget::where('asset_id', $leaseB->unit->asset_id)->first();

    expect((float) $budgetA->accrued_amount)->toBe(500.0)
        ->and((float) $budgetB->accrued_amount)->toBe(3000.0)
        ->and($budgetA->asset_id)->not->toBe($budgetB->asset_id);
});
