<?php

use App\Filament\Admin\Resources\LedgerAccounts\Pages\CreateLedgerAccount;
use App\Filament\Admin\Resources\LedgerAccounts\Pages\ListLedgerAccounts;
use App\Models\LedgerAccount;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(makeAsset());
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('renders the chart of accounts list and create screens', function () {
    Livewire::test(ListLedgerAccounts::class)->assertOk();
    Livewire::test(CreateLedgerAccount::class)->assertOk();
});

it('creates an account and auto-derives its parent from the code', function () {
    Livewire::test(CreateLedgerAccount::class)
        ->fillForm([
            'code' => '41102099', 'type' => 'revenue',
            'name_ar' => 'حساب اختبار', 'name_en' => 'Test Account', 'is_postable' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $account = LedgerAccount::where('code', '41102099')->first();
    expect($account->parent->code)->toBe('41102'); // deepest existing prefix
});

it('rejects a code whose leading digit contradicts the chosen type (inline error)', function () {
    Livewire::test(CreateLedgerAccount::class)
        ->fillForm([
            'code' => '41102099', 'type' => 'expense', // 4… is a revenue range
            'name_ar' => 'خطأ', 'name_en' => 'Bad', 'is_postable' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['code']);

    expect(LedgerAccount::where('code', '41102099')->exists())->toBeFalse();
});
