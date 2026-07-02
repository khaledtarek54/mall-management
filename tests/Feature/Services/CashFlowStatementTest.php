<?php

use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\LedgerReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
    $this->poster = app(LedgerPoster::class);
    $this->reports = app(LedgerReportService::class);
});

function cfYear(): array
{
    $y = (int) now()->year;

    return [CarbonImmutable::create($y, 1, 1), CarbonImmutable::create($y, 12, 31)];
}

it('reconciles operating activities to the actual cash movement (invoice then payment)', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())), [
        'issue_date' => now()->toDateString(),
        'subtotal' => 11000, 'vat_amount' => 140, 'total' => 11140, 'balance' => 11140,
    ]);
    $invoice->items()->create(['type' => 'base_rent', 'description' => 'Rent', 'amount' => 10000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000]);
    $invoice->items()->create(['type' => 'service_charge', 'description' => 'Service', 'amount' => 1000, 'vat_rate' => 14, 'vat_amount' => 140, 'total' => 1140]);
    $this->poster->sync($invoice->fresh());

    $payment = Payment::create([
        'reference' => 'PAY-CF-1', 'tenant_id' => $invoice->tenant_id, 'amount' => 11140,
        'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => now()->toDateString(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 11140]);
    $this->poster->sync($payment->fresh());

    [$from, $to] = cfYear();
    $cf = $this->reports->cashFlow(null, $from, $to);

    expect($cf['reconciled'])->toBeTrue();
    expect($cf['net_change'])->toEqualWithDelta($cf['cash_movement'], 0.001);
    expect($cf['cash_movement'])->toEqualWithDelta(11140.0, 0.001); // collected in cash
    expect($cf['net_income'])->toEqualWithDelta(11000.0, 0.001);    // rent + service (ex-VAT)
    expect($cf['operating_total'])->toEqualWithDelta(11140.0, 0.001);
    expect($cf['cash_closing'])->toEqualWithDelta(11140.0, 0.001);
});

it('classifies investing (fixed assets) + financing (capital) and still reconciles', function () {
    $posting = app(JournalPostingService::class);
    $acct = fn (string $code) => LedgerAccount::where('code', $code)->value('id');

    // Owner injects 50,000 capital into the bank — financing inflow.
    $posting->post(['entry_date' => now(), 'description_en' => 'Capital', 'description_ar' => 'رأس مال', 'lines' => [
        ['ledger_account_id' => $acct('11102001'), 'debit' => 50000, 'credit' => 0],
        ['ledger_account_id' => $acct('31101001'), 'debit' => 0, 'credit' => 50000],
    ]]);

    // Buy furniture for 20,000 from the bank — investing outflow.
    $posting->post(['entry_date' => now(), 'description_en' => 'Furniture', 'description_ar' => 'أثاث', 'lines' => [
        ['ledger_account_id' => $acct('12101001'), 'debit' => 20000, 'credit' => 0],
        ['ledger_account_id' => $acct('11102001'), 'debit' => 0, 'credit' => 20000],
    ]]);

    [$from, $to] = cfYear();
    $cf = $this->reports->cashFlow(null, $from, $to);

    expect($cf['reconciled'])->toBeTrue();
    expect($cf['financing_total'])->toEqualWithDelta(50000.0, 0.001);
    expect($cf['investing_total'])->toEqualWithDelta(-20000.0, 0.001);
    expect($cf['cash_movement'])->toEqualWithDelta(30000.0, 0.001);
    expect($cf['net_change'])->toEqualWithDelta(30000.0, 0.001);
});

it('treats depreciation as an operating add-back, not a phantom investing flow', function () {
    $posting = app(JournalPostingService::class);
    $acct = fn (string $code) => LedgerAccount::where('code', $code)->value('id');

    // Monthly depreciation: Dr Depreciation Expense / Cr Accumulated Depreciation (non-cash).
    $posting->post(['entry_date' => now(), 'description_en' => 'Depreciation', 'description_ar' => 'إهلاك', 'lines' => [
        ['ledger_account_id' => $acct('51107001'), 'debit' => 1000, 'credit' => 0],
        ['ledger_account_id' => $acct('12201001'), 'debit' => 0, 'credit' => 1000],
    ]]);

    [$from, $to] = cfYear();
    $cf = $this->reports->cashFlow(null, $from, $to);

    expect($cf['net_income'])->toEqualWithDelta(-1000.0, 0.001);      // expense reduces net income
    expect($cf['investing_total'])->toEqualWithDelta(0.0, 0.001);     // NOT a +1000 investing inflow
    expect($cf['operating_total'])->toEqualWithDelta(0.0, 0.001);     // −1000 expense + 1000 add-back
    expect($cf['cash_movement'])->toEqualWithDelta(0.0, 0.001);       // depreciation is non-cash
    expect($cf['reconciled'])->toBeTrue();
});

it('carries the opening cash balance from a prior period', function () {
    $posting = app(JournalPostingService::class);
    $acct = fn (string $code) => LedgerAccount::where('code', $code)->value('id');

    // Prior-year capital injection sits as opening cash for this year.
    $priorYear = (int) now()->subYear()->year;
    app(FiscalCalendar::class)->ensureYear($priorYear);
    $posting->post(['entry_date' => CarbonImmutable::create($priorYear, 6, 1), 'description_en' => 'Prior capital', 'description_ar' => 'رأس مال سابق', 'lines' => [
        ['ledger_account_id' => $acct('11102001'), 'debit' => 5000, 'credit' => 0],
        ['ledger_account_id' => $acct('31101001'), 'debit' => 0, 'credit' => 5000],
    ]]);

    [$from, $to] = cfYear();
    $cf = $this->reports->cashFlow(null, $from, $to);

    expect($cf['cash_opening'])->toEqualWithDelta(5000.0, 0.001);
    expect($cf['cash_closing'])->toEqualWithDelta(5000.0, 0.001); // no movement this year
    expect($cf['reconciled'])->toBeTrue();
});
