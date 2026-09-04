<?php

/**
 * A thousands separator means a thousand — in every importer that reads a money cell.
 *
 * Three importers each had their own reading of a spreadsheet amount, and two of them were
 * byte-identical private methods. They drifted in OPPOSITE directions, so one cell read as a
 * thousand by one importer read as a decimal by the next.
 *
 * Measured on HEAD before the fix (2026-09-04):
 *
 *   ImportBankStatementService::toFloat()   '1,234,567' → 1.234    '12,500' → 12.50
 *                                           '(1,250)'   → -1.25    '1.234,56' → 1.23456
 *   ImportOpeningBalancesService::amount()  '1.234,56'  → 1.23456  '1 234,56' → 123456
 *   BudgetService::amount()                 the same, byte for byte
 *
 * Neither import posts anything by itself. What each produces is a figure a person then signs
 * off — a statement line that can no longer be matched against the payment it IS, and the opening
 * trial balance every later statement is built on. `App\Support\CsvAmount` is now the one reading.
 */

use App\Models\BudgetLine;
use App\Models\LedgerAccount;
use App\Services\Accounting\BudgetService;
use App\Services\Accounting\ImportOpeningBalancesService;
use App\Services\Banking\ImportBankStatementService;
use App\Support\CsvAmount;

it('reads a thousands separator as a thousand, whichever separator it is', function () {
    expect(CsvAmount::parse('1,234,567'))->toBe(1234567.0)
        ->and(CsvAmount::parse('12,500'))->toBe(12500.0)
        ->and(CsvAmount::parse('(1,250)'))->toBe(-1250.0)
        // Two of the same separator cannot both be a decimal point.
        ->and(CsvAmount::parse('1.234.567'))->toBe(1234567.0)
        // Both present: the LAST one is the decimal point, whichever it is.
        ->and(CsvAmount::parse('1,234.56'))->toBe(1234.56)
        ->and(CsvAmount::parse('1.234,56'))->toBe(1234.56);
});

it('still reads a comma that is not grouping a thousand as a decimal point', function () {
    // The control, and it is the whole reason this is not "strip every comma": that rule is the
    // same bug pointing the other way. '1234,56' is 1234.56 on a European sheet and '0,500' is
    // half a pound — a leading zero is how somebody writes a fraction, never a thousands group.
    expect(CsvAmount::parse('1234,56'))->toBe(1234.56)
        ->and(CsvAmount::parse('1 234,56'))->toBe(1234.56)
        ->and(CsvAmount::parse('0,500'))->toBe(0.5)
        ->and(CsvAmount::parse('12,50'))->toBe(12.5)
        // A lone DOT is ALWAYS the decimal point — deliberately not symmetrical with the comma
        // rule, because Egyptian exports are English-formatted to two places and reading '1.500'
        // as 1500 would overstate the common form to rescue the rare one.
        ->and(CsvAmount::parse('1.500'))->toBe(1.5)
        ->and(CsvAmount::parse('(789.10)'))->toBe(-789.1)
        ->and(CsvAmount::parse('1,234.50 EGP'))->toBe(1234.5)
        ->and(CsvAmount::parse('-1,234.56'))->toBe(-1234.56)
        ->and(CsvAmount::parse(''))->toBe(0.0)
        ->and(CsvAmount::parse('-'))->toBe(0.0);
});

it('imports a bank statement row at the figure the bank printed', function () {
    $csv = <<<'CSV'
    date,details,amount,balance
    2026-03-02,Rent - three shops,"1,234,567","2,000,000"
    2026-03-03,Card settlement,"12,500","2,012,500"
    2026-03-04,Account fee,"(1,250)","2,011,250"
    CSV;

    $rows = app(ImportBankStatementService::class)->parseCsv($csv);

    expect($rows[0]['amount'])->toBe(1234567.0)
        ->and($rows[0]['running_balance'])->toBe(2000000.0)
        ->and($rows[1]['amount'])->toBe(12500.0)
        ->and($rows[2]['amount'])->toBe(-1250.0);
});

it('reads the European trial balance the opening-balance importer already accepts', function () {
    // `ImportOpeningBalancesService::cells()` splits on ';' before it tries a comma — i.e. it goes
    // out of its way to accept the European Excel export, whose numbers are exactly the ones its
    // own amount parser then destroyed.
    LedgerAccount::create([
        'code' => '11101', 'name_en' => 'Cash', 'name_ar' => 'النقدية',
        'type' => 'asset', 'is_postable' => true, 'is_active' => true,
    ]);
    LedgerAccount::create([
        'code' => '21101', 'name_en' => 'Payables', 'name_ar' => 'الدائنون',
        'type' => 'liability', 'is_postable' => true, 'is_active' => true,
    ]);

    $preview = app(ImportOpeningBalancesService::class)->preview("11101;1.234,56;0\n21101;0;1.234,56");

    expect($preview['errors'])->toBe([])
        ->and($preview['debit'])->toBe(1234.56)
        ->and($preview['credit'])->toBe(1234.56)
        ->and($preview['balanced'])->toBeTrue();
});

it('reads a European budget sheet at its face value', function () {
    $asset = makeAsset();
    LedgerAccount::create([
        'code' => '51101001', 'name_en' => 'Cleaning', 'name_ar' => 'النظافة',
        'type' => 'expense', 'is_postable' => true, 'is_active' => true,
    ]);

    app(BudgetService::class)->import('51101001;3;1.234,56', 2026, $asset->id);

    expect((float) BudgetLine::query()->where('asset_id', $asset->id)->sum('amount'))->toBe(1234.56);
});
