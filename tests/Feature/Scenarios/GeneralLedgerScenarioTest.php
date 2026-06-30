<?php

use App\Models\LedgerAccount;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\LedgerReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->post = app(JournalPostingService::class);
    $this->r = app(AccountResolver::class);
    $this->report = app(LedgerReportService::class);
});

it('produces a trial balance that ties out across multiple entries', function () {
    // Issue invoice: Dr AR 1140 / Cr rent 1000 + VAT 140
    $this->post->post([
        'entry_date' => now()->toDateString(),
        'lines' => [
            ['ledger_account_id' => $this->r->id('accounts_receivable'), 'debit' => 1140, 'credit' => 0],
            ['ledger_account_id' => $this->r->id('rent_revenue'), 'debit' => 0, 'credit' => 1000],
            ['ledger_account_id' => $this->r->id('vat_payable'), 'debit' => 0, 'credit' => 140],
        ],
    ]);
    // Capture payment: Dr bank 1140 / Cr AR 1140
    $this->post->post([
        'entry_date' => now()->toDateString(),
        'lines' => [
            ['ledger_account_id' => $this->r->id('bank'), 'debit' => 1140, 'credit' => 0],
            ['ledger_account_id' => $this->r->id('accounts_receivable'), 'debit' => 0, 'credit' => 1140],
        ],
    ]);

    $tb = $this->report->trialBalance();

    expect($tb['balanced'])->toBeTrue();
    expect($tb['total_debit'])->toEqualWithDelta($tb['total_credit'], 0.001);
    // After the payment, AR nets to zero; bank holds 1140 on the debit side.
    expect($tb['total_debit'])->toEqualWithDelta(1140.0, 0.001);
});

it('scopes the trial balance per property and consolidates with no filter', function () {
    $a = makeAsset();
    $b = makeAsset();

    $this->post->post([
        'asset_id' => $a->id,
        'lines' => [
            ['ledger_account_id' => $this->r->id('bank'), 'debit' => 500, 'credit' => 0],
            ['ledger_account_id' => $this->r->id('rent_revenue'), 'debit' => 0, 'credit' => 500],
        ],
    ]);
    $this->post->post([
        'asset_id' => $b->id,
        'lines' => [
            ['ledger_account_id' => $this->r->id('bank'), 'debit' => 300, 'credit' => 0],
            ['ledger_account_id' => $this->r->id('rent_revenue'), 'debit' => 0, 'credit' => 300],
        ],
    ]);

    expect($this->report->trialBalance([$a->id])['total_debit'])->toEqualWithDelta(500.0, 0.001);
    expect($this->report->trialBalance([$b->id])['total_debit'])->toEqualWithDelta(300.0, 0.001);
    expect($this->report->trialBalance()['total_debit'])->toEqualWithDelta(800.0, 0.001);
    // Restricting to a set consolidates within that set.
    expect($this->report->trialBalance([$a->id, $b->id])['total_debit'])->toEqualWithDelta(800.0, 0.001);
    // An empty allowed set (a user with no properties) sees nothing.
    expect($this->report->trialBalance([])['total_debit'])->toEqualWithDelta(0.0, 0.001);
});

it('a voided entry leaves the trial balance at zero (original + reversal net out)', function () {
    $je = $this->post->post([
        'lines' => [
            ['ledger_account_id' => $this->r->id('bank'), 'debit' => 1000, 'credit' => 0],
            ['ledger_account_id' => $this->r->id('rent_revenue'), 'debit' => 0, 'credit' => 1000],
        ],
    ]);

    expect($this->report->trialBalance()['total_debit'])->toEqualWithDelta(1000.0, 0.001);

    $this->post->void($je, 'correction');

    // The void marks the original 'void' AND posts a reversal; reports count both,
    // so every account nets back to zero — no phantom balance left behind.
    $tb = $this->report->trialBalance();
    expect($tb['total_debit'])->toEqualWithDelta(0.0, 0.001);
    expect($tb['total_credit'])->toEqualWithDelta(0.0, 0.001);
    expect($tb['balanced'])->toBeTrue();
});

it('builds an account ledger with a running balance', function () {
    $this->post->post([
        'lines' => [
            ['ledger_account_id' => $this->r->id('bank'), 'debit' => 500, 'credit' => 0],
            ['ledger_account_id' => $this->r->id('rent_revenue'), 'debit' => 0, 'credit' => 500],
        ],
    ]);
    $this->post->post([
        'lines' => [
            ['ledger_account_id' => $this->r->id('bank'), 'debit' => 300, 'credit' => 0],
            ['ledger_account_id' => $this->r->id('rent_revenue'), 'debit' => 0, 'credit' => 300],
        ],
    ]);

    $bank = LedgerAccount::where('code', '11102001')->first();
    $year = (int) now()->year;
    $statement = $this->report->accountLedger(
        $bank,
        null,
        CarbonImmutable::create($year, 1, 1),
        CarbonImmutable::create($year, 12, 31),
    );

    expect($statement['lines'])->toHaveCount(2);
    expect($statement['closing'])->toEqualWithDelta(800.0, 0.001);
});
