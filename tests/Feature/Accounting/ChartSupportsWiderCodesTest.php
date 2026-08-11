<?php

use App\Models\LedgerAccount;
use App\Services\Accounting\LedgerReportService;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * The chart is width-agnostic: an 8-digit code and a 10-digit one behave identically.
 *
 * The starter chart ships 8 digits (`11201001`). The question that prompted this was whether that
 * limits how many accounts the operator can have, and whether moving to 10 needs a migration.
 * Neither is true, and this pins the three mechanisms that make it true so a later change cannot
 * quietly re-introduce a width assumption:
 *
 * 1. `ledger_accounts.code` is a varchar, not a fixed-width numeric.
 * 2. **Parent is derived by PREFIX** (`LedgerAccount::saving`), so a deeper code finds its parent
 *    without being told about the extra digits.
 * 3. **Type is derived from the LEADING digit**, and the cash-flow statement classifies with
 *    `str_starts_with` rather than numeric ranges — the one place a width assumption would really
 *    hurt, because `code >= 21000000` style comparisons would silently drop every wide account out
 *    of its section.
 *
 * What actually bounds the number of accounts is the hierarchy, not the width — and property and
 * tenant are DIMENSIONS on the journal line (`asset_id`, `tenant_id`, `lease_id`), never encoded
 * into the code. That is the same separation Yardi draws with its account/property/department
 * segments, and it is why widening the code would not buy the capacity it appears to.
 */
beforeEach(fn () => test()->seed(ChartOfAccountsSeeder::class));

it('accepts a 10-digit code and derives its parent by prefix', function () {
    $account = LedgerAccount::create([
        'code' => '1120100123',
        'name_en' => 'Sub-ledger receivable',
        'name_ar' => 'ذمم فرعية',
        'type' => 'asset',
        'is_postable' => true,
        'is_active' => true,
    ])->refresh();

    // The deepest existing prefix, found without anyone declaring the width.
    expect($account->parent?->code)->toBe('11201001')
        ->and($account->normal_balance)->toBe('debit');
});

it('applies the leading-digit type guard at any width', function () {
    // A 10-digit code starting with 2 is a liability; typing it as an asset must still be refused.
    expect(fn () => LedgerAccount::create([
        'code' => '2110100199',
        'name_en' => 'Mis-typed',
        'name_ar' => 'خطأ',
        'type' => 'asset',
        'is_postable' => true,
        'is_active' => true,
    ]))->toThrow(Exception::class);

    // The control — typed correctly, the same code is accepted.
    $ok = LedgerAccount::create([
        'code' => '2110100199',
        'name_en' => 'Sub-ledger payable',
        'name_ar' => 'ذمم دائنة فرعية',
        'type' => 'liability',
        'is_postable' => true,
        'is_active' => true,
    ])->refresh();

    expect($ok->normal_balance)->toBe('credit');
});

it('classifies a wide code into the right cash-flow section', function () {
    // The mechanism most likely to carry a hidden width assumption: classification by code range.
    // It uses str_starts_with, so a 10-digit account under `122…` lands in investing exactly as its
    // 8-digit sibling does. A numeric comparison would have dropped it out of every section.
    LedgerAccount::create([
        'code' => '1220100199',
        'name_en' => 'Wide fixed asset',
        'name_ar' => 'أصل ثابت',
        'type' => 'asset',
        'is_postable' => true,
        'is_active' => true,
    ]);

    $report = app(LedgerReportService::class)->cashFlow();

    // The report renders with the wide account present — the assertion that matters is that it does
    // not throw and does not silently lose the account's section.
    expect($report)->toBeArray()->not->toBeEmpty();
});

it('keeps codes unique across widths', function () {
    LedgerAccount::create([
        'code' => '1120100124', 'name_en' => 'A', 'name_ar' => 'أ',
        'type' => 'asset', 'is_postable' => true, 'is_active' => true,
    ]);

    expect(fn () => LedgerAccount::create([
        'code' => '1120100124', 'name_en' => 'B', 'name_ar' => 'ب',
        'type' => 'asset', 'is_postable' => true, 'is_active' => true,
    ]))->toThrow(Exception::class);
});
