<?php

use App\Filament\Admin\Pages\IncomeStatement;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\Accounting\LedgerReportService;
use App\Services\Reports\ComparativeStatementService;
use App\Services\Reports\ReportCsvExporter;
use App\Support\IncomeStatementLayout;
use App\Support\StatementSection;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A property income statement stops halfway and states NET OPERATING INCOME.
 *
 * The P&L ran revenue − expenses = net profit with nothing in between, so the cost of cleaning the
 * mall sat in the same total as the interest on the loan secured against it and the depreciation of
 * its lifts. That is a general-ledger income statement. A PROPERTY one splits at NOI, because a mall
 * is worth roughly its NOI divided by a cap rate — two malls with identical NOI are worth the same
 * whether one is mortgaged and the other is not — and Yardi, MRI and Entrata all print that
 * subtotal. It is the first number an owner, a valuer or a lender looks for.
 *
 * The classification lives on the ACCOUNT ({@see StatementSection}), never on its code, for the same
 * reason the cash-flow statement stopped reading prefixes: the operator's real chart is still
 * pending. Here the argument is sharper, because ONE prefix genuinely holds both answers — `42101`
 * Miscellaneous Income is property income and belongs in NOI, `42102` Gain on Disposal does not.
 */
function noiAccount(string $code, string $type, ?string $section): LedgerAccount
{
    return LedgerAccount::create([
        'code' => $code,
        'name_en' => 'A '.$code,
        'name_ar' => 'ح '.$code,
        'type' => $type,
        'statement_section' => $section,
        'is_postable' => true,
        'is_active' => true,
    ]);
}

/** A posted entry moving `$amount` from `$debit` to `$credit`. */
function noiEntry(LedgerAccount $debit, LedgerAccount $credit, float $amount, ?int $assetId = null, string $on = '2026-03-10'): void
{
    $entry = JournalEntry::create([
        'number' => 'JE-'.uniqid(), 'entry_date' => $on, 'status' => 'draft', 'is_manual' => true,
        // Filed against a property when one is given: every statement scopes with
        // `whereIn('je.asset_id', ...)` and `whereIn` never matches NULL (EG-27), so a page test
        // over unfiled entries would render an empty statement and pass for the wrong reason.
        'asset_id' => $assetId,
    ]);

    $entry->lines()->create(['ledger_account_id' => $debit->id, 'debit' => $amount, 'credit' => 0]);
    $entry->lines()->create(['ledger_account_id' => $credit->id, 'debit' => 0, 'credit' => $amount]);
    $entry->update(['status' => 'posted']);
}

/** @return array<string, mixed> */
function noiStatement(): array
{
    return app(LedgerReportService::class)->incomeStatement(
        null, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')
    );
}

/**
 * A mall that trades AND carries a mortgage: 500,000 of rent, 200,000 of cleaning, then 90,000 of
 * interest and 60,000 of depreciation below the line.
 */
function noiFixture(?int $assetId = null): void
{
    $cash = noiAccount('1000', 'asset', null);
    $accumulated = noiAccount('1220', 'asset', null);
    $loan = noiAccount('2900', 'liability', null);

    noiEntry($cash, noiAccount('4100', 'revenue', StatementSection::OPERATING), 500_000, $assetId);
    noiEntry(noiAccount('5100', 'expense', StatementSection::OPERATING), $cash, 200_000, $assetId);
    noiEntry(noiAccount('5210', 'expense', StatementSection::NON_OPERATING), $loan, 90_000, $assetId);
    noiEntry(noiAccount('5110', 'expense', StatementSection::NON_OPERATING), $accumulated, 60_000, $assetId);
}

it('states net operating income above the financing and accounting layers', function () {
    noiFixture();

    $r = noiStatement();

    // The mall's own trading result: 500,000 − 200,000. Interest and depreciation are real costs and
    // they are NOT what the property earns, which is the whole distinction.
    expect($r['total_operating_revenue'])->toBe(500_000.0)
        ->and($r['total_operating_expense'])->toBe(200_000.0)
        ->and($r['net_operating_income'])->toBe(300_000.0)
        ->and($r['total_other_expense'])->toBe(150_000.0)
        ->and($r['net_profit'])->toBe(150_000.0);
});

it('never lets the split move the bottom line', function () {
    // The invariant that makes this safe to deploy. `net_profit` is computed off the FULL revenue and
    // expense sets and never travels through the buckets, so it stays right even when every account
    // is misclassified — only NOI moves. Five readers consume this array, one of which
    // (`GenerateOwnerStatementRunService`) turns it into money an owner is actually paid.
    noiFixture();

    $r = noiStatement();

    expect(round($r['net_operating_income'] + $r['total_other_revenue'] - $r['total_other_expense'], 2))
        ->toBe($r['net_profit'])
        ->and(round($r['total_operating_revenue'] + $r['total_other_revenue'], 2))->toBe($r['total_revenue'])
        ->and(round($r['total_operating_expense'] + $r['total_other_expense'], 2))->toBe($r['total_expense']);
});

it('lays the statement out exactly as before when nothing sits below the line', function () {
    // The deploy-safety case, and it is not a courtesy: with nothing classified below it, NOI EQUALS
    // net profit, and printing one figure twice under two names reads as an error rather than as
    // information. So an install that has never opened the chart screen sees what it always saw.
    $cash = noiAccount('1000', 'asset', null);
    noiEntry($cash, noiAccount('4100', 'revenue', StatementSection::OPERATING), 500_000);
    noiEntry(noiAccount('5100', 'expense', StatementSection::OPERATING), $cash, 200_000);

    $r = noiStatement();

    expect($r['has_below_the_line'])->toBeFalse();

    expect(collect(IncomeStatementLayout::sections($r))->pluck('label')->all())->toBe([
        __('admin.reports.revenue'),
        __('admin.reports.expenses'),
        __('admin.reports.net_profit'),
    ]);
});

it('grows the NOI line the moment the line means something', function () {
    noiFixture();

    // Other INCOME is absent from this fixture and must not print as a heading over a 0.00 — the
    // same rule `StatementGroups::worthShowing()` applies one level down.
    expect(collect(IncomeStatementLayout::sections(noiStatement()))->pluck('label')->all())->toBe([
        __('admin.reports.operating_revenue'),
        __('admin.reports.operating_expenses'),
        __('admin.reports.net_operating_income'),
        __('admin.reports.other_expenses'),
        __('admin.reports.net_profit'),
    ]);
});

it('exports the statement it displays', function () {
    // One statement, three renderers. An export laid out differently from the screen it was taken
    // from is the copy that gets emailed, filed and argued over — and this project has shipped that
    // drift once already (EG-36's narrative).
    noiFixture();

    $csv = app(ReportCsvExporter::class)->incomeStatement(noiStatement());
    $sections = collect($csv['rows'])->pluck(0)->unique()->values()->all();

    expect($sections)->toContain(__('admin.reports.operating_revenue'))
        ->and($sections)->toContain(__('admin.reports.other_expenses'))
        // The mid-statement NOI row names itself rather than printing as a bare "Subtotal".
        ->and(collect($csv['rows'])->pluck(2)->all())->toContain(__('admin.reports.net_operating_income'));
});

it('tells 42101 from 42102, which no prefix rule can', function () {
    // The row that proves the classification has to live on the account. Both sit under `42 Other
    // Income`; one is ordinary property income and belongs inside NOI, the other is a one-off.
    $this->seed(ChartOfAccountsSeeder::class);

    expect(LedgerAccount::where('code', '42101001')->firstOrFail()->statement_section)
        ->toBe(StatementSection::OPERATING)
        ->and(LedgerAccount::where('code', '42102001')->firstOrFail()->statement_section)
        ->toBe(StatementSection::NON_OPERATING);

    // Depreciation sits INSIDE `51 Operating Expenses` and is still excluded from NOI by definition.
    expect(LedgerAccount::where('code', '51107001')->firstOrFail()->statement_section)
        ->toBe(StatementSection::NON_OPERATING)
        // Interest is the owner's borrowing cost, not the asset's.
        ->and(LedgerAccount::where('code', '52104001')->firstOrFail()->statement_section)
        ->toBe(StatementSection::NON_OPERATING)
        // Credit loss is a cost of letting shops to retailers, so it reduces NOI rather than sitting
        // under it. Same for the contra-revenue that reduces effective gross income.
        ->and(LedgerAccount::where('code', '51109001')->firstOrFail()->statement_section)
        ->toBe(StatementSection::OPERATING)
        ->and(LedgerAccount::where('code', '43101001')->firstOrFail()->statement_section)
        ->toBe(StatementSection::OPERATING);
});

it('never puts a balance-sheet account on the income statement', function () {
    // It has no result to place. The form hides the field for them and the seeder leaves it null —
    // the exact mirror of the rule `cash_flow_section` states in the other direction.
    $this->seed(ChartOfAccountsSeeder::class);

    $offenders = LedgerAccount::whereNotIn('type', ['revenue', 'expense'])
        ->whereNotNull('statement_section')
        ->pluck('code')
        ->all();

    expect(implode(', ', $offenders))->toBe('');
});

it('floors an unclassified account into NOI, which understates it and leaves profit right', function () {
    // Erring toward operating carries a below-the-line cost above the line: NOI is understated and
    // the bottom line is untouched. Erring the other way would OVERSTATE the number a valuation is
    // built on, which is the one direction that costs money.
    expect(StatementSection::for(null, 'revenue'))->toBe(StatementSection::OPERATING)
        ->and(StatementSection::for(null, 'expense'))->toBe(StatementSection::OPERATING)
        // A value nobody registered is not trusted.
        ->and(StatementSection::for('non_operatng', 'expense'))->toBe(StatementSection::OPERATING);
});

it('refuses a mistyped section at the model layer', function () {
    // A typo does not error on its own — it would silently floor to operating — so the value set is
    // what makes it loud.
    expect(fn () => noiAccount('5900', 'expense', 'non_operatng'))
        ->toThrow(DomainException::class);
});

it('renders the NOI line on the real page', function () {
    // Building the report in a test proves the arithmetic; only driving the page proves an operator
    // can read it — and this is the layer the whole change exists for.
    $this->seed(RolesPermissionsSeeder::class);
    $asset = makeAsset();
    noiFixture($asset->id);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($asset);

    Livewire::test(IncomeStatement::class)
        ->set('year', 2026)
        ->set('period', '2026-03')
        ->assertOk()
        // Paired with a control: an assertion that only looks for the new line would pass just as
        // happily on a statement rendering nothing at all.
        ->assertSee(__('admin.reports.net_operating_income'))
        ->assertSee(__('admin.reports.operating_revenue'))
        ->assertSee(__('admin.reports.net_profit'));

    Filament::setTenant(null, isQuiet: true);
});

it('keeps the same shape when a comparison is asked for', function () {
    // One picker must change how many COLUMNS a statement has, never what shape it IS. The two
    // readings are built by different code — the plain one from the report's own collections, the
    // comparative one from a UNION of two periods — so nothing but a test stops them drifting into
    // two layouts of one statement that nobody can reconcile.
    $this->seed(RolesPermissionsSeeder::class);
    $asset = makeAsset();
    noiFixture($asset->id);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($asset);

    $page = Livewire::test(IncomeStatement::class)
        ->set('year', 2026)
        ->set('period', '2026-03')
        ->set('comparison', ComparativeStatementService::PRIOR_PERIOD)
        ->assertOk();

    $records = $page->instance()->comparativeRecords($page->instance()->comparative());
    $sections = collect($records)->pluck('section')->unique()->values()->all();

    expect($sections)->toBe([
        __('admin.reports.operating_revenue'),
        __('admin.reports.operating_expenses'),
        __('admin.reports.net_operating_income'),
        __('admin.reports.other_expenses'),
        __('admin.reports.net_profit'),
    ]);

    // And the comparison is a real one: February had nothing, so every current figure is the change.
    $noi = collect($records)->firstWhere('section', __('admin.reports.net_operating_income'));

    expect($noi['amount'])->toBe(300_000.0)
        ->and($noi['prior'])->toBe(0.0)
        ->and($noi['change'])->toBe(300_000.0);

    Filament::setTenant(null, isQuiet: true);
});

it('does not drop a below-the-line account that stopped running', function () {
    // A comparative row set is the UNION of two periods. An interest charge that ran last month and
    // stopped has no row in this one, and dropping it would hide exactly the change a comparison
    // exists to show — so `has_below_the_line` asks EITHER side, never both.
    $cash = noiAccount('1000', 'asset', null);
    $loan = noiAccount('2900', 'liability', null);
    $interest = noiAccount('5210', 'expense', StatementSection::NON_OPERATING);

    noiEntry($cash, noiAccount('4100', 'revenue', StatementSection::OPERATING), 500_000);
    // Last month only.
    noiEntry($interest, $loan, 90_000, null, '2026-02-10');

    $comparative = app(ComparativeStatementService::class)->incomeStatement(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
        null,
        ComparativeStatementService::PRIOR_PERIOD,
    );

    expect($comparative['has_below_the_line'])->toBeTrue();

    $stopped = collect($comparative['rows'])->firstWhere('code', '5210');

    expect($stopped)->not->toBeNull()
        ->and($stopped['current'])->toBe(0.0)
        ->and($stopped['prior'])->toBe(90_000.0)
        ->and($stopped['statement_section'])->toBe(StatementSection::NON_OPERATING);
});
