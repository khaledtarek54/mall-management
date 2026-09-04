<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Regression — a report whose figures come out of the ledger says how fresh the ledger is
|--------------------------------------------------------------------------
| Posting to the general ledger is a SWEEP, not a write-through: `accounting:sync-ledger` runs on a
| daily schedule, so every statement built from `journal_entries` is only as true as the last run.
| `PostsToLedger` is the answer — a "Ledger last synced …" line in the subheading and a "Post to GL
| now" button beside it — and which screens carry it was decided twice, both times by enumerating a
| diff: `a95f7e69` put it on the trial balance and the journal-entry list, and `0a2d214f` extended it
| to the income statement, the balance sheet and the general ledger under the sentence *"so no
| report can silently show stale numbers"*.
|
| The cash-flow statement shipped eight days before that second commit (`0f7be827`) and was missed
| by it. Measured at HEAD 2026-09-05: of the five pages under `app/Filament/Admin/Pages` that name
| `LedgerReportService`, it was the only one carrying neither half.
|
| It is the worst of the five to omit. That page's own integrity check asks *"do the three activity
| sections explain the movement in the cash accounts?"* — and an unposted receipt moves the bank and
| none of the sections, so on THIS statement "it does not reconcile" and "nobody has posted since
| Tuesday" are the same sentence. The reader was shown the first half and not the second.
|
| So the set is DERIVED from what a page READS, never from a list somebody keeps: a sixth ledger
| statement is covered by being one.
*/

use App\Filament\Admin\Pages\CashFlow;
use App\Filament\Admin\Pages\RentRoll;
use App\Services\Accounting\FiscalCalendar;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    ensureAllPropertiesAsset();
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/**
 * The admin pages whose FIGURES come out of the general ledger — read off disk, by what each page
 * asks for, rather than from a register that would have to be remembered.
 *
 * `ScopesLedgerReport` is deliberately NOT the probe: eight pages compose that scaffold and it
 * reaches `LedgerReportService::unallocated()` on behalf of all of them, so sweeping its users
 * would pull in the rent roll (leases) and the two tax returns (documents, through their own
 * services) — screens whose staleness question is a different one.
 *
 * @return array<int, class-string>
 */
function ledgerFedReportPages(): array
{
    $pages = [];

    foreach ((array) glob(app_path('Filament/Admin/Pages').'/*.php') as $file) {
        if (! str_contains((string) file_get_contents($file), 'LedgerReportService')) {
            continue;
        }

        $pages[] = 'App\\Filament\\Admin\\Pages\\'.basename($file, '.php');
    }

    sort($pages);

    return $pages;
}

/** Every header action the mounted page declares, groups flattened. @return array<int, Action> */
function headerActionsOf(object $page): array
{
    $actions = [];

    foreach ($page->getCachedHeaderActions() as $action) {
        if ($action instanceof ActionGroup) {
            $actions = [...$actions, ...array_values($action->getFlatActions())];

            continue;
        }

        $actions[] = $action;
    }

    return $actions;
}

it('finds the ledger-fed reports by what they read, not by a list somebody keeps', function () {
    $pages = ledgerFedReportPages();

    // A sweep that counts must assert it counted something, or it reports on a set it has silently
    // stopped collecting (CLAUDE.md records three of those).
    expect(count($pages))->toBeGreaterThanOrEqual(5)
        ->and($pages)->toContain(CashFlow::class)
        // …and NARROW. RentRoll composes the same `ScopesLedgerReport` scaffold, so a probe written
        // against the trait would have swept it too — and its figures are leases, not journal lines.
        ->and($pages)->not->toContain(RentRoll::class);
});

it('states the ledger freshness on every one of them', function () {
    $never = __('admin.reports.ledger_never_synced');
    $silent = [];

    foreach (ledgerFedReportPages() as $page) {
        $subheading = (string) Livewire::test($page)->assertOk()->instance()->getSubheading();

        if (! str_contains($subheading, $never)) {
            $silent[] = $page;
        }
    }

    // Named rather than counted: the failure diff has to say WHICH screen is quiet about it.
    expect($silent)->toBe([]);
});

it('offers "Post to GL now" on every one of them', function () {
    $without = [];

    foreach (ledgerFedReportPages() as $page) {
        $action = collect(headerActionsOf(Livewire::test($page)->assertOk()->instance()))
            ->first(fn (Action $a): bool => $a->getName() === 'post_to_ledger');

        // `isVisible()` and not merely "is declared": `->authorize()` folds into `isHidden()`, so
        // this is the same gate the panel applies, evaluated for the signed-in operator.
        if (! $action?->isVisible()) {
            $without[] = $page;
        }
    }

    expect($without)->toBe([]);
});

it('still refuses the button to somebody who may read the books but not post them', function () {
    // The control. Both sweeps above are satisfied by a button nailed permanently open, which would
    // let any reader run the sweep; `journal_entries.post` is what decides, and `viewer` lacks it.
    // `viewer` holds every `.view` in the catalogue (so `general_ledger.view`, so the page opens)
    // and no `.post`, which is exactly the split this button gates on.
    $this->actingAs(makeUser('viewer', [$this->asset->id]));

    Livewire::test(CashFlow::class)
        ->assertOk()
        ->assertActionHidden('post_to_ledger');
});
