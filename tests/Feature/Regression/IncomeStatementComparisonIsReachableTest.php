<?php

/*
|--------------------------------------------------------------------------
| A comparative statement nobody could reach (RP-06)
|--------------------------------------------------------------------------
| `ComparativeStatementService` was built, documented and covered by five passing tests — and called
| by NOTHING but those tests. An operator could not produce a comparative income statement at all.
|
| That is the third time this shape has turned up in this codebase in a week: a correct mechanism
| with no consumer, invisible because every test of it passes. `ComparativeStatementTest` proves the
| arithmetic; these prove an operator can get at it.
|
| So the tests here drive the real Livewire page: choose a basis, and assert the prior column
| actually carries last period's number.
*/

use App\Filament\Admin\Pages\IncomeStatement;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Services\Reports\ComparativeStatementService;
use Database\Seeders\AccountingSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(AccountingSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('super_admin'));
    $this->asset = makeAsset();
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** Post a revenue amount on a date, so the statement has something to compare. */
function postRevenue(int $assetId, string $on, float $amount): void
{
    $revenue = LedgerAccount::where('type', 'revenue')->where('is_postable', true)->firstOrFail();
    $cash = LedgerAccount::where('type', 'asset')->where('is_postable', true)->firstOrFail();

    $entry = JournalEntry::create([
        'asset_id' => $assetId,
        'entry_date' => $on,
        // Draft first: a line cannot be added to a POSTED entry (debits would stop equalling
        // credits mid-write), which is the guard doing its job.
        'status' => 'draft',
        'source_type' => 'manual',
    ]);

    JournalLine::create(['journal_entry_id' => $entry->id, 'ledger_account_id' => $cash->id, 'debit' => $amount, 'credit' => 0]);
    JournalLine::create(['journal_entry_id' => $entry->id, 'ledger_account_id' => $revenue->id, 'debit' => 0, 'credit' => $amount]);

    $entry->update(['status' => 'posted']);
}

it('offers the comparison on the page an operator opens', function () {
    // The wiring, at its plainest: the parameter exists on the real component and accepts a basis.
    Livewire::test(IncomeStatement::class)
        ->assertSet('comparison', null)
        ->set('comparison', ComparativeStatementService::PRIOR_PERIOD)
        ->assertSet('comparison', ComparativeStatementService::PRIOR_PERIOD)
        ->assertOk();
});

it('puts last period figure in the prior column', function () {
    // Two different amounts in two adjacent months, so a report that ignored the comparison — or
    // compared the period to itself — cannot pass by coincidence.
    postRevenue($this->asset->id, '2026-02-10', 40_000);
    postRevenue($this->asset->id, '2026-03-10', 180_000);

    $page = new IncomeStatement;
    $page->year = 2026;
    $page->period = '2026-03';
    $page->comparison = ComparativeStatementService::PRIOR_PERIOD;

    $records = $page->comparativeRecords($page->comparative());
    $total = collect($records)->firstWhere('account', __('admin.reports.total_revenue'));

    expect($total['amount'])->toBe(180_000.0)
        ->and($total['prior'])->toBe(40_000.0)
        ->and($total['change'])->toBe(140_000.0);
});

it('compares against the same month a year earlier when asked to', function () {
    // The basis this build added. A mall is seasonal — Ramadan and the back-to-school weeks move
    // footfall and therefore turnover rent — so December against November says almost nothing.
    postRevenue($this->asset->id, '2025-03-10', 90_000);
    postRevenue($this->asset->id, '2026-02-10', 40_000);
    postRevenue($this->asset->id, '2026-03-10', 180_000);

    $page = new IncomeStatement;
    $page->year = 2026;
    $page->period = '2026-03';
    $page->comparison = ComparativeStatementService::PRIOR_YEAR;

    $comparative = $page->comparative();
    $total = collect($page->comparativeRecords($comparative))->firstWhere('account', __('admin.reports.total_revenue'));

    // Last MARCH (90k), not last month (40k) — the distinction the basis exists for.
    expect($total['prior'])->toBe(90_000.0)
        ->and($comparative['prior_from'])->toBe('2025-03-01');
});

it('shows no comparison at all by default', function () {
    // A single-period statement stays the default. Turning comparison on for everyone would change
    // what every existing saved view and scheduled delivery renders.
    $page = new IncomeStatement;
    $page->year = 2026;
    $page->period = '2026-03';

    expect($page->comparative())->toBeNull();
});

it('ignores a basis it does not recognise instead of throwing', function () {
    // `comparison` reaches the page from a URL, so it is operator-supplied. An unknown value must
    // degrade to the plain statement rather than 500 a financial report.
    $page = new IncomeStatement;
    $page->comparison = 'last_tuesday';

    expect($page->comparative())->toBeNull();
});

it('exports the comparison it displays', function () {
    // A statement read WITH prior-period columns and exported WITHOUT them is a different document
    // under the same name — and the export is the copy that gets emailed and argued over.
    postRevenue($this->asset->id, '2026-02-10', 40_000);
    postRevenue($this->asset->id, '2026-03-10', 180_000);

    $page = new IncomeStatement;
    $page->year = 2026;
    $page->period = '2026-03';
    $page->comparison = ComparativeStatementService::PRIOR_PERIOD;

    $csv = $page->reportCsv();

    expect($csv['headers'])->toContain(__('admin.reports.prior'), __('admin.reports.change'))
        ->and(collect($csv['rows'])->pluck(4))->toContain(40_000.0);

    // The control: without a comparison the export is the plain three-column statement.
    $page->comparison = null;

    expect($page->reportCsv()['headers'])->not->toContain(__('admin.reports.prior'));
});

it('leaves the percentage empty rather than inventing one', function () {
    // A rise from nothing has no percentage. Printing "+100%" or "∞" invents a number the books do
    // not support, and a spreadsheet would total a 0 and read it as "no change".
    postRevenue($this->asset->id, '2026-03-10', 180_000);   // nothing at all in February

    $page = new IncomeStatement;
    $page->year = 2026;
    $page->period = '2026-03';
    $page->comparison = ComparativeStatementService::PRIOR_PERIOD;

    $total = collect($page->comparativeRecords($page->comparative()))
        ->firstWhere('account', __('admin.reports.total_revenue'));

    expect($total['prior'])->toBe(0.0)
        ->and($total['change_pct'])->toBeNull();
});
