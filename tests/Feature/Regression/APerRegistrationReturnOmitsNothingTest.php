<?php

use App\Filament\Admin\Pages\TrialBalance;
use App\Filament\Admin\Pages\VatReturn;
use App\Filament\Admin\Pages\WithholdingTaxReturn;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * **A return filed per REGISTRATION omits nothing, so it must not warn that it does.**
 *
 * `ScopesLedgerReport::unallocatedNotice()` is inherited by every page rendering
 * `filament.pages.ledger-report`, and it states in bold: *"They are NOT in the figures above."* That
 * is true of the five statements scoped to a property — `aggregate()` narrows with
 * `whereIn('je.asset_id', …)` and `whereIn` never matches NULL, so a portfolio-level entry really is
 * absent from each of them.
 *
 * It is FALSE on the two tax returns. `VatReturn::report()` and `WithholdingTaxReturn::report()`
 * both pass `null` as the asset — deliberately, and each says why: a return is filed against the
 * operator's registration, not against a mall. Their services then apply the filter as
 * `->when($assetId, …)`, so with null there is no asset predicate at all and the null-asset entries
 * ARE in the figures. The page was telling an accountant that a statutory filing position
 * understates what they owe, when it does not, and pointing them at a remedy that would move the
 * entry onto one arbitrary mall.
 *
 * The notice stays on the concern — a sixth statement should still inherit the warning rather than
 * be the one that quietly omits money. The two returns opt OUT, because for them there is nothing
 * to warn about.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'PR']);
    $this->actingAs(makeUser('accounting', [$this->asset->id]));

    // A portfolio-level entry: real VAT, filed against NO property. Head-office overhead is exactly
    // this — one insurance premium covering every mall.
    $accounts = app(AccountResolver::class);

    // Drafted, filled, THEN posted — a line on a posted entry cannot change, which is the point of
    // `JournalLine`'s immutability guard.
    $entry = JournalEntry::create([
        'number' => 'JE-'.uniqid(),
        'asset_id' => null,
        'entry_date' => now()->toDateString(),
        'status' => 'draft',
        'is_manual' => true,
    ]);

    $entry->lines()->create([
        'ledger_account_id' => $accounts->id('vat_recoverable', null),
        'debit' => 1_400, 'credit' => 0,
    ]);

    $entry->lines()->create([
        'ledger_account_id' => $accounts->id('bank', null),
        'debit' => 0, 'credit' => 1_400,
    ]);

    $entry->update(['status' => 'posted']);
});

/**
 * The notice as the BLADE gets it.
 *
 * `unallocatedNotice()` is protected; the view reaches it because Livewire renders through
 * `Closure::bind($view, $component, $component)`. Binding a closure the same way tests the real
 * call path instead of widening the method's visibility for a test's convenience.
 */
function noticeOn(string $page): ?array
{
    $component = Livewire::test($page)->instance();

    return Closure::bind(
        fn () => $this->unallocatedNotice(),
        $component,
        $component,
    )();
}

it('does not tell the VAT return that money it DID count is missing', function () {
    Filament::setTenant($this->asset, isQuiet: true);

    expect(noticeOn(VatReturn::class))->toBeNull();

    // The premise, proven rather than assumed: the entry really is in the return's own figures, so
    // the notice would have been a false statement and not merely a redundant one. `report()` is
    // protected like the notice, and reached the same way the page's own view reaches it.
    $page = Livewire::test(VatReturn::class)->instance();

    $report = Closure::bind(fn () => $this->report(), $page, $page)();

    expect((float) ($report['input_vat'] ?? 0))->toBeGreaterThanOrEqual(1_400.0);
});

it('does not tell the withholding return that either', function () {
    Filament::setTenant($this->asset, isQuiet: true);

    expect(noticeOn(WithholdingTaxReturn::class))->toBeNull();
});

it('still warns on a statement that really does omit the entry', function () {
    // The control, and the reason this is an opt-out rather than a deletion. A property-scoped
    // statement narrows with `whereIn('je.asset_id', …)`, which never matches NULL — so on the trial
    // balance that 1,400 genuinely is absent, and saying so is the whole point of the notice.
    Filament::setTenant($this->asset, isQuiet: true);

    $notice = noticeOn(TrialBalance::class);

    expect($notice)->not->toBeNull()
        ->and($notice['count'])->toBe(1)
        ->and((float) $notice['total'])->toBeGreaterThan(0.0);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));
