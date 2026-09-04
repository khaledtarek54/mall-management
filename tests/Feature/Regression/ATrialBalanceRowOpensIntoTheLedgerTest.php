<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A trial-balance figure can be opened (SW-178)
|--------------------------------------------------------------------------
| The drill-down that shipped with `FinancialStatementDrilldownTest` named four terminal screens —
| income statement, balance sheet, trial balance, general ledger — and wired three. The URL builder
| went onto `RendersFinancialStatement`, which the trial balance does not use, so the one screen an
| accountant opens to ask "what is IN 11101?" stayed the one screen that could not answer, although
| its rows have carried `account_id` since the page was written.
|
| The builder now lives on `ScopesLedgerReport` — where `$year`, `$period` and `$assetId` are
| declared, and which every ledger report uses — so the statements and the trial balance open the
| same ledger with the same scope, from one definition.
*/

use App\Filament\Admin\Pages\IncomeStatement;
use App\Filament\Admin\Pages\TrialBalance;
use App\Models\LedgerAccount;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset(['code' => 'TB']);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('turns an account row into a link carrying the report own period and property', function (): void {
    $account = LedgerAccount::query()->where('is_postable', true)->orderBy('code')->firstOrFail();

    // "Show accounts with no movement" so the row set is non-empty on a fresh install — the URL
    // closure runs per row, so this also proves the page still renders with it wired.
    $component = Livewire::test(TrialBalance::class)->set('includeZeroBalances', true)->assertOk();
    $page = $component->instance();

    // The row set the operator is looking at really does carry the account id.
    $ids = collect(invade($page)->report()['rows'])->pluck('account_id')->all();
    expect($ids)->toContain($account->id);

    $url = $page->getTable()
        ->getColumn('account')
        ->record(['id' => $account->id, 'type' => $account->type])
        ->getUrl();

    expect($url)->toContain('general-ledger')
        ->and($url)->toContain('accountId='.$account->id)
        // …and the report's OWN year and property, not today's and not "all properties".
        ->and($url)->toContain('year='.$page->year)
        ->and($url)->toContain('assetId='.$this->asset->id);
});

it('still leaves a statement total alone and offers nothing for a row with no account', function (): void {
    // The moved guard, both halves. A total and a group subtotal are not accounts, so there is
    // nothing to open; a link on either sends the operator to a ledger of "everything", which
    // answers a question they did not ask.
    $account = LedgerAccount::query()->where('is_postable', true)->orderBy('code')->firstOrFail();

    $page = new IncomeStatement;
    $page->mount();

    expect(invade($page)->ledgerUrlForAccount(null))->toBeNull()
        ->and(invade($page)->ledgerUrlFor(['is_total' => true, 'account_id' => null]))->toBeNull()
        ->and(invade($page)->ledgerUrlFor(['is_total' => false, 'is_subtotal' => true, 'account_id' => $account->id]))->toBeNull()
        ->and(invade($page)->ledgerUrlFor(['is_total' => false, 'account_id' => null]))->toBeNull()
        // The control — an ordinary leaf still links, so the nulls above are the guard and not a
        // builder that has stopped building.
        ->and(invade($page)->ledgerUrlFor(['is_total' => false, 'account_id' => $account->id]))
        ->toContain('accountId='.$account->id);
});

it('builds the same link from the statement and from the trial balance', function (): void {
    // One definition, asked from both sides. A second copy of this URL is how one report ends up
    // opening a different period from the one it was clicked in.
    $account = LedgerAccount::query()->where('is_postable', true)->orderBy('code')->firstOrFail();

    $statement = new IncomeStatement;
    $statement->mount();

    $trialBalance = new TrialBalance;
    $trialBalance->mount();

    expect(invade($trialBalance)->ledgerUrlForAccount($account->id))
        ->toBe(invade($statement)->ledgerUrlForAccount($account->id))
        ->and(invade($trialBalance)->ledgerUrlForAccount($account->id))->not->toBeNull();
});
