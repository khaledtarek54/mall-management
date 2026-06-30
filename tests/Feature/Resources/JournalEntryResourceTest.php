<?php

use App\Filament\Admin\Pages\BalanceSheet;
use App\Filament\Admin\Pages\GeneralLedger;
use App\Filament\Admin\Pages\IncomeStatement;
use App\Filament\Admin\Pages\TrialBalance;
use App\Filament\Admin\Resources\JournalEntries\Pages\CreateJournalEntry;
use App\Filament\Admin\Resources\JournalEntries\Pages\EditJournalEntry;
use App\Filament\Admin\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Filament\Admin\Resources\LedgerAccounts\Pages\ListLedgerAccounts;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountResolver;
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

it('renders the accounting screens', function () {
    Livewire::test(ListLedgerAccounts::class)->assertOk();
    Livewire::test(ListJournalEntries::class)->assertOk();
    Livewire::test(TrialBalance::class)->assertOk();
    Livewire::test(GeneralLedger::class)->assertOk();
    Livewire::test(IncomeStatement::class)->assertOk();
    Livewire::test(BalanceSheet::class)->assertOk();
});

it('creates a draft journal entry and posts it through the UI', function () {
    $ar = app(AccountResolver::class);

    Livewire::test(CreateJournalEntry::class)
        ->fillForm([
            'entry_date' => now()->toDateString(),
            'description_en' => 'UI smoke entry',
            'lines' => [
                ['ledger_account_id' => $ar->id('accounts_receivable'), 'debit' => 1140, 'credit' => 0],
                ['ledger_account_id' => $ar->id('rent_revenue'), 'debit' => 0, 'credit' => 1000],
                ['ledger_account_id' => $ar->id('vat_payable'), 'debit' => 0, 'credit' => 140],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $entry = JournalEntry::latest('id')->first();
    expect($entry)->not->toBeNull();
    expect($entry->status)->toBe('draft');
    expect($entry->lines)->toHaveCount(3);

    Livewire::test(EditJournalEntry::class, ['record' => $entry->getRouteKey()])
        ->callAction('post')
        ->assertHasNoActionErrors();

    expect($entry->fresh()->status)->toBe('posted');
});
