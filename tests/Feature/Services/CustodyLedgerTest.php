<?php

use App\Models\Custody;
use App\Models\CustodyTransaction;
use App\Models\Employee;
use App\Models\LedgerAccount;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\LedgerReportService;
use App\Services\GrantCustodyService;
use App\Services\SettleCustodyService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
    $this->poster = app(LedgerPoster::class);
    $this->accounts = app(AccountResolver::class);
});

function custodyEmployee(): Employee
{
    return Employee::create([
        'asset_id' => makeAsset()->id, 'code' => 'E-'.uniqid(), 'name' => 'Karim Nabil',
        'hire_date' => now()->startOfYear()->toDateString(), 'base_salary' => 7000, 'payment_method' => 'bank',
    ]);
}

function grantCustody(Employee $employee, array $attrs = []): Custody
{
    return app(GrantCustodyService::class)->grant($employee, array_merge([
        'amount' => 5000, 'custody_date' => now()->toDateString(), 'paid_from' => 'cash',
    ], $attrs));
}

function custodyExpectAllZero(array $codes): void
{
    foreach ($codes as $code) {
        $account = LedgerAccount::where('code', $code)->first();
        $statement = app(LedgerReportService::class)->accountLedger($account);
        expect($statement['closing'])->toEqualWithDelta(0.0, 0.001, "account {$code} should net to zero");
    }
}

/* ---- Grant --------------------------------------------------------------- */

it('journalizes a custody grant as Dr Custodies / Cr Cash', function () {
    $emp = custodyEmployee();
    $custody = grantCustody($emp, ['amount' => 5000, 'paid_from' => 'cash']);

    $entry = $this->poster->post($custody);

    expect($entry->isBalanced())->toBeTrue();
    expect((int) $entry->asset_id)->toBe($emp->asset_id);
    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('custody')]->debit)->toEqualWithDelta(5000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('cash')]->credit)->toEqualWithDelta(5000.0, 0.001);
    expect($byAccount->has($this->accounts->id('accounts_receivable')))->toBeFalse();
    expect($byAccount->has($this->accounts->id('accounts_payable')))->toBeFalse();
});

it('rejects a grant to a terminated employee or a non-positive amount', function () {
    $emp = custodyEmployee();
    $emp->update(['status' => 'terminated']);
    expect(fn () => grantCustody($emp->fresh()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    expect(fn () => grantCustody(custodyEmployee(), ['amount' => 0]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

/* ---- Settlements --------------------------------------------------------- */

it('journalizes an expense settlement as Dr Expense (by category) / Cr Custodies', function () {
    $custody = grantCustody(custodyEmployee(), ['amount' => 5000]);
    $txn = app(SettleCustodyService::class)->settle($custody, [
        'type' => 'expense', 'amount' => 1200, 'transaction_date' => now()->toDateString(), 'category' => 'maintenance',
    ]);

    $entry = $this->poster->post($txn);
    expect($entry->isBalanced())->toBeTrue();
    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('maintenance_expense')]->debit)->toEqualWithDelta(1200.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('custody')]->credit)->toEqualWithDelta(1200.0, 0.001);
});

it('journalizes a cash return as Dr Cash / Cr Custodies', function () {
    $custody = grantCustody(custodyEmployee(), ['amount' => 5000]);
    $txn = app(SettleCustodyService::class)->settle($custody, [
        'type' => 'return', 'amount' => 800, 'transaction_date' => now()->toDateString(), 'method' => 'cash',
    ]);

    $byAccount = $this->poster->post($txn)->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('cash')]->debit)->toEqualWithDelta(800.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('custody')]->credit)->toEqualWithDelta(800.0, 0.001);
});

it('derives settled + outstanding and rejects over-settlement', function () {
    $custody = grantCustody(custodyEmployee(), ['amount' => 5000]);
    $svc = app(SettleCustodyService::class);
    $svc->settle($custody, ['type' => 'expense', 'amount' => 3000, 'transaction_date' => now()->toDateString(), 'category' => 'admin']);
    $svc->settle($custody, ['type' => 'return', 'amount' => 1000, 'transaction_date' => now()->toDateString(), 'method' => 'cash']);

    expect($custody->fresh()->settled())->toBe(4000.0);
    expect($custody->fresh()->outstanding())->toBe(1000.0);

    // Over-settling the remaining 1000 is rejected.
    expect(fn () => $svc->settle($custody->fresh(), ['type' => 'expense', 'amount' => 1500, 'transaction_date' => now()->toDateString(), 'category' => 'other']))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

/* ---- Lifecycle ----------------------------------------------------------- */

it('nets Custodies to zero after full settlement', function () {
    $custody = grantCustody(custodyEmployee(), ['amount' => 5000, 'paid_from' => 'cash']);
    $this->poster->sync($custody->fresh());
    $svc = app(SettleCustodyService::class);
    $e = $svc->settle($custody, ['type' => 'expense', 'amount' => 4200, 'transaction_date' => now()->toDateString(), 'category' => 'maintenance']);
    $r = $svc->settle($custody, ['type' => 'return', 'amount' => 800, 'transaction_date' => now()->toDateString(), 'method' => 'cash']);
    $this->poster->sync($e->fresh());
    $this->poster->sync($r->fresh());

    // Grant Dr 5000 offset by 4200 expense + 800 return credits → Custodies clears.
    custodyExpectAllZero(['11204001']);
});

it('voids the grant AND settlement entries through the WINDOWED sweep on soft-delete', function () {
    $custody = grantCustody(custodyEmployee(), ['amount' => 5000]);
    $txn = app(SettleCustodyService::class)->settle($custody, ['type' => 'expense', 'amount' => 1200, 'transaction_date' => now()->toDateString(), 'category' => 'admin']);

    $this->poster->sync($custody->fresh());
    $this->poster->sync($txn->fresh());
    DB::table('custody_transactions')->where('id', $txn->id)->update(['updated_at' => now()->subDays(30)]);

    $custody->delete();
    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    custodyExpectAllZero(['11204001', '11101001', '51106001']); // custody, cash, admin expense
    expect(CustodyTransaction::withTrashed()->find($txn->id)->trashed())->toBeTrue();
});
