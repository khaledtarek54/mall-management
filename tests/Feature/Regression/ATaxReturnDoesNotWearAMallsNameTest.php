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
use Filament\Schemas\Schema;
use Livewire\Livewire;

/**
 * **A RETURN FILED PER TAX REGISTRATION DOES NOT WEAR ONE MALL'S NAME** (SW-200).
 *
 * `VatReturn::report()` and `WithholdingTaxReturn::report()` each pass a NULL asset to their
 * service, deliberately, with the reason written beside the call: one tax registration covers the
 * whole portfolio, so there is no per-mall VAT return and no per-mall Form 41 to file. Both pages
 * then inherited `ScopesLedgerReport::ledgerFilterComponents()`, whose property control is
 * `PropertyField::reportScope()` — **pinned to the mall in the switcher**.
 *
 * So the strip above a statutory filing position named one mall while the figures under it were
 * every mall's. That is the exact failure `reportScope()` was built to end — *"the figures were
 * right every time and the caption above them was wrong"* — arriving through the other door, and it
 * is the more dangerous of the two directions, because nobody re-checks a total they believe they
 * asked for.
 *
 * The control is a STATEMENT now, not a picker, and the page carries no property at all — a pinned
 * `assetId` would otherwise ride out on a saved view and on every shared link as the property these
 * figures came from.
 *
 * **A statement rather than nothing.** Every screen in this panel is property-scoped, so a return
 * showing no scope control would be read as inheriting the mall in the switcher: the same wrong
 * answer, told by silence.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    ensureAllPropertiesAsset();

    $this->here = makeAsset(['code' => 'HERE']);
    $this->elsewhere = makeAsset(['code' => 'ELSE']);

    $this->actingAs(makeUser('accounting', [$this->here->id, $this->elsewhere->id]));

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->here, isQuiet: true);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('shows the VAT return a scope it can honour, not the mall in the switcher', function () {
    $instance = Livewire::test(VatReturn::class)->instance();

    $field = $instance->filtersForm(app(Schema::class)->livewire($instance))->getComponent('assetId');

    // Present, because silence on a property-scoped panel reads as "this mall"; disabled, because
    // there is nothing here anyone may change; and saying what it is really reporting on.
    expect($field)->not->toBeNull()
        ->and($field->isDisabled())->toBeTrue()
        ->and($field->getPlaceholder())->toBe(__('admin.reports.property_scope_registration'))
        ->and($instance->assetId)->toBeNull();
});

it('shows the withholding return the same scope, which is the page the old list never swept', function () {
    $instance = Livewire::test(WithholdingTaxReturn::class)->instance();

    $field = $instance->filtersForm(app(Schema::class)->livewire($instance))->getComponent('assetId');

    expect($field)->not->toBeNull()
        ->and($field->isDisabled())->toBeTrue()
        ->and($field->getPlaceholder())->toBe(__('admin.reports.property_scope_registration'))
        ->and($instance->assetId)->toBeNull();
});

it('proves the caption was a lie and not merely redundant', function () {
    // Input VAT filed against the OTHER mall — the one the operator is not standing in. If the
    // return really were scoped to the pinned property this would be absent from its figures, and
    // the old caption would merely have been redundant.
    $accounts = app(AccountResolver::class);

    // Drafted, filled, THEN posted — a line on a posted entry cannot change, which is the point of
    // `JournalLine`'s immutability guard.
    $entry = JournalEntry::create([
        'number' => 'JE-'.uniqid(),
        'asset_id' => $this->elsewhere->id,
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

    // `report()` is protected; the page's own view reaches it because Livewire renders through
    // `Closure::bind($view, $component, $component)`, so binding a closure the same way tests the
    // real call path instead of widening the method for a test's convenience.
    $page = Livewire::test(VatReturn::class)->instance();

    $report = Closure::bind(fn () => $this->report(), $page, $page)();

    expect((float) ($report['input_vat'] ?? 0))->toBeGreaterThanOrEqual(1_400.0);
});

it('still pins the property on a statement that really is answered for one mall', function () {
    // The control, and the reason this is an opt-out rather than a deletion. A trial balance
    // narrows with `whereIn('je.asset_id', …)`, so it genuinely reports one mall and the pinned
    // caption is the truth. If this ever goes red the fix above took the pin off the panel.
    $instance = Livewire::test(TrialBalance::class)->instance();

    $field = $instance->filtersForm(app(Schema::class)->livewire($instance))->getComponent('assetId');

    expect($field)->not->toBeNull()
        ->and($field->isDisabled())->toBeTrue()
        ->and($instance->assetId)->toBe($this->here->id);
});
