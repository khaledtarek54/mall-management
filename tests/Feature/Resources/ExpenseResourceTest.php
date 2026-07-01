<?php

use App\Filament\Admin\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Admin\Resources\Expenses\Pages\EditExpense;
use App\Filament\Admin\Resources\Expenses\Pages\ListExpenses;
use App\Models\Expense;
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

it('renders the expenses list and create screens', function () {
    Livewire::test(ListExpenses::class)->assertOk();
    Livewire::test(CreateExpense::class)->assertOk();
});

it('creates a direct expense (total derived) and cancels it through the UI', function () {
    Livewire::test(CreateExpense::class)
        ->fillForm([
            'category' => 'utilities',
            'paid_from' => 'cash',
            'expense_date' => now()->toDateString(),
            'amount' => 1000,
            'vat_amount' => 140,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $expense = Expense::latest('id')->first();
    expect($expense->status)->toBe('recorded');
    expect((float) $expense->total)->toEqualWithDelta(1140.0, 0.001); // model-derived

    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->callAction('cancel_expense')
        ->assertHasNoActionErrors();

    expect($expense->fresh()->status)->toBe('cancelled');
});
