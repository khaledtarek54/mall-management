<?php

use App\Filament\Admin\Resources\Payrolls\Pages\CreatePayroll;
use App\Filament\Admin\Resources\Payrolls\Pages\EditPayroll;
use App\Filament\Admin\Resources\Payrolls\Pages\ListPayrolls;
use App\Models\Payroll;
use App\Services\Accounting\FiscalCalendar;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(makeAsset());
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('renders the payroll list and create screens', function () {
    Livewire::test(ListPayrolls::class)->assertOk();
    Livewire::test(CreatePayroll::class)->assertOk();
});

it('creates a payroll run (net derived) and approves it through the UI', function () {
    Livewire::test(CreatePayroll::class)
        ->fillForm([
            'period_month' => now()->startOfMonth()->toDateString(),
            'paid_from' => 'bank',
            'gross_salaries' => 50000,
            'salary_tax' => 6000,
            'social_insurance' => 4000,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $payroll = Payroll::latest('id')->first();
    expect($payroll->status)->toBe('draft');
    expect((float) $payroll->net_paid)->toEqualWithDelta(40000.0, 0.001); // model-derived

    Livewire::test(EditPayroll::class, ['record' => $payroll->getRouteKey()])
        ->callAction('approve')
        ->assertHasNoActionErrors();

    expect($payroll->fresh()->status)->toBe('approved');
});

it('hides approve from a user who can edit but not approve', function () {
    $payroll = Payroll::create([
        'asset_id' => null, 'period_month' => now()->startOfMonth()->toDateString(),
        'gross_salaries' => 1000, 'salary_tax' => 0, 'social_insurance' => 0,
        'paid_from' => 'bank', 'status' => 'draft',
    ]);

    // A user who can view + edit payrolls but was NOT granted payrolls.approve.
    $user = makeUser('viewer');
    $user->givePermissionTo('payrolls.edit');
    $this->actingAs($user);

    Livewire::test(EditPayroll::class, ['record' => $payroll->getRouteKey()])
        ->assertActionHidden('approve');
});
