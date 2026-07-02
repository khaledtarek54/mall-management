<?php

use App\Models\DepositTransaction;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\LedgerReportService;
use App\Services\DepositService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->poster = app(LedgerPoster::class);
    $this->accounts = app(AccountResolver::class);
    $this->lease = makeLease(makeUnit(makeAsset()));
});

function makeDeposit($lease, array $attrs = []): DepositTransaction
{
    return DepositTransaction::create(array_merge([
        'lease_id' => $lease->id,
        'type' => 'receipt',
        'amount' => 5000,
        'transaction_date' => now()->toDateString(),
        'method' => 'bank',
        'status' => 'recorded',
    ], $attrs));
}

it('derives tenant + asset from the lease on create', function () {
    $deposit = makeDeposit($this->lease);
    expect($deposit->tenant_id)->toBe($this->lease->tenant_id);
    expect($deposit->asset_id)->toBe($this->lease->unit->asset_id);
});

it('journalizes a receipt as Dr Bank / Cr Deposits Held', function () {
    $entry = $this->poster->post(makeDeposit($this->lease, ['type' => 'receipt'])->fresh());

    expect($entry->isBalanced())->toBeTrue();
    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('bank')]->debit)->toEqualWithDelta(5000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('deposits_held')]->credit)->toEqualWithDelta(5000.0, 0.001);
});

it('journalizes a refund as Dr Deposits Held / Cr Bank', function () {
    $entry = $this->poster->post(makeDeposit($this->lease, ['type' => 'refund'])->fresh());

    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('deposits_held')]->debit)->toEqualWithDelta(5000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('bank')]->credit)->toEqualWithDelta(5000.0, 0.001);
});

it('journalizes a forfeit as Dr Deposits Held / Cr Misc Income', function () {
    $entry = $this->poster->post(makeDeposit($this->lease, ['type' => 'forfeit'])->fresh());

    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('deposits_held')]->debit)->toEqualWithDelta(5000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('misc_income')]->credit)->toEqualWithDelta(5000.0, 0.001);
});

it('routes a cash deposit to the cash account', function () {
    $entry = $this->poster->post(makeDeposit($this->lease, ['method' => 'cash'])->fresh());
    expect($entry->lines->keyBy('ledger_account_id')->has($this->accounts->id('cash')))->toBeTrue();
});

it('skips a cancelled deposit and keeps the trial balance balanced', function () {
    expect($this->poster->post(makeDeposit($this->lease, ['status' => 'cancelled'])))->toBeNull();

    $this->poster->post(makeDeposit($this->lease, ['type' => 'receipt'])->fresh());
    expect(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue();
});

it('cancels a deposit transaction (idempotent) via the service', function () {
    $deposit = makeDeposit($this->lease);
    app(DepositService::class)->cancel($deposit);
    expect($deposit->fresh()->status)->toBe('cancelled');
    app(DepositService::class)->cancel($deposit->fresh());
    expect($deposit->fresh()->status)->toBe('cancelled');
});

it('skips a deposit of an unknown type (journalizer match default → null)', function () {
    // The DB enum can't hold an unknown value, so exercise the defensive `default => null`
    // arm with an in-memory type (not persisted).
    $deposit = makeDeposit($this->lease, ['type' => 'receipt'])->fresh();
    $deposit->type = 'transfer';

    expect($this->poster->post($deposit))->toBeNull();
});
