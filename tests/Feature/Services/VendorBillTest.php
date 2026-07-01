<?php

use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\LedgerReportService;
use App\Services\VendorBillService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->svc = app(VendorBillService::class);
    $this->poster = app(LedgerPoster::class);
    $this->accounts = app(AccountResolver::class);
});

function makeBill(array $attrs = []): VendorBill
{
    return VendorBill::create(array_merge([
        'vendor_id' => Vendor::factory()->create()->id,
        'asset_id' => makeAsset()->id,
        'category' => 'maintenance',
        'status' => 'draft',
        'bill_date' => now()->toDateString(),
        'subtotal' => 10000,
        'vat_amount' => 1400,
        'total' => 11400,
        'balance' => 11400,
    ], $attrs));
}

it('approves a draft bill (makes it postable)', function () {
    $bill = makeBill();
    expect($bill->isPostable())->toBeFalse();

    $this->svc->approve($bill);

    expect($bill->fresh()->status)->toBe('approved');
    expect($bill->fresh()->isPostable())->toBeTrue();
});

it('records a partial then final payment, deriving balance + status', function () {
    $bill = makeBill();
    $this->svc->approve($bill);

    $this->svc->recordPayment($bill->fresh(), 4000, 'bank_transfer');
    expect($bill->fresh()->status)->toBe('partially_paid');
    expect((float) $bill->fresh()->balance)->toEqualWithDelta(7400.0, 0.001);

    $this->svc->recordPayment($bill->fresh(), 7400, 'cash');
    expect($bill->fresh()->status)->toBe('paid');
    expect((float) $bill->fresh()->balance)->toEqualWithDelta(0.0, 0.001);
});

it('caps a payment at the remaining balance (no over-pay)', function () {
    $bill = makeBill();
    $this->svc->approve($bill);

    $paid = $this->svc->recordPayment($bill->fresh(), 99999, 'bank_transfer');

    expect($paid)->toEqualWithDelta(11400.0, 0.001);
    expect((float) $bill->fresh()->balance)->toEqualWithDelta(0.0, 0.001);
});

it('refuses to pay a draft bill', function () {
    $bill = makeBill();
    expect($this->svc->recordPayment($bill, 100))->toEqualWithDelta(0.0, 0.001);
});

it('refuses to cancel a bill that has payments', function () {
    $bill = makeBill();
    $this->svc->approve($bill);
    $this->svc->recordPayment($bill->fresh(), 100, 'cash');

    expect(fn () => $this->svc->cancel($bill->fresh()))->toThrow(DomainException::class);
});

it('journalizes a bill as Dr expense + Dr VAT-recoverable / Cr Payables', function () {
    $bill = makeBill();
    $this->svc->approve($bill);

    $entry = $this->poster->post($bill->fresh());

    expect($entry->isBalanced())->toBeTrue();
    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('maintenance_expense')]->debit)->toEqualWithDelta(10000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('vat_recoverable')]->debit)->toEqualWithDelta(1400.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('accounts_payable')]->credit)->toEqualWithDelta(11400.0, 0.001);
});

it('skips journalizing a draft bill', function () {
    expect($this->poster->post(makeBill()))->toBeNull();
});

it('journalizes a bill payment as Dr Payables / Cr Bank and ties AP to bill balances', function () {
    $bill = makeBill();
    $this->svc->approve($bill);
    $this->poster->post($bill->fresh());           // AP +11400

    $this->svc->recordPayment($bill->fresh(), 11400, 'bank_transfer');
    $payment = $bill->payments()->first();
    $entry = $this->poster->post($payment);        // AP −11400

    expect($entry->isBalanced())->toBeTrue();
    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('accounts_payable')]->debit)->toEqualWithDelta(11400.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('bank')]->credit)->toEqualWithDelta(11400.0, 0.001);

    $ap = $this->accounts->account('accounts_payable');
    $glAp = app(LedgerReportService::class)->accountLedger($ap)['closing'];
    expect($glAp)->toEqualWithDelta((float) $bill->fresh()->balance, 0.001); // both 0
});
