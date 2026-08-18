<?php

/**
 * Budget-versus-actual on the income statement (RP-06's remaining half).
 *
 * The statement could already compare a period against the one before it or the same one a year
 * earlier. Both answer "is this normal?" — neither answers **"is this what we planned?"**, which is
 * the question a mall's monthly review is built around and the one an owner asks first. There was
 * no operating budget in the schema at all; only a marketing spend pot.
 *
 * The design keeps it small: `BudgetService::asIncomeStatement()` returns the SAME shape a prior
 * period does, so the budget slots into `ComparativeStatementService` where a prior period would
 * have gone and every downstream column works unmodified. These tests check that equivalence, and
 * the import rule that carries the real risk — **a re-import REPLACES the year**, because adding to
 * it would double the plan silently.
 */

use App\Models\BudgetLine;
use App\Models\LedgerAccount;
use App\Services\Accounting\BudgetService;
use App\Services\Reports\ComparativeStatementService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->svc = app(BudgetService::class);

    // Real postable accounts off the seeded chart, found by TYPE rather than hard-coded — a chart
    // renumbering must not silently change what this asserts.
    $this->revenue = LedgerAccount::query()->where('type', 'revenue')->where('is_postable', true)->orderBy('code')->first();
    $this->expense = LedgerAccount::query()->where('type', 'expense')->where('is_postable', true)->orderBy('code')->first();
});

/* ---- the import ---------------------------------------------------------- */

it('spreads an annual figure across twelve months', function () {
    $this->svc->import("{$this->revenue->code}, 120000", 2026, $this->asset->id);

    $lines = BudgetLine::where('asset_id', $this->asset->id)->get();

    expect($lines)->toHaveCount(12)
        ->and(round((float) $lines->sum('amount'), 2))->toBe(120000.0);
});

it('puts the rounding remainder on December so the year sums exactly', function () {
    // 100,000 / 12 is 8,333.333…; twelve rounded months are 99,999.96 and an operator comparing the
    // total against what they typed finds four piastres missing and no explanation.
    $this->svc->import("{$this->revenue->code}, 100000", 2026, $this->asset->id);

    $lines = BudgetLine::where('asset_id', $this->asset->id)->get()->keyBy('month');

    expect(round((float) $lines->sum('amount'), 2))->toBe(100000.0)
        ->and((float) $lines[1]->amount)->toBe(8333.33)
        ->and((float) $lines[12]->amount)->toBe(8333.37);
});

it('takes an exact month when one is given, and mixes both forms in one paste', function () {
    // How a first budget is actually written: annual, with two or three seasonal lines called out.
    $this->svc->import(
        "{$this->revenue->code}, 120000\n{$this->expense->code}, 3, 50000",
        2026,
        $this->asset->id,
    );

    expect(BudgetLine::where('ledger_account_id', $this->revenue->id)->count())->toBe(12)
        ->and(BudgetLine::where('ledger_account_id', $this->expense->id)->count())->toBe(1)
        ->and((float) BudgetLine::where('ledger_account_id', $this->expense->id)->first()->amount)->toBe(50000.0);
});

it('REPLACES the year rather than adding to it', function () {
    // The dangerous one. Importing twice must not double the plan — and unlike an opening balance
    // there is no draft-and-review step to catch it.
    $this->svc->import("{$this->revenue->code}, 120000", 2026, $this->asset->id);
    $this->svc->import("{$this->revenue->code}, 60000", 2026, $this->asset->id);

    $lines = BudgetLine::where('asset_id', $this->asset->id)->get();

    expect($lines)->toHaveCount(12)
        ->and(round((float) $lines->sum('amount'), 2))->toBe(60000.0);
});

it('drops an account taken OUT of a revised budget', function () {
    // The delete's real job, and the case a naive updateOrCreate misses entirely: an account
    // budgeted in January's plan and dropped from March's revision must disappear, not linger at
    // its old figure. Caught by mutation — the "replaces the year" test above passes with the
    // delete removed, because overwriting the same cells is not the same as removing a line.
    $this->svc->import(
        "{$this->revenue->code}, 120000\n{$this->expense->code}, 60000",
        2026,
        $this->asset->id,
    );

    expect(BudgetLine::where('ledger_account_id', $this->expense->id)->count())->toBe(12);

    // The revision no longer budgets that expense at all.
    $this->svc->import("{$this->revenue->code}, 120000", 2026, $this->asset->id);

    expect(BudgetLine::where('ledger_account_id', $this->expense->id)->count())->toBe(0)
        // …and the control: the account that IS still budgeted survives untouched.
        ->and(BudgetLine::where('ledger_account_id', $this->revenue->id)->count())->toBe(12);
});

it('leaves another YEAR alone when one year is re-imported', function () {
    // The control for the replace: "replace the year" must not mean "replace everything".
    $this->svc->import("{$this->revenue->code}, 120000", 2025, $this->asset->id);
    $this->svc->import("{$this->revenue->code}, 60000", 2026, $this->asset->id);

    expect(round((float) BudgetLine::where('fiscal_year', 2025)->sum('amount'), 2))->toBe(120000.0)
        ->and(round((float) BudgetLine::where('fiscal_year', 2026)->sum('amount'), 2))->toBe(60000.0);
});

it('leaves another PROPERTY alone', function () {
    $other = makeAsset();
    $this->svc->import("{$this->revenue->code}, 120000", 2026, $other->id);
    $this->svc->import("{$this->revenue->code}, 60000", 2026, $this->asset->id);

    expect(round((float) BudgetLine::where('asset_id', $other->id)->sum('amount'), 2))->toBe(120000.0);
});

/* ---- refusals ------------------------------------------------------------ */

it('refuses an unknown account', function () {
    expect(fn () => $this->svc->import('99999999, 1000', 2026, $this->asset->id))
        ->toThrow(DomainException::class);

    expect(BudgetLine::count())->toBe(0);
});

it('refuses a balance-sheet account', function () {
    // A budget is a P&L plan. Budgeting an asset account would write a line the income statement
    // can never show, which reads as the import having failed.
    $bs = LedgerAccount::query()->whereIn('type', ['asset', 'liability'])->where('is_postable', true)->first();

    expect(fn () => $this->svc->import("{$bs->code}, 1000", 2026, $this->asset->id))
        ->toThrow(DomainException::class);
});

it('refuses an impossible month', function () {
    expect(fn () => $this->svc->import("{$this->revenue->code}, 13, 1000", 2026, $this->asset->id))
        ->toThrow(DomainException::class);
});

it('refuses an empty paste', function () {
    expect(fn () => $this->svc->import('   ', 2026, $this->asset->id))->toThrow(DomainException::class);
});

/* ---- it speaks the income statement's own shape -------------------------- */

it('returns the same shape a prior period does, which is what makes it plug in', function () {
    $this->svc->import("{$this->revenue->code}, 120000\n{$this->expense->code}, 60000", 2026, $this->asset->id);

    $shape = $this->svc->asIncomeStatement(
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-12-31'),
        [$this->asset->id],
    );

    expect($shape)->toHaveKeys(['revenue', 'expense', 'total_revenue', 'total_expense', 'net_profit'])
        ->and($shape['total_revenue'])->toBe(120000.0)
        ->and($shape['total_expense'])->toBe(60000.0)
        ->and($shape['net_profit'])->toBe(60000.0)
        ->and($shape['revenue']->first())->toHaveKeys(['account_id', 'code', 'name_en', 'name_ar', 'amount']);
});

it('counts only the months inside the range', function () {
    // A quarter must show a quarter of the plan, not the year.
    $this->svc->import("{$this->revenue->code}, 120000", 2026, $this->asset->id);

    $q1 = $this->svc->asIncomeStatement(
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-03-31'),
        [$this->asset->id],
    );

    expect($q1['total_revenue'])->toBe(30000.0);
});

it('is offered as a comparison basis on the income statement', function () {
    // The wiring, not just the data: BUDGET has to be a basis the page will accept, or the service
    // is another correct mechanism with no consumer — the exact shape RP-06 was written about.
    expect(ComparativeStatementService::BASES)->toContain(ComparativeStatementService::BUDGET);

    $this->svc->import("{$this->revenue->code}, 120000", 2026, $this->asset->id);

    $report = app(ComparativeStatementService::class)->incomeStatement(
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-12-31'),
        [$this->asset->id],
        ComparativeStatementService::BUDGET,
    );

    expect($report['basis'])->toBe(ComparativeStatementService::BUDGET)
        // The comparison dates do NOT move for a budget — it is this span's plan, not an earlier one.
        ->and($report['prior_from'])->toBe('2026-01-01')
        ->and($report['totals']['revenue']['prior'])->toBe(120000.0);
});

it('says whether a budget exists at all, so "no budget" is not read as zero', function () {
    expect($this->svc->existsFor(2026, [$this->asset->id]))->toBeFalse();

    $this->svc->import("{$this->revenue->code}, 1000", 2026, $this->asset->id);

    expect($this->svc->existsFor(2026, [$this->asset->id]))->toBeTrue();
});
