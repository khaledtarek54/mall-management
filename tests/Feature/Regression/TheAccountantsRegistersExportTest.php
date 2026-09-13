<?php

use App\Filament\Admin\Resources\DepositTransactions\DepositTransactionResource;
use App\Filament\Admin\Resources\DepositTransactions\Pages\ListDepositTransactions;
use App\Filament\Admin\Resources\Expenses\ExpenseResource;
use App\Filament\Admin\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Admin\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Admin\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Filament\Admin\Resources\LedgerAccounts\LedgerAccountResource;
use App\Filament\Admin\Resources\LedgerAccounts\Pages\ListLedgerAccounts;
use App\Filament\Admin\Resources\PostDatedCheques\Pages\ListPostDatedCheques;
use App\Filament\Admin\Resources\PostDatedCheques\PostDatedChequeResource;
use App\Filament\Admin\Resources\VendorBills\Pages\ListVendorBills;
use App\Filament\Admin\Resources\VendorBills\VendorBillResource;
use App\Filament\Exports\ExpenseExporter;
use App\Filament\Exports\JournalEntryExporter;
use App\Filament\Exports\LedgerAccountExporter;
use App\Filament\Imports\LedgerAccountImporter;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\MarketingBudget;
use App\Models\MarketingSpend;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Support\Exports;
use App\Support\ValueSets;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ExportCells;

/**
 * The accountant's registers export (the reports audit, 2026-09-12 — RP-10 b).
 *
 * Receipts, invoices and credit notes had an Export button; the journal, the chart of accounts,
 * supplier bills, expenses, security deposits and post-dated cheques did not — the six registers an
 * accountant reconciles from and an auditor asks for first. Every ledger system hands these to a
 * spreadsheet. Six exporters under the one `Exports` doctrine: whoever may READ the list may take it
 * away, because the export runs the list's own scoped, filtered query and can never show a row the
 * screen would not.
 *
 * The generic gates already prove every exported cell is a scalar and that the chart's file carries
 * every column its importer REQUIRES under the importer's own label in both languages. What is
 * pinned here is the door itself — offered where the list is readable, absent where it is not —
 * and the two cells that are not bare columns.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->asset = makeAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    // Signed in BEFORE the tenant is set: Filament's TenantSet event names the user.
    $this->signIn = function (string $role): void {
        test()->actingAs(makeUser($role, [$this->asset->id]));
        Filament::setTenant($this->asset);
    };
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** register => [list page, resource] */
function accountantRegisters(): array
{
    return [
        'journal entries' => [ListJournalEntries::class, JournalEntryResource::class],
        'chart of accounts' => [ListLedgerAccounts::class, LedgerAccountResource::class],
        'vendor bills' => [ListVendorBills::class, VendorBillResource::class],
        'expenses' => [ListExpenses::class, ExpenseResource::class],
        'security deposits' => [ListDepositTransactions::class, DepositTransactionResource::class],
        'post-dated cheques' => [ListPostDatedCheques::class, PostDatedChequeResource::class],
    ];
}

it('offers an export on every one of the six registers to a role that can read it', function () {
    ($this->signIn)('accounting');

    foreach (accountantRegisters() as $register => [$page, $resource]) {
        expect(Exports::allowed($resource))->toBeTrue("{$register}: accounting may read the list, so it may export it");

        $table = Livewire::test($page)
            ->assertOk()
            ->assertTableActionVisible('export')
            ->instance()
            ->getTable();

        // By CLASS, not by name: the header action and the bulk action are both called `export`,
        // and `assertTableBulkActionVisible('export')` resolved the header one — green with the
        // bulk action deleted (found by mutation).
        $bulk = collect($table->getFlatBulkActions())->first(fn ($action): bool => $action instanceof ExportBulkAction);

        expect($bulk)->not->toBeNull("{$register}: no bulk export in the toolbar")
            ->and($bulk->isVisible())->toBeTrue("{$register}: the bulk export is hidden from accounting");
    }
});

it('offers none of them to a role that cannot read the list', function () {
    // `marketing` holds none of the six `.view` rights — the export follows the read gate, so the
    // same role gets neither the page nor the file (the control is the test above).
    ($this->signIn)('marketing');

    foreach (accountantRegisters() as $register => [$page, $resource]) {
        expect($resource::canViewAny())->toBeFalse("{$register}: the premise — marketing cannot read it")
            ->and(Exports::allowed($resource))->toBeFalse("{$register}: so it may not export it either");
    }
});

it('exports the chart in the shape its own importer accepts', function () {
    // The chart was importable and not exportable. The file must round-trip: every column the
    // importer REQUIRES is exported under the importer's own label (the generic gate proves that in
    // both languages), and a classification cell carries its CODE — the value the importer's rule
    // accepts — never its translated label.
    ($this->signIn)('accounting');
    $accounting = auth()->user();

    $required = collect(LedgerAccountImporter::getColumns())
        ->filter(fn ($column): bool => $column->isMappingRequired())
        ->map(fn ($column): string => (string) $column->getLabel())
        ->values()
        ->all();

    $headers = collect(LedgerAccountExporter::getColumns())->map(fn ($column): string => (string) $column->getLabel())->all();

    expect($required)->not->toBeEmpty()
        ->and(array_diff($required, $headers))->toBe([]);

    $row = ExportCells::row(LedgerAccountExporter::class, LedgerAccount::where('code', '11101001')->firstOrFail());

    expect($row['code'])->toBe('11101001')
        ->and($row['type'])->toBe('asset')
        ->and(ValueSets::allowed('ledger_accounts', 'type'))->toContain($row['type'])
        // The parent is informational (the importer derives it from the code), carried as a CODE —
        // the shipped chart's immediate parent of the cashier leaf, not the branch.
        ->and($row['parent.code'])->toBe(LedgerAccount::where('code', '11101001')->firstOrFail()->parent->code)
        ->and($row['parent.code'])->toBe('11101')
        // The flags travel as `1`/`0`. Filament renders FALSE as an EMPTY cell, and the importer read
        // a blank as null into a NOT NULL column — so a branch (not postable) and an inactive leaf
        // could not be re-imported: 101 of the shipped chart's 169 rows. Found by review.
        ->and($row['is_postable'])->toBe('1')
        ->and(ExportCells::row(LedgerAccountExporter::class, LedgerAccount::where('code', '11101')->firstOrFail())['is_postable'])->toBe('0');

    // …and it really does round-trip: a BRANCH and an INACTIVE leaf, exported, fed back through the
    // importer under the exporter's own headers, exactly as ImportCsv feeds a line.
    LedgerAccount::where('code', '11101001')->update(['is_active' => false]);

    $labels = collect(LedgerAccountExporter::getColumns())->mapWithKeys(fn ($c): array => [$c->getName() => (string) $c->getLabel()])->all();
    // The CSV lines as ImportCsv hands them over: keyed by the export HEADER.
    $lines = collect(['11101', '11101001'])->map(fn (string $code): array => collect(ExportCells::row(LedgerAccountExporter::class, LedgerAccount::where('code', $code)->firstOrFail()))
        ->mapWithKeys(fn ($v, string $name): array => [$labels[$name] => $v])->all());

    // The books MOVE before the file comes back, so a re-import that wrote nothing would be caught:
    // the branch is flagged postable and the leaf active, and the file says otherwise. (The first
    // cut of this test mapped the importer to the exporter's NAMES where Filament wants the CSV
    // HEADERS — every column was skipped, and the assertions held because nothing had changed.)
    LedgerAccount::where('code', '11101')->update(['is_postable' => true]);
    LedgerAccount::where('code', '11101001')->update(['is_active' => true]);

    $import = Import::create(['completed_at' => null, 'file_name' => 'chart.csv', 'file_path' => 'chart.csv', 'importer' => LedgerAccountImporter::class, 'processed_rows' => 0, 'total_rows' => 2, 'successful_rows' => 0, 'user_id' => $accounting->id]);
    // Filament's column map is importer column => CSV HEADER; the importer maps by LABEL, so the
    // header is the importer's own label — which the export wrote, in this locale.
    $columnMap = collect(LedgerAccountImporter::getColumns())->mapWithKeys(fn ($c): array => [$c->getName() => (string) $c->getLabel()])->all();

    expect(array_diff($columnMap, $labels))->toBe([]);

    foreach ($lines as $line) {
        (new LedgerAccountImporter($import, $columnMap, []))($line);
    }

    expect(LedgerAccount::where('code', '11101')->sole()->is_postable)->toBeFalse()
        ->and(LedgerAccount::where('code', '11101001')->sole()->is_active)->toBeFalse()
        ->and(LedgerAccount::whereIn('code', ['11101', '11101001'])->count())->toBe(2);

    // A blank flag cell — what every spreadsheet hands back for "no" — KEEPS the row as it is rather
    // than failing it on a message-less NOT NULL error: the branch is flagged postable again, the
    // file says nothing, and it stays postable.
    LedgerAccount::where('code', '11101')->update(['is_postable' => true]);
    $blank = collect($lines[0])->map(fn ($v, string $header): mixed => in_array($header, [$labels['is_postable'], $labels['is_active']], true) ? '' : $v)->all();

    (new LedgerAccountImporter($import, $columnMap, []))($blank);

    expect(LedgerAccount::where('code', '11101')->sole()->is_postable)->toBeTrue();
});

it('derives an expense\'s cost nature and names a portfolio-level entry\'s property, as the lists do', function () {
    ($this->signIn)('accounting');

    $expense = Expense::create(['asset_id' => $this->asset->id, 'category' => 'maintenance', 'paid_from' => 'cash', 'expense_date' => '2026-06-01', 'amount' => 100, 'vat_amount' => 0, 'total' => 100, 'status' => 'recorded']);

    // `cost_nature` is not a column — a bare export path rendered a BLANK under a "Cost nature"
    // header on every row, and a blank is a scalar no cell gate refuses.
    expect(ExportCells::row(ExpenseExporter::class, $expense->fresh())['cost_nature'])->toBe($expense->costNature())
        ->and($expense->costNature())->not->toBe('');

    // The year-end close posts against no property; the list says so in words, and so does the file.
    $entry = JournalEntry::create(['number' => 'JE-CLOSE', 'entry_date' => '2026-12-31', 'status' => 'posted', 'is_manual' => true, 'asset_id' => null]);

    expect(ExportCells::row(JournalEntryExporter::class, $entry->fresh())['asset.name'])->toBe(__('admin.fields.property_consolidated'));
});

it('exports a journal entry\'s narrative and source as the register shows them', function () {
    ($this->signIn)('accounting');

    // A source with no number of its own — the register names it by kind and id; so does the file.
    $budget = MarketingBudget::forPeriod($this->asset->id, 2026);
    $spend = MarketingSpend::create(['marketing_budget_id' => $budget->id, 'description' => 'Spring campaign', 'amount' => 12000, 'spent_on' => '2026-06-01', 'paid_from' => 'bank']);
    app(LedgerPoster::class)->sync($spend->fresh());

    $entry = JournalEntry::where('source_type', 'marketing_spend')->where('source_id', $spend->id)->firstOrFail();
    $row = ExportCells::row(JournalEntryExporter::class, $entry);

    expect($row['source'])->toBe(__('admin.activity.subjects.marketing_spend').' #'.$spend->id)
        ->and($row['description'])->toBe($entry->displayDescription())
        // The model's own figure when the export query carried no `withSum`: 12,000 on each side.
        // The exporter hands back the formatted CELL, a string.
        ->and((float) $row['total_debit'])->toBe(12000.0)
        ->and($row['number'])->toBe($entry->number);

    // …and the loaded subselect is read where the list's own query put it, not re-summed per row —
    // the first cut ran `lines()->sum()` for every entry beside a subselect that had already done it.
    $loaded = JournalEntry::withSum('lines as total_debit', 'debit')->findOrFail($entry->id);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $row = ExportCells::row(JournalEntryExporter::class, $loaded);
    $log = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect((float) $row['total_debit'])->toBe(12000.0)
        ->and($log->filter(fn (string $q): bool => str_contains(strtolower($q), 'sum('))->all())->toBe([]);
});
