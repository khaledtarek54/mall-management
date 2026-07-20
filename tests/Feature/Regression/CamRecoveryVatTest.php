<?php

use App\Models\CamExpensePool;
use App\Models\InvoiceItem;
use App\Models\JournalLine;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerReportService;
use App\Services\CamReconciliationService;
use App\Services\Reconciliation\BooksReconciliationService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Carbon;

/**
 * Per-pool VAT on the CAM cost recovery. The monthly CAM estimate bills at 14% VAT (a taxable
 * supply); the year-end true-up is more consideration for that same supply, so it carries the pool's
 * recovery_vat_rate (default 14%). These drive the REAL bill() + the REAL accounting:sync-ledger
 * sweep and assert output VAT is booked on a positive true-up and REVERSED on an over-collection
 * credit, that the trial balance ties out, and that a 0% pool is byte-identical to the pre-VAT era.
 */
afterEach(fn () => Carbon::setTestNow());

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2027);
});

/** A single-lease pool with no admin fee (isolates the recovery VAT); returns the billed allocation. */
function billAtVat(float $actual, float $estimated, float $vatRate)
{
    Carbon::setTestNow('2027-01-15');
    $asset = makeAsset();
    makeLease(makeUnit($asset, ['area_sqm' => 100]), makeTenant());
    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'total_actual_expense' => $actual, 'total_estimated_collected' => $estimated,
        'recovery_vat_rate' => $vatRate, 'status' => 'draft',
    ]);
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);

    return $svc->bill($pool->allocations()->sole());
}

function camTrialBalanced(): bool
{
    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    return app(LedgerReportService::class)->trialBalance()['balanced'];
}

function vatPayable(string $side): float
{
    $id = app(AccountResolver::class)->id('vat_payable');

    return (float) JournalLine::where('ledger_account_id', $id)->sum($side);
}

it('books output VAT on a positive true-up recovery invoice, and the books tie out', function () {
    // allocated 50000, estimated 30000 → true-up 20000; VAT 14% = 2800 ON TOP.
    $billed = billAtVat(50000, 30000, 14);

    $invoice = InvoiceItem::where('charge_id', $billed->billed_charge_id)->first()->invoice;
    expect((float) $invoice->subtotal)->toBe(20000.0)
        ->and((float) $invoice->vat_amount)->toBe(2800.0)   // 14% of the recovery
        ->and((float) $invoice->total)->toBe(22800.0)
        ->and((float) $billed->allocated_amount)->toBe(50000.0); // uncapped tie-out basis untouched by VAT

    expect(camTrialBalanced())->toBeTrue()
        ->and(vatPayable('credit'))->toBe(2800.0)  // output VAT collected
        ->and(app(BooksReconciliationService::class)->run()['ok'])->toBeTrue();
});

it('reverses output VAT on an over-collection credit note, and the books tie out', function () {
    // allocated 10000, estimated 60000 → credit 50000; VAT 14% = 7000.
    $billed = billAtVat(10000, 60000, 14);

    $note = \App\Models\CreditNote::find($billed->billed_credit_note_id);
    expect((float) $note->subtotal)->toBe(50000.0)
        ->and((float) $note->vat_amount)->toBe(7000.0)
        ->and((float) $note->total)->toBe(57000.0);

    expect(camTrialBalanced())->toBeTrue()
        ->and(vatPayable('debit'))->toBe(7000.0)  // output VAT reversed on the refund
        ->and(app(BooksReconciliationService::class)->run()['ok'])->toBeTrue();
});

it('bills no VAT when the pool recovery rate is 0% (byte-identical to the pre-VAT pass-through)', function () {
    $billed = billAtVat(50000, 30000, 0);

    $invoice = InvoiceItem::where('charge_id', $billed->billed_charge_id)->first()->invoice;
    expect((float) $invoice->subtotal)->toBe(20000.0)
        ->and((float) $invoice->vat_amount)->toBe(0.0)
        ->and((float) $invoice->total)->toBe(20000.0);

    expect(camTrialBalanced())->toBeTrue()->and(vatPayable('credit'))->toBe(0.0);
});

it('freezes the recovery VAT rate once an allocation is billed', function () {
    Carbon::setTestNow('2027-01-15');
    $asset = makeAsset();
    makeLease(makeUnit($asset, ['area_sqm' => 100]), makeTenant());
    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'total_actual_expense' => 50000, 'total_estimated_collected' => 30000,
        'recovery_vat_rate' => 14, 'status' => 'draft',
    ]);
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $svc->bill($pool->allocations()->sole());

    // Changing the recovery VAT after billing would leave the billed row on the old rate.
    expect(fn () => $pool->update(['recovery_vat_rate' => 0]))->toThrow(DomainException::class);
});
