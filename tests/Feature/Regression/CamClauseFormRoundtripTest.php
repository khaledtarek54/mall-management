<?php

use App\Filament\Admin\Resources\CamExpensePools\Pages\CreateCamExpensePool;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Filament\Admin\RelationManagers\LeaseCamTermsRelationManager;
use App\Models\CamExpensePool;
use App\Models\LeaseCamTerm;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Regression: the CAM clause form fields store a FRACTION while operators enter a PERCENT, via
 * formatStateUsing(×100)/dehydrateStateUsing(÷100). formatStateUsing runs on hydrate INCLUDING on
 * the field's default — so a default expressed in percent (10) formats to 1000 and blows
 * maxValue(100), which is exactly how the admin_fee_pct default first shipped broken (it failed
 * CamPoolUniquePerYearTest + AllPropertiesCreatePinsAssetTest). These tests pin BOTH the no-error
 * behaviour AND the correct stored value, which the incidental failures did not assert.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'CAM-FORM']);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

function fillPool(array $overrides = []): array
{
    return array_merge([
        'period_year' => 2029,
        'status' => 'draft',
        'total_actual_expense' => 5000,
        'total_estimated_collected' => 4800,
    ], $overrides);
}

it('stores the default 10% admin fee as the fraction 0.10 when the field is left untouched', function () {
    Livewire::test(CreateCamExpensePool::class)
        ->fillForm(fillPool()) // admin_fee_pct not set → default(0.10) → shows 10 → stores 0.10
        ->call('create')
        ->assertHasNoFormErrors();

    $pool = CamExpensePool::where('asset_id', $this->asset->id)->where('period_year', 2029)->sole();
    expect((float) $pool->admin_fee_pct)->toBe(0.10);
});

it('round-trips an operator-entered percent to a fraction', function () {
    Livewire::test(CreateCamExpensePool::class)
        ->fillForm(fillPool(['period_year' => 2030, 'admin_fee_pct' => 15])) // 15% typed
        ->call('create')
        ->assertHasNoFormErrors();

    expect((float) CamExpensePool::where('period_year', 2030)->sole()->admin_fee_pct)->toBe(0.15);
});

it('stores null (no fee) when the admin fee is cleared', function () {
    Livewire::test(CreateCamExpensePool::class)
        ->fillForm(fillPool(['period_year' => 2031, 'admin_fee_pct' => null]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CamExpensePool::where('period_year', 2031)->sole()->admin_fee_pct)->toBeNull();
});

it('renders the lease CAM-terms relation manager without error', function () {
    $lease = makeLease(makeUnit($this->asset), makeTenant());

    Livewire::test(LeaseCamTermsRelationManager::class, [
        'ownerRecord' => $lease,
        'pageClass' => EditLease::class,
    ])->assertSuccessful();
});

it('round-trips a YoY cap term percent to a fraction through the relation manager', function () {
    $lease = makeLease(makeUnit($this->asset), makeTenant());

    Livewire::test(LeaseCamTermsRelationManager::class, [
        'ownerRecord' => $lease,
        'pageClass' => EditLease::class,
    ])
        ->callTableAction('create', data: [
            'effective_year' => 2025,
            'cap_type' => 'yoy',
            'base_year' => 2024,
            'base_year_amount' => 30000,
            'yoy_pct' => 5, // 5% typed
            'compounding' => true,
        ])
        ->assertHasNoTableActionErrors();

    expect((float) LeaseCamTerm::where('lease_id', $lease->id)->sole()->yoy_pct)->toBe(0.05);
});
