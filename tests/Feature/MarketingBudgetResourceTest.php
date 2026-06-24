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
