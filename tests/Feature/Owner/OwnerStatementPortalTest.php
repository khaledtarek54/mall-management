<?php

use App\Filament\Owner\Resources\OwnerStatements\OwnerStatementResource;
use App\Filament\Owner\Resources\OwnerStatements\Pages\ListOwnerStatements;
use App\Models\AccountingPeriod;
use App\Models\OwnerStatement;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\OwnerAccounting\FinaliseOwnerStatementRunService;
use App\Services\OwnerAccounting\GenerateOwnerStatementRunService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The owner portal exposes the deliverable to its recipient (module 32). Until now the operator
 * produced owner statements but the owner couldn't see them — they had to be emailed a PDF by hand.
 * These pin that an owner sees ONLY their own finalised/sent statements (never a draft, never
 * another owner's), read-only.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->asset = makeAsset(['code' => 'OWN']);
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

    // The resource + its record URLs live in the OWNER panel — make it the current one so the
    // table can resolve routes (else it looks in the default admin panel and 404s).
    Filament::setCurrentPanel(Filament::getPanel('owner'));
});

function finalisedStatementFor($test): OwnerStatement
{
    $run = app(GenerateOwnerStatementRunService::class)->generate($test->asset, $test->march);
    app(FinaliseOwnerStatementRunService::class)->finalise($run, $test->owner);

    return $run->fresh()->statements()->first();
}

it('shows the owner their own finalised statement, read-only', function () {
    $statement = finalisedStatementFor($this);

    $this->actingAs($this->owner);

    Livewire::test(ListOwnerStatements::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$statement]);

    // The deliverable is read-only in the portal — no create/edit/delete.
    expect(OwnerStatementResource::canCreate())->toBeFalse()
        ->and(OwnerStatementResource::canEdit($statement))->toBeFalse()
        ->and(OwnerStatementResource::canDelete($statement))->toBeFalse();
});

it('hides a draft statement — the operator working state is not the owner’s to see', function () {
    // Generated but NOT finalised → still a draft.
    $run = app(GenerateOwnerStatementRunService::class)->generate($this->asset, $this->march);
    $draft = $run->statements()->first();

    $this->actingAs($this->owner);

    Livewire::test(ListOwnerStatements::class)
        ->assertOk()
        ->assertCanNotSeeTableRecords([$draft]);
});

it('never shows one owner another owner’s statement', function () {
    $mine = finalisedStatementFor($this);

    // A second owner with their own mall + finalised statement.
    $otherAsset = makeAsset(['code' => 'OTH']);
    $otherOwner = makeUser('owner');
    $otherAsset->owners()->attach($otherOwner->id, ['ownership_percentage' => 100]);
    $post = app(JournalPostingService::class);
    $r = app(AccountResolver::class);
    $post->post(['entry_date' => '2026-03-10', 'asset_id' => $otherAsset->id, 'lines' => [
        ['ledger_account_id' => $r->id('accounts_receivable'), 'debit' => 8000, 'credit' => 0],
        ['ledger_account_id' => $r->id('rent_revenue'), 'debit' => 0, 'credit' => 8000],
    ]]);
    $otherRun = app(GenerateOwnerStatementRunService::class)->generate($otherAsset, $this->march);
    app(FinaliseOwnerStatementRunService::class)->finalise($otherRun, $otherOwner);
    $theirs = $otherRun->fresh()->statements()->first();

    $this->actingAs($this->owner);

    Livewire::test(ListOwnerStatements::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});
