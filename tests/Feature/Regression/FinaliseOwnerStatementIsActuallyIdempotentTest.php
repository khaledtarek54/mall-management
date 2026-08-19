<?php

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\OwnerStatementRun;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\OwnerAccounting\FinaliseOwnerStatementRunService;
use App\Services\OwnerAccounting\GenerateOwnerStatementRunService;
use App\Services\OwnerAccounting\ReviseOwnerStatementRunService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

/**
 * `finalise()` is idempotent, as its own comment has always claimed — F-13.
 *
 * **The defect was a comment guarding an unreachable branch.** The method carried
 * `if ($fresh->isFinalised()) return $fresh…  // idempotent — already finalised`, and the line
 * directly above it called `generate()`, which refuses with *"a finalised statement already
 * exists… revise it instead"* the moment one does. So the branch was dead code and a second
 * `finalise()` **raised** rather than returning.
 *
 * Nothing double-posted, which is why this was LOW rather than a money defect — but a caller
 * reading the method was told the opposite of what it did, and a retry after a timeout is exactly
 * the situation the comment was describing.
 *
 * The fix checks the run BEFORE regenerating. The distinction that matters, and the reason the last
 * test is here: only **this** run short-circuits. The refusal that protects a genuinely different
 * situation — someone trying to finalise over a month that has already been closed and needs a
 * revision instead — has to keep working, and an early return that swallowed it would look
 * identical from the outside.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    ensureAllPropertiesAsset();
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->actor = makeUser('super_admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->actor);

    $this->asset = makeAsset(['code' => 'FID']);
    $this->asset->propertyOwners()->attach(makeUser('owner')->id, ['ownership_percentage' => 100]);
    Filament::setTenant($this->asset);

    // The statement IS the ledger, so the month needs real posted money in it — an empty period
    // finalises to nothing and would let every assertion below pass for the wrong reason.
    $post = app(JournalPostingService::class);
    $r = app(AccountResolver::class);
    $post->post(['entry_date' => '2026-03-10', 'asset_id' => $this->asset->id, 'lines' => [
        ['ledger_account_id' => $r->id('accounts_receivable'), 'debit' => 10_000, 'credit' => 0],
        ['ledger_account_id' => $r->id('rent_revenue'), 'debit' => 0, 'credit' => 10_000],
    ]]);
    $post->post(['entry_date' => '2026-03-12', 'asset_id' => $this->asset->id, 'lines' => [
        ['ledger_account_id' => $r->id('salaries_expense'), 'debit' => 4_000, 'credit' => 0],
        ['ledger_account_id' => $r->id('bank'), 'debit' => 0, 'credit' => 4_000],
    ]]);

    $this->run = app(GenerateOwnerStatementRunService::class)->generate(
        $this->asset,
        AccountingPeriod::forDate(CarbonImmutable::create(2026, 3, 15)),
    );

    // Posted entries raised BY an owner-statement run — the thing a double-finalise would
    // duplicate. Keyed on the MORPH ALIAS, never the FQCN: `source_type` stores
    // `owner_statement_run`, so a class-name comparison silently matches nothing and every count
    // below would read zero and assert happily.
    $this->postedRuns = fn (): int => JournalEntry::where('source_type', (new OwnerStatementRun)->getMorphClass())
        ->where('status', 'posted')
        ->count();
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('finalises a draft run and posts exactly one entry — the control', function () {
    // Without this the idempotency assertions below would pass just as happily against a method
    // that did nothing at all.
    $finalised = app(FinaliseOwnerStatementRunService::class)->finalise($this->run, $this->actor);

    // Driven through the real sweep rather than `LedgerPoster::post()` — the one-registry rule: a
    // test that calls the poster directly proves the journalizer's arithmetic and nothing about
    // whether this source ever reaches it.
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    expect($finalised->isFinalised())->toBeTrue()
        ->and(($this->postedRuns)())->toBe(1)
        ->and((float) $finalised->net_distributable)->toBeGreaterThan(0.0);
});

it('returns the same run on a second call instead of raising', function () {
    $first = app(FinaliseOwnerStatementRunService::class)->finalise($this->run, $this->actor);

    // Before the fix this raised "A finalised statement already exists for this period — revise it
    // instead", from `generate()`, before the branch that says it is idempotent was ever reached.
    $second = app(FinaliseOwnerStatementRunService::class)->finalise($first, $this->actor);

    expect($second->getKey())->toBe($first->getKey())
        ->and($second->isFinalised())->toBeTrue()
        // The finalisation stamps are the FIRST call's. A second pass that re-stamped them would be
        // a no-op with a different name — it would move the posting date of a document already
        // filed.
        ->and($second->finalised_at?->toDateTimeString())->toBe($first->finalised_at?->toDateTimeString())
        ->and($second->posting_date?->toDateString())->toBe($first->posting_date?->toDateString());
});

it('does not post a second entry, and does not pay the owner twice', function () {
    $first = app(FinaliseOwnerStatementRunService::class)->finalise($this->run, $this->actor);
    $net = (float) $first->net_distributable;

    app(FinaliseOwnerStatementRunService::class)->finalise($first, $this->actor);
    app(FinaliseOwnerStatementRunService::class)->finalise($first, $this->actor);

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    expect(($this->postedRuns)())->toBe(1, 'a repeated finalise posted the owner accrual again')
        ->and((float) $first->fresh()->net_distributable)->toBe($net);
});

it('still lets a revision through — idempotency must not freeze the document', function () {
    // The control that says the early return is NARROW, and it is the one that could have gone
    // wrong. `revise()` supersedes the run and then calls `finalise()` on that same row, so the
    // short-circuit sits directly on the correction path: a check written as "has this run ever
    // been finalised" rather than "is it finalised NOW" would return the superseded run untouched
    // and leave the operator with a wrong statement and no way to restate it.
    $finalised = app(FinaliseOwnerStatementRunService::class)->finalise($this->run, $this->actor);

    $revised = app(ReviseOwnerStatementRunService::class)->revise($finalised, $this->actor);

    expect($revised->getKey())->not->toBe($finalised->getKey())
        ->and($revised->isFinalised())->toBeTrue()
        ->and($revised->version)->toBeGreaterThan($finalised->version)
        ->and($finalised->fresh()->status)->toBe(OwnerStatementRun::STATUS_SUPERSEDED);

    // And the revised version is itself idempotent — the fix has to hold on version 2 as well.
    expect(app(FinaliseOwnerStatementRunService::class)->finalise($revised, $this->actor)->getKey())
        ->toBe($revised->getKey());
});
