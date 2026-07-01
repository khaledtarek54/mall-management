<?php

use App\Filament\Admin\Resources\AccountingPeriods\Pages\ListAccountingPeriods;
use App\Models\AccountingPeriod;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\YearEndCloseService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(makeAsset());
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('renders the accounting periods list', function () {
    Livewire::test(ListAccountingPeriods::class)->assertOk();
});

it('hides period-management actions from users without the manage permission', function () {
    $this->actingAs(makeUser('viewer')); // has accounting_periods.view, NOT .manage

    Livewire::test(ListAccountingPeriods::class)
        ->assertOk()
        ->assertActionHidden('year_end_close')
        ->assertActionHidden('year_end_reopen');
});

it('closes a period via the row action', function () {
    $period = AccountingPeriod::forDate(CarbonImmutable::create(2026, 3, 1));

    Livewire::test(ListAccountingPeriods::class)
        ->callTableAction('close_period', $period)
        ->assertHasNoTableActionErrors();

    expect($period->fresh()->status)->toBe('closed');
});

it('runs the year-end close from the header action', function () {
    $r = app(AccountResolver::class);
    app(JournalPostingService::class)->post(['entry_date' => '2026-03-05', 'lines' => [
        ['ledger_account_id' => $r->id('accounts_receivable'), 'debit' => 3000, 'credit' => 0],
        ['ledger_account_id' => $r->id('rent_revenue'), 'debit' => 0, 'credit' => 3000],
    ]]);

    Livewire::test(ListAccountingPeriods::class)
        ->callAction('year_end_close', ['year' => 2026])
        ->assertHasNoActionErrors();

    // Posts the closing entry AND locks the year's periods.
    expect(app(YearEndCloseService::class)->closingEntryFor(2026))->not->toBeNull();
    expect(AccountingPeriod::whereHas('fiscalYear', fn ($q) => $q->where('year', 2026))->where('status', 'open')->count())->toBe(0);

    // Reopen unlocks the periods and voids the closing entry.
    Livewire::test(ListAccountingPeriods::class)
        ->callAction('year_end_reopen', ['year' => 2026])
        ->assertHasNoActionErrors();

    expect(app(YearEndCloseService::class)->closingEntryFor(2026))->toBeNull();
    expect(AccountingPeriod::whereHas('fiscalYear', fn ($q) => $q->where('year', 2026))->where('status', 'closed')->count())->toBe(0);
});
