<?php

use App\Filament\Admin\Resources\MarketingBudgets\MarketingBudgetResource;
use App\Filament\Admin\Resources\MarketingBudgets\Pages\EditMarketingBudget;
use App\Filament\Admin\Resources\MarketingBudgets\RelationManagers\MarketingSpendsRelationManager;
use App\Models\Charge;
use App\Models\InvoiceItem;
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
| The levy is now a REAL Charge (5% of base rent, VAT-exempt) that bills to the
| tenant as a `marketing` InvoiceItem. The property marketing budget's
| accrued_amount is DERIVED from those billed items (MarketingBudget::
| recomputeAccrued), so it reconciles rather than being incremented.
|
| Focus areas (per the brief):
|   * the budget accrual DERIVES from the billed 5% levy items (billing a lease
|     raises the budget's accrued by 5% of the billed rent),
|   * re-billing the same period is idempotent — no double accrual,
|   * accrual follows the billed (pro-rated) levy, not the full monthly levy,
|   * a MarketingSpend draws DOWN the running balance,
|   * over-budget spend boundary (spend > remaining balance) — asserts the
|     ACTUAL behavior (currently ALLOWED, balance goes negative),
|   * the 5% rate is captured per-charge / per-item at billing time so changing
|     the Settings rate does NOT rewrite already-billed items or accruals,
|   * zero / negative spend amounts,
|   * RBAC + per-property separation.
|--------------------------------------------------------------------------
*/

/**
 * Build a billable lease carrying a base-rent Charge AND the marketing levy
 * Charge at the lease's then-current rate — the shape a real lease has once
 * LeaseCreationService seeds it.
 */
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
        'start_date' => $lease->commencement_date,
        'is_active' => true,
    ]);

    app(MarketingLevyService::class)->createLevyCharge($lease);

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
| ---- BILLING-DRIVEN ACCRUAL (derived from 5% levy items) --------------- |
*/

it('raises the budget accrued by 5% of billed base rent when a lease is billed', function () {
    $lease = scenarioLeaseWithRent(20000);

    app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));

    $budget = MarketingBudget::forPeriod($lease->unit->asset_id, 2026)->refresh();

    // 5% of 20,000 billed rent = 1,000 accrued (derived from the marketing item).
    expect((float) $budget->accrued_amount)->toBe(1000.0)
        ->and($budget->balance())->toBe(1000.0);
});

it('accrues the levy month over month — each billed period adds 5%', function () {
    $lease = scenarioLeaseWithRent(10000);
    $svc = app(MonthlyBillingService::class);

    $svc->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));
    $svc->generateForLease($lease, CarbonImmutable::parse('2026-04-01'));
    $svc->generateForLease($lease, CarbonImmutable::parse('2026-05-01'));

    $budget = MarketingBudget::forPeriod($lease->unit->asset_id, 2026)->refresh();

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

    // The derived accrual reflects the single billed marketing item, not two.
    $budget = MarketingBudget::forPeriod($lease->unit->asset_id, 2026)->refresh();
    expect((float) $budget->accrued_amount)->toBe(500.0);
});

it('accrues nothing when a lease has no marketing levy to bill', function () {
    // A lease whose only active charge is service charge — no base_rent, hence
    // no marketing levy line, hence nothing to derive an accrual from.
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

    // No marketing item was billed, so no budget row is created/accrued.
    expect(MarketingBudget::where('asset_id', $asset->id)->exists())->toBeFalse();
});

it('accrues against the billed (pro-rated) levy, not the full monthly levy', function () {
    // Lease commences 16 Mar 2026; March has 31 days → 16 days billed.
    $lease = scenarioLeaseWithRent(31000, ['commencement_date' => '2026-03-16']);

    $result = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-03-01'), prorate: true);

    // The marketing item is pro-rated by the same factor as the rest of the invoice.
    $billedLevy = (float) InvoiceItem::where('invoice_id', $result['invoice']->id)
        ->where('type', 'marketing')
        ->value('amount');

    $budget = MarketingBudget::forPeriod($lease->unit->asset_id, 2026)->refresh();

    // Accrual equals the ACTUAL billed (pro-rated) levy, not 5% of full 31,000 (1,550).
    expect((float) $budget->accrued_amount)->toBe($billedLevy)
        ->and((float) $budget->accrued_amount)->toBeLessThan(1550.0);
});

/*
| ---- SPEND DRAWS DOWN THE BALANCE -------------------------------------- |
*/

it('draws the running balance down as spends are recorded against accrued levy', function () {
    $lease = scenarioLeaseWithRent(40000); // 5% = 2,000 accrued
    app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));

    $budget = MarketingBudget::forPeriod($lease->unit->asset_id, 2026)->refresh();
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
| ---- RATE VERSIONING (per-item / per-charge capture) ------------------- |
| Changing the operator-tunable Settings rate must NOT rewrite history:    |
|   * an already-billed marketing item keeps its captured amount,          |
|   * the derived accrual for the billed period stays put,                 |
|   * an already-created marketing Charge keeps its captured amount until   |
|     explicitly re-synced; only future billing picks up the new rate.     |
*/

it('does not rewrite a historical accrual when the levy rate later changes', function () {
    setLevyRate(5.0);
    $lease = scenarioLeaseWithRent(10000); // marketing charge captured at 5% → 500

    // Bill March at 5% → accrue 500.
    app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));

    // Capture a handle to the billed March levy item before the rate moves.
    $marchItem = InvoiceItem::where('type', 'marketing')
        ->whereHas('invoice', fn ($q) => $q->where('lease_id', $lease->id))
        ->first();
    expect((float) $marchItem->amount)->toBe(500.0);

    // Operator bumps the rate to 8%.
    setLevyRate(8.0);

    // The already-billed March item is frozen at the rate in force when billed,
    // so the derived accrual for March is untouched.
    expect((float) $marchItem->fresh()->amount)->toBe(500.0)
        ->and((float) MarketingBudget::forPeriod($lease->unit->asset_id, 2026)->refresh()->accrued_amount)->toBe(500.0);

    // Re-sync the lease's marketing charge to the NEW rate EFFECTIVE APRIL, then bill April → +800.
    //
    // The effective date is explicit now, because the levy is a date-ranged schedule like the rent
    // it derives from: re-syncing closes the 5% row at 31 March and opens an 8% row from 1 April.
    // Without a date it would default to the current month and April would still bill 5% — which
    // is correct behaviour, and precisely why the date has to be stated.
    // Use a freshly-loaded lease so billing reads the re-synced charge, not the
    // 5% charge the March run cached onto the original instance.
    $lease = $lease->fresh();
    app(MarketingLevyService::class)->createLevyCharge($lease, CarbonImmutable::parse('2026-04-01'));
    app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-04-01'));

    expect((float) MarketingBudget::forPeriod($lease->unit->asset_id, 2026)->refresh()->accrued_amount)->toBe(1300.0)
        // March's billed item is STILL 500 — history is not rewritten.
        ->and((float) $marchItem->fresh()->amount)->toBe(500.0);
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

    // Re-syncing re-captures at the NEW rate — proving capture happens at write time — and now
    // does so by CLOSING the 5% row and OPENING a 10% one rather than overwriting the amount. Two
    // rows, not one: the levy is a schedule, so what was billed at 5% stays readable. (This test
    // asserted `count() === 1` under the old one-row-per-lease model.)
    $resynced = $svc->createLevyCharge($lease->fresh());
    $schedule = Charge::where('lease_id', $lease->id)->where('type', 'marketing')
        ->orderBy('start_date')->orderBy('id')->get();

    expect((float) $resynced->amount)->toBe(1000.0)
        ->and($schedule)->toHaveCount(2)
        ->and((float) $schedule->first()->amount)->toBe(500.0)
        ->and($schedule->first()->end_date)->not->toBeNull()
        ->and($schedule->last()->id)->toBe($resynced->id)
        ->and($schedule->last()->end_date)->toBeNull();
});

it('applies the current rate to NEW billing while leaving prior accruals intact', function () {
    setLevyRate(5.0);
    $leaseA = scenarioLeaseWithRent(10000); // levy captured at 5% → 500
    app(MonthlyBillingService::class)->generateForLease($leaseA, CarbonImmutable::parse('2026-03-01'));

    setLevyRate(7.5);
    $leaseB = scenarioLeaseWithRent(10000); // levy captured at 7.5% → 750
    app(MonthlyBillingService::class)->generateForLease($leaseB, CarbonImmutable::parse('2026-03-01'));

    $budgetA = MarketingBudget::forPeriod($leaseA->unit->asset_id, 2026)->refresh();
    $budgetB = MarketingBudget::forPeriod($leaseB->unit->asset_id, 2026)->refresh();

    expect((float) $budgetA->accrued_amount)->toBe(500.0)   // old rate, frozen
        ->and((float) $budgetB->accrued_amount)->toBe(750.0); // new rate
});

/*
| ---- ACCRUAL AUTO-REVERSES ON INVOICE CANCELLATION --------------------- |
| The accrual is DERIVED (recomputeAccrued excludes cancelled invoices), so |
| cancelling the invoice that carried the levy backs the accrual out.       |
*/

it('reverses the derived accrual when the billed invoice is cancelled', function () {
    $lease = scenarioLeaseWithRent(10000);

    $result = app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));
    $budget = MarketingBudget::forPeriod($lease->unit->asset_id, 2026)->refresh();
    expect((float) $budget->accrued_amount)->toBe(500.0);

    $result['invoice']->update(['status' => 'cancelled']);

    // Cancelled invoices are excluded from the derived accrual → back to 0.
    expect((float) MarketingBudget::forPeriod($lease->unit->asset_id, 2026)->refresh()->accrued_amount)->toBe(0.0);
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

    Livewire::test(MarketingSpendsRelationManager::class, [
        'ownerRecord' => $budget,
        'pageClass' => EditMarketingBudget::class,
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

    Livewire::test(MarketingSpendsRelationManager::class, [
        'ownerRecord' => $budget,
        'pageClass' => EditMarketingBudget::class,
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

    Livewire::test(MarketingSpendsRelationManager::class, [
        'ownerRecord' => $budget,
        'pageClass' => EditMarketingBudget::class,
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

it('lets the marketing role view + manage budgets but never create or delete them', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('marketing'));

    $asset = makeAsset();
    Filament::setTenant($asset);
    $budget = MarketingBudget::create(['asset_id' => $asset->id, 'period_year' => 2026]);

    expect(MarketingBudgetResource::canViewAny())->toBeTrue()
        ->and(MarketingBudgetResource::canEdit($budget))->toBeTrue()
        // Budgets are auto-provisioned per property/year — never hand-created or deleted.
        ->and(MarketingBudgetResource::canCreate())->toBeFalse()
        ->and(MarketingBudgetResource::canDelete($budget))->toBeFalse();
});

it('forbids non-marketing departments from touching the marketing budget', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('leasing'));

    expect(MarketingBudgetResource::canViewAny())->toBeFalse()
        ->and(MarketingBudgetResource::canCreate())->toBeFalse();
});

it('auto-provisions budgets — even super_admin cannot create or delete them', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));

    $asset = makeAsset();
    Filament::setTenant($asset);
    $budget = MarketingBudget::create(['asset_id' => $asset->id, 'period_year' => 2026]);

    // Budgets are an auto-provisioned ledger (marketing:ensure-budgets) — no
    // create/delete UI for anyone; super_admin still views + manages spends.
    expect(MarketingBudgetResource::canViewAny())->toBeTrue()
        ->and(MarketingBudgetResource::canCreate())->toBeFalse()
        ->and(MarketingBudgetResource::canDelete($budget))->toBeFalse();
});

it('marketing:ensure-budgets auto-provisions one budget per property per year (idempotent)', function () {
    $a1 = makeAsset();
    $a2 = makeAsset();
    expect(MarketingBudget::where('period_year', 2026)->count())->toBe(0);

    $this->artisan('marketing:ensure-budgets', ['--year' => 2026])->assertExitCode(0);

    expect(MarketingBudget::where('period_year', 2026)->where('asset_id', $a1->id)->exists())->toBeTrue()
        ->and(MarketingBudget::where('period_year', 2026)->where('asset_id', $a2->id)->exists())->toBeTrue();

    // Re-run: no duplicates (firstOrCreate).
    $this->artisan('marketing:ensure-budgets', ['--year' => 2026])->assertExitCode(0);
    expect(MarketingBudget::where('period_year', 2026)->whereIn('asset_id', [$a1->id, $a2->id])->count())->toBe(2);
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

    $budgetA = MarketingBudget::forPeriod($leaseA->unit->asset_id, 2026)->refresh();
    $budgetB = MarketingBudget::forPeriod($leaseB->unit->asset_id, 2026)->refresh();

    expect((float) $budgetA->accrued_amount)->toBe(500.0)
        ->and((float) $budgetB->accrued_amount)->toBe(3000.0)
        ->and($budgetA->asset_id)->not->toBe($budgetB->asset_id);
});
