<?php

use App\Filament\Admin\Widgets\ActionRequired;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * Money posted with no property reaches no owner statement, and nothing counted it.
 *
 * A null `asset_id` is a deliberate choice rather than an accident — the journal-entry form offers
 * it, labelled "consolidated", for an operator-level entry belonging to no single mall. So refusing
 * it would break a feature somebody asked for.
 *
 * The problem is what happens afterwards. **Every owner statement is generated per asset** —
 * `GenerateOwnerStatementRunService` scopes `where('asset_id', $asset->id)` — so a consolidated
 * entry appears in NO statement, while the portfolio-wide trial balance still balances to the
 * penny. Revenue posted this way understates the owner's statement and no report disagrees with
 * any other. There was no screen anywhere that listed these, and no count.
 *
 * One scope answers it (`JournalEntry::withoutProperty()`), read by both the Action Required card
 * and the journal table's filter, so the number and the rows cannot drift apart.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->asset = makeAsset(['code' => 'MALL']);
});

/** Post a balanced entry, with or without a property dimension. */
function postEntry(?int $assetId): JournalEntry
{
    $accounts = app(AccountResolver::class);

    return app(JournalPostingService::class)->post([
        'entry_date' => now()->toDateString(),
        'asset_id' => $assetId,
        'description' => 'Consolidated adjustment',
        'source_type' => 'manual',
        'lines' => [
            ['ledger_account_id' => $accounts->id('bank', $assetId), 'debit' => 1000, 'credit' => 0],
            ['ledger_account_id' => $accounts->id('misc_income', $assetId), 'debit' => 0, 'credit' => 1000],
        ],
    ]);
}

it('finds a posted entry that belongs to no property', function () {
    postEntry(null);

    expect(JournalEntry::withoutProperty()->count())->toBe(1);
});

it('leaves a properly dimensioned entry alone — the paired control', function () {
    // Without this the scope could be counting every entry and the card would cry wolf on a book
    // that is perfectly assigned.
    postEntry($this->asset->id);

    expect(JournalEntry::withoutProperty()->count())->toBe(0);
});

it('does not count a DRAFT entry that has not hit the books', function () {
    // The concern is money in no property's books. A draft is in nobody's books yet, so flagging it
    // would nag the operator about a decision they are still making.
    // Built directly as a draft: a POSTED entry is immutable, so it cannot be demoted to one —
    // which is the correct refusal and is why this fixture takes the other route.
    JournalEntry::create([
        'entry_date' => now()->toDateString(),
        'asset_id' => null,
        'status' => 'draft',
        'source_type' => 'manual',
    ]);

    expect(JournalEntry::withoutProperty()->count())->toBe(0);
});

it('renders the Action Required card so nobody has to think to look', function () {
    // The whole point: the trial balance still balances, so this cannot be found by noticing a
    // discrepancy. It has to appear somewhere an operator already looks.
    postEntry(null);
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    asTenant($this->asset, function () {
        Livewire::test(ActionRequired::class)
            ->assertSuccessful()
            ->assertSee(__('admin.widgets.action_required.ledger_without_property_body'));
    });
});

it('shows no card when every entry carries its property — the paired control', function () {
    // A refusal-shaped assertion passes just as happily when the card never renders at all.
    postEntry($this->asset->id);
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    asTenant($this->asset, function () {
        Livewire::test(ActionRequired::class)
            ->assertSuccessful()
            ->assertDontSee(__('admin.widgets.action_required.ledger_without_property_body'));
    });
});

it('hides the card from somebody the linked register would 403', function () {
    // The widget maps every card key to the permission governing the register it links to, and a
    // card that walks an unauthorised user into a 403 is worse than no card. Asserted through the
    // rendered widget rather than by reading the registry — the registry is the mechanism, this is
    // the outcome.
    postEntry(null);
    $this->actingAs(makeUser('technician', [$this->asset->id]));

    asTenant($this->asset, function () {
        Livewire::test(ActionRequired::class)
            ->assertSuccessful()
            ->assertDontSee(__('admin.widgets.action_required.ledger_without_property_body'));
    });
});
