<?php

use App\Filament\Admin\RelationManagers\OwnerStatementsRelationManager;
use App\Filament\Admin\Resources\OwnerStatementRuns\OwnerStatementRunResource;
use App\Filament\Admin\Resources\OwnerStatementRuns\Pages\ViewOwnerStatementRun;
use App\Models\AccountingPeriod;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\OwnerAccounting\GenerateOwnerStatementRunService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A statement run must show the statements it produced.
 *
 * The run resource had an index page and nothing else. A run could be generated, finalised, revised,
 * PDF'd and sent while the per-owner statements — the module's actual OUTPUT — were never listed
 * anywhere: the working breakdown showed the property's totals, and the PDF showed one owner's copy.
 * "Who gets what out of this run, and has it gone out?" had no screen.
 *
 * The run is built by the real `GenerateOwnerStatementRunService` off real posted journal entries,
 * not hand-constructed rows. A fixture that inserts its own run drifts from the schema the moment a
 * column is added — and cannot prove the screen shows what the service actually produces.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    ensureAllPropertiesAsset();
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->asset = makeAsset(['code' => 'OSV']);
    $this->owner = makeUser('owner');
    $this->asset->owners()->attach($this->owner->id, ['ownership_percentage' => 100]);
    $this->march = AccountingPeriod::forDate(CarbonImmutable::create(2026, 3, 15));

    $post = app(JournalPostingService::class);
    $r = app(AccountResolver::class);
    $post->post(['entry_date' => '2026-03-10', 'asset_id' => $this->asset->id, 'lines' => [
        ['ledger_account_id' => $r->id('accounts_receivable'), 'debit' => 10000, 'credit' => 0],
        ['ledger_account_id' => $r->id('rent_revenue'), 'debit' => 0, 'credit' => 10000],
    ]]);
    $post->post(['entry_date' => '2026-03-12', 'asset_id' => $this->asset->id, 'lines' => [
        ['ledger_account_id' => $r->id('salaries_expense'), 'debit' => 4000, 'credit' => 0],
        ['ledger_account_id' => $r->id('bank'), 'debit' => 0, 'credit' => 4000],
    ]]);

    $this->run = app(GenerateOwnerStatementRunService::class)->generate($this->asset, $this->march);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('super_admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('renders the run view page', function () {
    asTenant($this->asset, function () {
        Livewire::test(ViewOwnerStatementRun::class, ['record' => $this->run->getKey()])->assertOk();
    });
});

it('lists each owner’s statement on the run', function () {
    // The run produced exactly one statement (a single 100% owner) — and until now nothing showed it.
    $statement = $this->run->statements()->sole();

    asTenant($this->asset, function () use ($statement) {
        Livewire::test(OwnerStatementsRelationManager::class, [
            'ownerRecord' => $this->run,
            'pageClass' => ViewOwnerStatementRun::class,
        ])
            ->assertOk()
            ->assertCanSeeTableRecords([$statement]);
    });
});

it('shows the owner their share of the property net', function () {
    // 10,000 revenue − 4,000 expense = 6,000 net, 100% owned. The point of listing statements is
    // that this number is READABLE without opening a PDF.
    $statement = $this->run->statements()->sole();

    expect((float) $statement->owner_share)->toBe(6000.0)
        ->and((float) $statement->ownership_percentage)->toBe(100.0);
});

it('gates the statements behind the same permission as the PDF', function () {
    // A statement carries one owner's income; the relation manager must not be a way around the
    // gate the run's PDF and send actions already apply.
    $this->actingAs(makeUser('accounting'));

    expect(OwnerStatementsRelationManager::canViewForRecord($this->run, ViewOwnerStatementRun::class))
        ->toBe(OwnerStatementRunResource::canViewStatements())
        ->toBeTrue();
});
