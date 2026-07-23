<?php

use App\Filament\Admin\Resources\MarketingBudgets\MarketingBudgetResource;
use App\Filament\Admin\Resources\MarketingBudgets\Pages\ListMarketingBudgets;
use App\Models\MarketingBudget;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Module 13 UX pass — two owner-oversight gaps: (1) the marketing fund's spend detail was not
 * exportable (where did the levy go), and (2) an over-budget property (spent past the collected levy)
 * looked identical to a healthy one on the list, defeating the screen's whole purpose. This pins the
 * spend-register CSV and the over-budget filter.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

it('exports the marketing spend register with a total that ties to the fund', function () {
    $asset = makeAsset(['code' => 'HW']);
    $budget = MarketingBudget::create(['asset_id' => $asset->id, 'period_year' => 2026, 'accrued_amount' => 1000]);
    $budget->spends()->create(['category' => 'offer', 'description' => 'Ramadan offer', 'amount' => 200, 'paid_from' => 'cash', 'spent_on' => now()]);
    $budget->spends()->create(['category' => 'printed_work', 'description' => 'Flyers', 'amount' => 300, 'paid_from' => 'bank', 'spent_on' => now()]);

    $csv = MarketingBudgetResource::spendRegisterCsv($budget->fresh());

    $offer = collect($csv['rows'])->firstWhere(2, 'Ramadan offer');
    $total = collect($csv['rows'])->last();

    expect((float) $offer[3])->toBe(200.0)
        ->and($offer[1])->toBe('Offer')                 // category headline-cased
        ->and((float) $total[3])->toBe(500.0)           // 200 + 300
        ->and(round((float) $budget->fresh()->spent_amount, 2))->toBe(500.0); // ties to the fund
});

it('filters the budget list to only over-budget properties', function () {
    $this->actingAs(makeUser('super_admin'));
    $asset = makeAsset(['code' => 'HW']);
    Filament::setTenant($asset);

    // Healthy: spent < accrued. Over budget: spent > accrued.
    $healthy = MarketingBudget::create(['asset_id' => $asset->id, 'period_year' => 2025, 'accrued_amount' => 1000, 'spent_amount' => 400]);
    $over = MarketingBudget::create(['asset_id' => $asset->id, 'period_year' => 2026, 'accrued_amount' => 1000, 'spent_amount' => 1500]);

    Livewire::test(ListMarketingBudgets::class)
        ->filterTable('over_budget')
        ->assertCanSeeTableRecords([$over])
        ->assertCanNotSeeTableRecords([$healthy]);
});
