<?php

/*
|--------------------------------------------------------------------------
| Regression — CAM expense pool is unique per (asset_id, period_year)
|--------------------------------------------------------------------------
| GUARD: CamPoolUniquePerYear. The admin CAM Expense Pool form's
| 'period_year' field carries a ->unique(ignoreRecord: true, modifyRuleUsing:
| where('asset_id', ...)) rule, backed by the cam_pool_asset_year_unique DB
| index on (asset_id, period_year). For the active Filament asset you may
| only have ONE pool per period year:
|   (a) re-using an existing year for the same asset  -> rejected (unique)
|   (b) a different year for the same asset           -> accepted
|
| We mount the real CreateCamExpensePool Livewire page with an asset set as
| the Filament tenant (asset_id is defaulted + disabled + dehydrated to that
| asset), and assert the form errors on the duplicate year only.
*/

use App\Filament\Admin\Resources\CamExpensePools\Pages\CreateCamExpensePool;
use App\Models\CamExpensePool;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset(['code' => 'CAM-A']);

    // Pre-existing pool occupies (asset, 2026).
    $this->existing = CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => 2026,
        'total_actual_expense' => 100,
        'total_estimated_collected' => 100,
        'status' => 'draft',
    ]);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

function fillCamPool(array $overrides = []): array
{
    return array_merge([
        'period_year' => 2027,
        'status' => 'draft',
        'total_actual_expense' => 5000,
        'total_estimated_collected' => 4800,
    ], $overrides);
}

it('rejects a duplicate period year for the same asset', function () {
    Livewire::test(CreateCamExpensePool::class)
        ->fillForm(fillCamPool(['period_year' => 2026])) // already taken for this asset
        ->call('create')
        ->assertHasFormErrors(['period_year' => 'unique']);

    // No second pool was written for (asset, 2026).
    expect(CamExpensePool::where('asset_id', $this->asset->id)->where('period_year', 2026)->count())
        ->toBe(1);
});

it('accepts a different period year for the same asset', function () {
    Livewire::test(CreateCamExpensePool::class)
        ->fillForm(fillCamPool(['period_year' => 2027])) // free year for this asset
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CamExpensePool::where('asset_id', $this->asset->id)->where('period_year', 2027)->exists())
        ->toBeTrue();
});

it('enforces the (asset_id, period_year) uniqueness at the database level', function () {
    expect(fn () => CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => 2026, // collides with $this->existing
        'total_actual_expense' => 1,
        'total_estimated_collected' => 1,
        'status' => 'draft',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
