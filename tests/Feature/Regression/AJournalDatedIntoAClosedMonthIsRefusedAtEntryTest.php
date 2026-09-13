<?php

use App\Filament\Admin\Resources\JournalEntries\Pages\CreateJournalEntry;
use App\Filament\Admin\Resources\JournalEntries\Pages\EditJournalEntry;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\PeriodService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * **A manual journal dated into a closed month is refused when it is keyed, on the date field.**
 *
 * Driven as the accountant (2026-09-13 workflow audit): the create page accepted a draft dated
 * into a CLOSED month, the accountant keyed every line, and only *Post* refused it — with nothing
 * but reopening the month able to fix it. Every other money document's create page already
 * refused a closed period at entry (`CreateVendorBill`, `CreatePayment`, `CreateCreditNote`, the
 * F-89/F-93 shape); the accountant's OWN document was the one that did not. The market refuses a
 * date in a closed post month at entry.
 *
 * Stated, and deliberately kept: an UNBALANCED draft is still allowed — a parked entry may be
 * finished later, and Post is where it must balance. A MISSING period is allowed too, as
 * `PostingDate` says.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->asset = makeAsset(['code' => 'JCL']);
    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    $this->march = AccountingPeriod::forDate(CarbonImmutable::create(2026, 3, 1));
    app(PeriodService::class)->closePeriod($this->march);

    $ar = app(AccountResolver::class);
    $this->lines = [
        ['ledger_account_id' => $ar->id('accounts_receivable'), 'debit' => 100, 'credit' => 0],
        ['ledger_account_id' => $ar->id('rent_revenue'), 'debit' => 0, 'credit' => 100],
    ];
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('refuses a draft dated into the closed month, on the date field, and creates nothing', function () {
    Livewire::test(CreateJournalEntry::class)
        ->fillForm(['entry_date' => '2026-03-15', 'description_en' => 'Closed-month probe', 'lines' => $this->lines])
        ->call('create')
        ->assertHasFormErrors(['entry_date']);

    expect(JournalEntry::where('description_en', 'Closed-month probe')->exists())->toBeFalse();
});

it('still accepts the same entry dated into an open month — the control', function () {
    Livewire::test(CreateJournalEntry::class)
        ->fillForm(['entry_date' => '2026-04-15', 'description_en' => 'Open-month probe', 'lines' => $this->lines])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(JournalEntry::where('description_en', 'Open-month probe')->value('status'))->toBe('draft');
});

it('refuses moving a draft\'s date into the closed month, and leaves an unchanged date alone', function () {
    Livewire::test(CreateJournalEntry::class)
        ->fillForm(['entry_date' => '2026-04-15', 'description_en' => 'Movable draft', 'lines' => $this->lines])
        ->call('create')->assertHasNoFormErrors();
    $draft = JournalEntry::where('description_en', 'Movable draft')->firstOrFail();

    Livewire::test(EditJournalEntry::class, ['record' => $draft->getRouteKey()])
        ->fillForm(['entry_date' => '2026-03-20'])
        ->call('save')
        ->assertHasFormErrors(['entry_date']);

    expect($draft->fresh()->entry_date->toDateString())->toBe('2026-04-15');

    // A draft keyed BEFORE its month closed stays editable for its other fields: the date is not
    // re-asked when it did not move. Close April under it, then rename it.
    app(PeriodService::class)->closePeriod(AccountingPeriod::forDate(CarbonImmutable::create(2026, 4, 1)));

    Livewire::test(EditJournalEntry::class, ['record' => $draft->getRouteKey()])
        ->fillForm(['description_en' => 'Movable draft, renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($draft->fresh()->description_en)->toBe('Movable draft, renamed');
});

it('still allows an unbalanced draft into an open month — Post is where it must balance', function () {
    $ar = app(AccountResolver::class);

    Livewire::test(CreateJournalEntry::class)
        ->fillForm(['entry_date' => '2026-04-15', 'description_en' => 'Parked, unbalanced', 'lines' => [
            ['ledger_account_id' => $ar->id('accounts_receivable'), 'debit' => 100, 'credit' => 0],
            ['ledger_account_id' => $ar->id('rent_revenue'), 'debit' => 0, 'credit' => 90],
        ]])
        ->call('create')
        ->assertHasNoFormErrors();

    $draft = JournalEntry::where('description_en', 'Parked, unbalanced')->firstOrFail();

    Livewire::test(EditJournalEntry::class, ['record' => $draft->getRouteKey()])
        ->callAction('post')
        ->assertNotified();

    expect($draft->fresh()->status)->toBe('draft');
});
