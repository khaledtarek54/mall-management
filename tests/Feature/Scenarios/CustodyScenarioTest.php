<?php

use App\Models\Custody;
use App\Models\Employee;
use App\Models\LedgerAccount;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\LedgerReportService;
use App\Services\GrantCustodyService;
use App\Services\SettleCustodyService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * End-to-end custody (عهدة): grant → categorised expense settlements → cash return.
 * The Custodies asset clears as the money is spent/returned; the expenses land on the
 * P&L by category; the trial balance stays balanced.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
    $this->poster = app(LedgerPoster::class);
    $this->report = app(LedgerReportService::class);
    $this->settle = app(SettleCustodyService::class);
});

function custodyClosing(string $code): float
{
    $a = LedgerAccount::where('code', $code)->first();

    return round((float) app(LedgerReportService::class)->accountLedger($a)['closing'], 2);
}

it('runs a full custody lifecycle: grant → expenses → return, clearing the custody asset', function () {
    $emp = Employee::create([
        'asset_id' => makeAsset()->id, 'code' => 'C-1', 'name' => 'Omar Fathy',
        'hire_date' => now()->startOfYear()->toDateString(), 'base_salary' => 6000, 'payment_method' => 'cash',
    ]);

    // Grant 5000 from bank.
    $custody = app(GrantCustodyService::class)->grant($emp, ['amount' => 5000, 'custody_date' => now()->toDateString(), 'paid_from' => 'bank']);
    $this->poster->sync($custody->fresh());
    expect(custodyClosing('11204001'))->toBe(5000.0); // Custodies (asset)
    expect($custody->fresh()->outstanding())->toBe(5000.0);

    // Spend 2000 on maintenance + 1500 on utilities (with receipts).
    $e1 = $this->settle->settle($custody, ['type' => 'expense', 'amount' => 2000, 'transaction_date' => now()->toDateString(), 'category' => 'maintenance']);
    $e2 = $this->settle->settle($custody, ['type' => 'expense', 'amount' => 1500, 'transaction_date' => now()->toDateString(), 'category' => 'utilities']);
    // Return the unspent 1500 in cash.
    $r = $this->settle->settle($custody, ['type' => 'return', 'amount' => 1500, 'transaction_date' => now()->toDateString(), 'method' => 'cash']);

    foreach ([$e1, $e2, $r] as $t) {
        $this->poster->sync($t->fresh());
    }

    expect($custody->fresh()->outstanding())->toBe(0.0);
    expect(custodyClosing('11204001'))->toBe(0.0);   // Custodies fully cleared
    expect(custodyClosing('51102001'))->toBe(2000.0); // Maintenance expense
    expect(custodyClosing('51103001'))->toBe(1500.0); // Utilities expense
    expect(custodyClosing('11101001'))->toBe(1500.0); // Cash back in from the return
    expect(custodyClosing('11102001'))->toBe(-5000.0); // Bank out (the grant)

    $tb = $this->report->trialBalance();
    expect($tb['balanced'])->toBeTrue();
    expect($tb['total_debit'])->toEqualWithDelta($tb['total_credit'], 0.001);
});

it('rejects over-settlement beyond the outstanding custody', function () {
    $emp = Employee::create([
        'asset_id' => makeAsset()->id, 'code' => 'C-2', 'name' => 'Nour', 'hire_date' => '2026-01-01',
        'base_salary' => 5000, 'payment_method' => 'cash',
    ]);
    $custody = app(GrantCustodyService::class)->grant($emp, ['amount' => 1000, 'custody_date' => now()->toDateString()]);
    $this->settle->settle($custody, ['type' => 'expense', 'amount' => 1000, 'transaction_date' => now()->toDateString(), 'category' => 'admin']);

    expect(fn () => $this->settle->settle($custody->fresh(), ['type' => 'expense', 'amount' => 1, 'transaction_date' => now()->toDateString(), 'category' => 'other']))
        ->toThrow(HttpException::class);
});
