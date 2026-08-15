<?php

/*
|--------------------------------------------------------------------------
| "Has this month's statement gone to the owner?" must be answerable
|--------------------------------------------------------------------------
| Two defects of the same shape — a screen that cannot answer the question it appears to answer,
| and a document that can be finalised with nobody to give it to.
|
| 1. The runs list had a **Sent** tab filtering `owner_statement_runs.status = 'sent'`. A RUN is only
|    ever draft / finalised / superseded — sending marks the child STATEMENT sent and leaves the run
|    finalised. So the tab was permanently empty, and a sent run sat in Finalised beside the unsent
|    ones with nothing to tell them apart: the operator's "who still needs sending?" had no answer,
|    and "did we already send this?" was guessable only from whether a hover button had disappeared.
|
| 2. A property with no owner assigned could be generated AND finalised. `rebuildStatements()`
|    returns 0 distributed, the journalizer skips a zero, and the result is a finalised document
|    addressed to nobody that posts nothing — while the P&L underneath it shows real money. Silence
|    is the wrong answer to "who owns this mall?"; the operator gets told.
*/

use App\Filament\Admin\Resources\OwnerStatementRuns\Pages\ListOwnerStatementRuns;
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

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    ensureAllPropertiesAsset();
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->march = AccountingPeriod::forDate(CarbonImmutable::create(2026, 3, 15));
    $this->actor = makeUser('super_admin');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->actor);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** A property with real posted money in a month — the statement is the ledger, so it needs some. */
function earningAsset(string $code, bool $withOwner = true): \App\Models\Asset
{
    $asset = makeAsset(['code' => $code]);

    if ($withOwner) {
        $asset->propertyOwners()->attach(makeUser('owner')->id, ['ownership_percentage' => 100]);
    }

    Filament::setTenant($asset);

    return $asset;
}

/** One month's rent and salary on a property, then its draft run for that month. */
function runFor(\App\Models\Asset $asset, string $month): \App\Models\OwnerStatementRun
{
    $post = app(JournalPostingService::class);
    $r = app(AccountResolver::class);
    $post->post(['entry_date' => "{$month}-10", 'asset_id' => $asset->id, 'lines' => [
        ['ledger_account_id' => $r->id('accounts_receivable'), 'debit' => 10000, 'credit' => 0],
        ['ledger_account_id' => $r->id('rent_revenue'), 'debit' => 0, 'credit' => 10000],
    ]]);
    $post->post(['entry_date' => "{$month}-12", 'asset_id' => $asset->id, 'lines' => [
        ['ledger_account_id' => $r->id('salaries_expense'), 'debit' => 4000, 'credit' => 0],
        ['ledger_account_id' => $r->id('bank'), 'debit' => 0, 'credit' => 4000],
    ]]);

    return app(GenerateOwnerStatementRunService::class)->generate(
        $asset,
        AccountingPeriod::forDate(CarbonImmutable::parse("{$month}-15")),
    );
}

it('lists a sent statement under Sent, and an unsent one under Finalised', function () {
    // Two months on ONE property: the list is property-scoped, so the pair has to share an asset.
    $asset = earningAsset('OSA');
    $sentRun = runFor($asset, '2026-02');
    $unsentRun = runFor($asset, '2026-03');

    app(FinaliseOwnerStatementRunService::class)->finalise($sentRun, $this->actor);
    app(FinaliseOwnerStatementRunService::class)->finalise($unsentRun, $this->actor);

    // Sending marks the child statement, exactly as the Send action does.
    $sentRun->statements()->first()->update([
        'status' => OwnerStatement::STATUS_SENT,
        'sent_at' => now(),
    ]);

    Livewire::test(ListOwnerStatementRuns::class, ['activeTab' => 'sent'])
        ->assertCanSeeTableRecords([$sentRun->fresh()])
        ->assertCanNotSeeTableRecords([$unsentRun->fresh()]);

    // Finalised is the SEND worklist — what still has to go out. A run already with the owner
    // sitting in the same tab is what made the list unanswerable.
    Livewire::test(ListOwnerStatementRuns::class, ['activeTab' => 'finalised'])
        ->assertCanSeeTableRecords([$unsentRun->fresh()])
        ->assertCanNotSeeTableRecords([$sentRun->fresh()]);
});

it('says on the row whether the owner has it', function () {
    $run = runFor(earningAsset('OSC'), '2026-03');
    app(FinaliseOwnerStatementRunService::class)->finalise($run, $this->actor);

    Livewire::test(ListOwnerStatementRuns::class)
        ->assertOk()
        ->assertTableColumnStateSet('sent_at', null, $run->fresh());

    $run->statements()->first()->update([
        'status' => OwnerStatement::STATUS_SENT,
        'sent_at' => CarbonImmutable::parse('2026-04-02 09:00'),
    ]);

    Livewire::test(ListOwnerStatementRuns::class)
        ->assertTableColumnStateNotSet('sent_at', null, $run->fresh());
});

it('refuses to stamp a statement with a basis it does not compute', function () {
    // `cash` was a constant with nothing behind it — no screen offered it and the P&L has no basis
    // parameter, so a run stamped cash carried ACCRUAL figures under a cash label. The one thing an
    // owner reads a cash-basis statement to learn is which tenants actually paid.
    $run = runFor(earningAsset('OSE'), '2026-03');

    expect(fn () => $run->update(['basis' => 'cash']))->toThrow(InvalidArgumentException::class)
        // The control: the basis it does compute still saves.
        ->and($run->fresh()->update(['basis' => 'accrual']))->toBeTrue();
});

it('refuses to finalise a statement for a property with no owner', function () {
    $asset = earningAsset('OSD', withOwner: false);
    $run = runFor($asset, '2026-03');

    // The draft is allowed — it is the property's P&L, and generating it is how an operator
    // discovers the owner is missing.
    expect($run->statements()->count())->toBe(0)
        ->and((float) $run->net_operating_income)->toBe(6000.0);

    expect(fn () => app(FinaliseOwnerStatementRunService::class)->finalise($run, $this->actor))
        ->toThrow(DomainException::class);

    expect($run->fresh()->isDraft())->toBeTrue('a refused finalise must leave the run a draft');

    // The control: assign the owner and the identical call goes through, so the refusal is the
    // missing-owner rule and not a broken fixture.
    $asset->propertyOwners()->attach(makeUser('owner')->id, ['ownership_percentage' => 100]);

    $finalised = app(FinaliseOwnerStatementRunService::class)->finalise($run->fresh(), $this->actor);

    expect($finalised->isFinalised())->toBeTrue()
        ->and((float) $finalised->net_distributable)->toBe(6000.0);
});
