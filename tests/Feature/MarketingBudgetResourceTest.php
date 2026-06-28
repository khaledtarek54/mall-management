<?php

use App\Filament\Admin\Resources\MarketingBudgets\MarketingBudgetResource;
use App\Filament\Admin\Resources\MarketingBudgets\Pages\EditMarketingBudget;
use App\Filament\Admin\Resources\MarketingBudgets\Pages\ListMarketingBudgets;
use App\Filament\Admin\Resources\MarketingBudgets\RelationManagers\MarketingSpendsRelationManager;
use App\Models\MarketingBudget;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

it('renders the marketing budgets list with a balance column', function () {
    $this->actingAs(makeUser('super_admin'));
    $asset = makeAsset(['code' => 'HW']);
    Filament::setTenant($asset);

    MarketingBudget::create(['asset_id' => $asset->id, 'period_year' => 2026, 'accrued_amount' => 1000]);

    Livewire::test(ListMarketingBudgets::class)->assertOk();
});

it('renders the edit-budget form with the read-only fund displays', function () {
    $this->actingAs(makeUser('super_admin'));
    $asset = makeAsset(['code' => 'HW']);
    Filament::setTenant($asset);

    $budget = MarketingBudget::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'accrued_amount' => 5000, 'spent_amount' => 1200,
    ]);

    Livewire::test(EditMarketingBudget::class, ['record' => $budget->getRouteKey()])
        ->assertOk()
        ->assertSee('3,800.00 EGP'); // balance = accrued − spent, via the TextEntry
});

it('renders the marketing spends relation manager', function () {
    $this->actingAs(makeUser('super_admin'));
    $asset = makeAsset(['code' => 'HW']);
    Filament::setTenant($asset);

    $budget = MarketingBudget::create(['asset_id' => $asset->id, 'period_year' => 2026]);

    Livewire::test(MarketingSpendsRelationManager::class, [
        'ownerRecord' => $budget,
        'pageClass' => EditMarketingBudget::class,
    ])->assertOk();
});

it('gates marketing on the marketing permission', function () {
    $this->actingAs(makeUser('viewer'));

    expect(MarketingBudgetResource::canViewAny())->toBeTrue()
        ->and(MarketingBudgetResource::canCreate())->toBeFalse();
});

it('warns but still records a marketing spend that exceeds the budget', function () {
    $this->actingAs(makeUser('super_admin'));
    $asset = makeAsset(['code' => 'HW']);
    Filament::setTenant($asset);

    // Only EGP 1,000 accrued in the budget.
    $budget = MarketingBudget::create([
        'asset_id' => $asset->id,
        'period_year' => 2026,
        'accrued_amount' => 1000,
    ]);

    Livewire::test(MarketingSpendsRelationManager::class, [
        'ownerRecord' => $budget,
        'pageClass' => EditMarketingBudget::class,
    ])
        ->callTableAction('create', data: [
            'category' => 'other',
            'description' => 'Over-budget campaign',
            'amount' => 1500,
            'spent_on' => now()->toDateString(),
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('admin.tables.marketing_spend.overspend_title'));

    // Warn-but-ALLOW: the spend is recorded and the balance goes negative.
    expect($budget->fresh()->balance())->toBe(-500.0);
});
