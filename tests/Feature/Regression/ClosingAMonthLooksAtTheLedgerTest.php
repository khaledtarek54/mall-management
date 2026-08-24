<?php

/*
|--------------------------------------------------------------------------
| The close gate must look at the ledger it says it checked (2026-08-25)
|--------------------------------------------------------------------------
| The month-end close screen shows a readiness row labelled "the books tie out", and an operator
| closes the accounting period from it. It ran `BooksReconciliationService::run('YYYY-MM')`, and a
| month-scoped run SKIPS the two cumulative tie-outs — the GL's AR/AP control accounts, and the
| deposits-held liability.
|
| Measured on the seeded portfolio: eight checks on the console, SIX on the screen, both green, with
| nothing on the page naming the two that were absent. The skip itself is right — a ledger balance
| carries every period ever posted, so comparing it to one month's documents is not a weaker check
| but a meaningless one. Going quiet about it was the defect: the question asked at close is "do my
| books tie out as things now stand", which is exactly the cumulative question.
|
| Same class as every other finding in this codebase where a gate reported on a property weaker than
| the one its label claims.
*/

use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\MonthEndReadinessService;
use App\Services\Reconciliation\BooksReconciliationService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
    app(FiscalCalendar::class)->ensureYear(2026);

    // The GL tie-out reports `configured: false` — and is correctly ABSENT — until the ledger is
    // both mapped AND populated. A fixture with an empty ledger would make every assertion here
    // pass for the wrong reason, so one real invoice is posted.
    $tenant = makeTenant();
    $lease = makeLease(makeUnit($this->asset), $tenant, ['status' => 'active']);
    $this->invoice = $invoice = makeInvoice($lease, ['asset_id' => $this->asset->id, 'status' => 'issued']);
    $invoice->items()->create([
        'type' => 'base_rent', 'description' => 'Rent', 'quantity' => 1,
        'unit_price' => 10000, 'amount' => 10000,
        'vat_rate' => 14, 'vat_amount' => 1400, 'total' => 11400,
    ]);
    app(LedgerPoster::class)->sync($invoice->fresh());
});

it('runs the cumulative tie-outs the month-scoped run cannot', function () {
    $svc = app(BooksReconciliationService::class);

    $month = collect($svc->run('2026-08')['checks'])->pluck('key');
    $all = collect($svc->run(null)['checks'])->pluck('key');

    // The premise: these two genuinely are absent from a month-scoped run. If that ever stops being
    // true this test is asserting nothing, so it is checked rather than assumed.
    expect($month)->not->toContain('gl_tie_out')
        ->and($month)->not->toContain('deposits_tie_out')
        ->and($all)->toContain('gl_tie_out')
        ->and($all)->toContain('deposits_tie_out');

    // And they are reachable on their own, which is what lets the close screen have them.
    $cumulative = collect($svc->cumulativeChecks())->pluck('key');
    expect($cumulative)->toContain('deposits_tie_out');
});

it('BLOCKS the close when the ledger does not tie, on the month-scoped path', function () {
    // The test that matters, and the one my first attempt got wrong: asserting that
    // `cumulativeChecks()` exists proves the ingredient, not the dish. Only breaking the LEDGER and
    // then asking the READINESS SERVICE — the thing the screen renders — can fail when the merge is
    // removed. It was verified by deleting the merge and watching this go red.
    //
    // The break: an invoice balance moved underneath a posted entry, so the AR control account no
    // longer equals what the source documents imply. Written with a raw update precisely because no
    // service would do this — it stands in for the drift a real break causes.
    DB::table('invoices')->where('id', $this->invoice->id)->update(['balance' => 999999]);

    $step = collect(app(MonthEndReadinessService::class)->for(CarbonImmutable::parse('2026-08-01'))['steps'])
        ->firstWhere('key', 'books_tie_out');

    expect($step)->not->toBeNull()
        ->and($step['status'])->not->toBe('ok')
        ->and($step['detail'])->toContain('General ledger');
});

it('still passes the close when the books DO tie', function () {
    // Paired with the refusal above: a gate that blocked on everything would satisfy that test and
    // be useless. This is the control.
    $step = collect(app(MonthEndReadinessService::class)->for(CarbonImmutable::parse('2026-08-01'))['steps'])
        ->firstWhere('key', 'books_tie_out');

    expect($step['status'])->toBe('ok');
});

it('reports the ledger check by name, so the row cannot go green over a skipped one', function () {
    $labels = collect(app(BooksReconciliationService::class)->cumulativeChecks())
        ->pluck('label')->implode(' · ');

    expect($labels)->toContain('General ledger')
        ->and($labels)->toContain('deposits');
});
