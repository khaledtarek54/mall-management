<?php

use App\Filament\Admin\Resources\Disbursements\Pages\ListDisbursements;
use App\Filament\Admin\Resources\OwnerStatementRuns\Pages\ListOwnerStatementRuns;
use App\Models\AccountingPeriod;
use App\Models\Disbursement;
use App\Models\OwnerStatementRun;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\OwnerAccounting\DisbursementService;
use App\Services\OwnerAccounting\FinaliseOwnerStatementRunService;
use App\Services\OwnerAccounting\GenerateOwnerStatementRunService;
use App\Services\OwnerAccounting\OwnerStatementPdfService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * Slice 7 — the operator UI. Renders the two boards WITH rows (an empty table hides the
 * column + action-`visible()` closure bugs) and drives the Generate action through to the
 * service, plus a permission-gate check.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    ensureAllPropertiesAsset();
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->asset = makeAsset(['code' => 'OSR']);
    $this->owner = makeUser('owner');
    $this->asset->owners()->attach($this->owner->id, ['ownership_percentage' => 100]);
    $this->march = AccountingPeriod::forDate(CarbonImmutable::create(2026, 3, 15));

    // Post a property net of 6000 for March.
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
});

it('renders the owner-statement-runs board with a finalised row (all closures survive)', function () {
    $run = app(FinaliseOwnerStatementRunService::class)->finalise(
        app(GenerateOwnerStatementRunService::class)->generate($this->asset, $this->march),
        $this->owner,
    );

    $this->actingAs(makeUser('manager', [$this->asset->id]));

    asTenant($this->asset, function () use ($run) {
        Livewire::test(ListOwnerStatementRuns::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$run]);
    });
});

it('generates a draft run through the Generate header action', function () {
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    asTenant($this->asset, function () {
        Livewire::test(ListOwnerStatementRuns::class)
            ->callAction('generate', ['accounting_period_id' => $this->march->id]);
    });

    expect(OwnerStatementRun::where('asset_id', $this->asset->id)->where('status', 'draft')->exists())->toBeTrue();
});

it('renders the disbursements board with a paid row', function () {
    $run = app(FinaliseOwnerStatementRunService::class)->finalise(
        app(GenerateOwnerStatementRunService::class)->generate($this->asset, $this->march),
        $this->owner,
    );
    $operator = makeUser('manager', [$this->asset->id]);
    $d = app(DisbursementService::class)->schedule($run->statements->first(), 6000, Disbursement::METHOD_BANK_TRANSFER, $operator);
    app(DisbursementService::class)->markPaid(app(DisbursementService::class)->approve($d, $operator), $operator, '2026-03-31');

    $this->actingAs($operator);

    asTenant($this->asset, function () use ($d) {
        Livewire::test(ListDisbursements::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$d->fresh()]);
    });
});

it('hides the Generate action from a user without owner_statements.generate', function () {
    // viewer has owner_statements.view (sees the board) but NOT .generate.
    $this->actingAs(makeUser('viewer', [$this->asset->id]));

    asTenant($this->asset, function () {
        Livewire::test(ListOwnerStatementRuns::class)
            ->assertSuccessful()
            ->assertActionHidden('generate');
    });
});

it('renders the owner statement as a valid PDF', function () {
    $run = app(FinaliseOwnerStatementRunService::class)->finalise(
        app(GenerateOwnerStatementRunService::class)->generate($this->asset, $this->march),
        $this->owner,
    );

    $pdf = app(OwnerStatementPdfService::class)->build($run->statements->first());

    expect($pdf)->toStartWith('%PDF')
        ->and(strlen($pdf))->toBeGreaterThan(1000);
});

it('sends a finalised statement to the owner (marks it sent + bells the owner)', function () {
    $run = app(FinaliseOwnerStatementRunService::class)->finalise(
        app(GenerateOwnerStatementRunService::class)->generate($this->asset, $this->march),
        $this->owner,
    );

    $this->actingAs(makeUser('manager', [$this->asset->id]));

    asTenant($this->asset, function () use ($run) {
        Livewire::test(ListOwnerStatementRuns::class)->callTableAction('send', $run);
    });

    expect($run->statements->first()->fresh()->status)->toBe('sent')
        ->and($this->owner->notifications()->count())->toBe(1);
});
