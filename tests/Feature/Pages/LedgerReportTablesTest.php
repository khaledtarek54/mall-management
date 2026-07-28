<?php

/**
 * The ledger report pages, after they stopped being hand-written Blade tables
 * and became native Filament tables.
 *
 * These pages are not CRUD lists — they render an AGGREGATE the report service
 * computes, fed to Filament through `records()` rather than `query()`. So the
 * thing worth pinning is not "the page loads" but that the table shows the same
 * numbers the service (and therefore the PDF and CSV of the same report) says,
 * and that the footer totals are the real tie-out figures.
 *
 * The GL only has anything in it after the real posting sweep runs, so these
 * drive a genuine invoice → sync → report path rather than writing journal rows
 * by hand.
 */

use App\Filament\Admin\Pages\ArAging;
use App\Filament\Admin\Pages\BalanceSheet;
use App\Filament\Admin\Pages\CashFlow;
use App\Filament\Admin\Pages\GeneralLedger;
use App\Filament\Admin\Pages\IncomeStatement;
use App\Filament\Admin\Pages\TrialBalance;
use App\Models\Asset;
use App\Models\LedgerAccount;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerReportService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    ensureAllPropertiesAsset();
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
});

/** A VAT-exempt invoice that balances on its own, posted to the GL by the real sweep. */
function ledgerReportFixture(): Asset
{
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset));

    $invoice = makeInvoice($lease, [
        'issue_date' => now()->toDateString(),
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000, 'balance' => 10000,
    ]);
    $invoice->items()->create([
        'type' => 'base_rent', 'description' => 'Rent', 'amount' => 10000,
        'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000,
    ]);

    test()->artisan('accounting:sync-ledger --all')->assertSuccessful();

    return $asset;
}

it('renders the trial balance as a table whose rows and totals match the report service', function () {
    $asset = ledgerReportFixture();
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    asTenant($asset, function () {
        $component = Livewire::test(TrialBalance::class)->assertOk();

        $records = collect($component->instance()->getTableRecords());
        expect($records)->not->toBeEmpty();

        // The table must agree with the service, account for account — this is
        // the same array the PDF and the CSV of this report are built from.
        $report = app(LedgerReportService::class)->trialBalance(
            null,
            now()->startOfYear(),
            now()->endOfYear(),
        );

        expect($records)->toHaveCount($report['rows']->count());

        $tableDebit = round($records->sum('debit_balance'), 2);
        $tableCredit = round($records->sum('credit_balance'), 2);

        expect($tableDebit)->toBe($report['total_debit'])
            ->and($tableCredit)->toBe($report['total_credit']);

        // …and the statement foots. A trial balance that renders but does not
        // balance is the one outcome this page exists to make impossible to miss.
        expect($tableDebit)->toBe($tableCredit);
    });
});

it('reports the balance check in the trial balance subheading', function () {
    $asset = ledgerReportFixture();
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    asTenant($asset, function () {
        $subheading = Livewire::test(TrialBalance::class)->instance()->getSubheading();

        expect($subheading)->toContain(__('admin.reports.balanced'));
    });
});

it('narrows the trial balance to the selected fiscal year', function () {
    $asset = ledgerReportFixture();
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    asTenant($asset, function () {
        // A year with no postings must come back empty rather than silently
        // showing another year's ledger — the year picker is bound to the same
        // $year property the PDF/CSV exports read.
        $component = Livewire::test(TrialBalance::class)
            ->set('year', (int) now()->subYears(3)->year);

        expect(collect($component->instance()->getTableRecords()))->toBeEmpty();
    });
});

it('links each AR-aging row through to its invoice', function () {
    // Regression: the drill-through was briefly built off $invoice->asset_id —
    // a column invoices do not have — so recordUrl returned null and the link
    // silently disappeared. An invoice's property comes via lease.unit.
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset));
    $invoice = makeInvoice($lease, [
        'status' => 'issued',
        'issue_date' => now()->subDays(15),
        'due_date' => now()->subDays(10),
        'balance' => 5000, 'paid_amount' => 0, 'total' => 5000,
    ]);

    $this->actingAs(makeUser('super_admin', [$asset->id]));

    asTenant($asset, function () use ($asset, $invoice) {
        $component = Livewire::test(ArAging::class)->set('bucket', 'd_1_30');
        $url = $component->instance()->getTable()->getRecordUrl($invoice);

        expect($url)->not->toBeNull()
            ->and($url)->toContain((string) $invoice->id)
            // …and lands in the invoice's OWN property.
            ->and($url)->toContain((string) $asset->code);
    });
});

it('keeps the trial balance behind the general_ledger.view permission', function () {
    expect(TrialBalance::canAccess())->toBeFalse();

    $this->actingAs(makeUser('accounting'));
    expect(TrialBalance::canAccess())->toBeTrue();

    $this->actingAs(makeUser('leasing'));
    expect(TrialBalance::canAccess())->toBeFalse();
});

/* ---- The three financial statements -------------------------------------- */

it('renders the balance sheet with its sections and a footing total line', function () {
    $asset = ledgerReportFixture();
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    asTenant($asset, function () {
        $component = Livewire::test(BalanceSheet::class)->assertOk();
        $records = collect($component->instance()->getTableRecords());

        expect($records)->not->toBeEmpty();

        // Both sides are present as sections…
        $sections = $records->pluck('section')->unique();
        expect($sections)->toContain(__('admin.reports.assets'))
            ->and($sections)->toContain(__('admin.reports.liabilities_equity'));

        // …and each closes with a total row rather than a summarizer, because a
        // records()-backed table has no query for a per-group summarizer to sum.
        $totals = $records->where('is_total', true);
        expect($totals)->toHaveCount(2);

        // The sheet balances: assets total == liabilities+equity+net income total.
        $report = app(LedgerReportService::class)->balanceSheet(null, now()->endOfYear());
        expect($report['balanced'])->toBeTrue();

        expect(round($totals->firstWhere('account', __('admin.reports.total_assets'))['amount'], 2))
            ->toBe($report['total_assets']);
    });
});

it('renders the income statement with revenue, expenses and net profit', function () {
    $asset = ledgerReportFixture();
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    asTenant($asset, function () {
        $records = collect(Livewire::test(IncomeStatement::class)->assertOk()->instance()->getTableRecords());

        $sections = $records->pluck('section')->unique();
        expect($sections)->toContain(__('admin.reports.revenue'))
            ->and($sections)->toContain(__('admin.reports.expenses'))
            ->and($sections)->toContain(__('admin.reports.net_profit'));

        $report = app(LedgerReportService::class)->incomeStatement(null, now()->startOfYear(), now()->endOfYear());

        $netRow = $records->where('section', __('admin.reports.net_profit'))->firstWhere('is_total', true);
        expect(round($netRow['amount'], 2))->toBe($report['net_profit']);
    });
});

it('renders the cash-flow statement and reports whether it reconciles', function () {
    $asset = ledgerReportFixture();
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    asTenant($asset, function () {
        $component = Livewire::test(CashFlow::class)->assertOk();
        $records = collect($component->instance()->getTableRecords());

        $sections = $records->pluck('section')->unique();
        expect($sections)->toContain(__('admin.reports.operating_activities'));

        // The integrity check must be stated on the page, not buried: a
        // cash-flow statement that doesn't tie to the cash accounts is wrong.
        $report = app(LedgerReportService::class)->cashFlow(null, now()->startOfYear(), now()->endOfYear());
        expect($component->instance()->getSubheading())->toBe(
            $report['reconciled']
                ? __('admin.reports.cash_flow_reconciled')
                : __('admin.reports.cash_flow_unreconciled')
        );
    });
});

/* ---- General ledger ------------------------------------------------------ */

it('shows nothing until an account is chosen, then the account statement', function () {
    $asset = ledgerReportFixture();
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    asTenant($asset, function () {
        // No account picked → empty, with the "choose an account" prompt.
        $component = Livewire::test(GeneralLedger::class)->assertOk();
        expect(collect($component->instance()->getTableRecords()))->toBeEmpty();

        // Pick the AR control account, which the invoice above debited.
        $account = LedgerAccount::query()
            ->whereIn('id', DB::table('journal_lines')->select('ledger_account_id'))
            ->firstOrFail();

        $component = Livewire::test(GeneralLedger::class)->set('accountId', $account->id);
        $records = collect($component->instance()->getTableRecords());

        // Opening balance is a real first row, so the running balance on line 1
        // follows from something visible.
        expect($records->first()['is_opening'])->toBeTrue();
        expect($records)->toHaveCount(
            app(LedgerReportService::class)->accountLedger(
                $account, null, now()->startOfYear(), now()->endOfYear()
            )['lines']->count() + 1
        );

        // The closing balance is surfaced on the page itself.
        expect($component->instance()->getSubheading())
            ->toContain(__('admin.reports.closing_balance'));
    });
});
