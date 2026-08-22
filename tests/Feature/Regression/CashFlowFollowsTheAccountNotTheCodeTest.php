<?php

use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\Accounting\LedgerReportService;
use App\Support\CashFlowSection;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * EG-28, finding S-4 — the cash-flow statement stops classifying by literal code prefixes.
 *
 * It read `111`, `121`, `122`, `12`, `22` and `222` off the account code, so it was correct only
 * about the chart this project happens to ship. The failure mode is the dangerous one: a different
 * Egyptian chart numbered 1–5 by nature but with **different sub-ranges** saves fine — the save-time
 * guard only checks the leading digit — and then silently misclassifies every cash flow. Nothing
 * errors, the statement still balances, and the figures are wrong.
 *
 * It matters now rather than hypothetically: the operator's real chart is still pending, and the one
 * supplied so far is recorded as a dummy template.
 *
 * The account now says where it belongs, and the shipped chart was backfilled from exactly the rules
 * the report used — so today's statement is unchanged and any other chart is classified by an
 * accountant rather than inferred from how somebody numbered it.
 */
function cashFlowAccount(string $code, string $type, ?string $section): LedgerAccount
{
    return LedgerAccount::create([
        'code' => $code,
        'name_en' => 'A '.$code,
        'name_ar' => 'ح '.$code,
        'type' => $type,
        'cash_flow_section' => $section,
        'is_postable' => true,
        'is_active' => true,
    ]);
}

/** A posted entry moving `$amount` from `$debit` to `$credit`. */
function cashFlowEntry(LedgerAccount $debit, LedgerAccount $credit, float $amount, string $on = '2026-03-10'): void
{
    $entry = JournalEntry::create([
        'number' => 'JE-'.uniqid(), 'entry_date' => $on, 'status' => 'draft', 'is_manual' => true,
    ]);

    $entry->lines()->create(['ledger_account_id' => $debit->id, 'debit' => $amount, 'credit' => 0]);
    $entry->lines()->create(['ledger_account_id' => $credit->id, 'debit' => 0, 'credit' => $amount]);
    $entry->update(['status' => 'posted']);
}

it('classifies a chart whose sub-ranges are nothing like ours', function () {
    // The whole finding. These codes start 1 and 2 so they SAVE — the guard only checks the nature
    // digit — and under the old prefix rules `1900` and `2900` both fell through to operating,
    // silently moving a capital purchase and a loan drawdown into working capital.
    $cash = cashFlowAccount('1000', 'asset', CashFlowSection::CASH);
    $plant = cashFlowAccount('1900', 'asset', CashFlowSection::INVESTING);
    $loan = cashFlowAccount('2900', 'liability', CashFlowSection::FINANCING);

    cashFlowEntry($plant, $cash, 40_000);   // bought plant with cash
    cashFlowEntry($cash, $loan, 100_000);   // drew down a loan

    $cf = app(LedgerReportService::class)->cashFlow(
        null, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')
    );

    expect($cf['investing_total'])->toBe(-40000.0)
        ->and($cf['financing_total'])->toBe(100000.0)
        // …and the cash account is the balance being explained, not a section of it.
        ->and($cf['operating_total'])->toBe(0.0);
});

it('leaves the shipped chart classifying exactly as it did', function () {
    // The control, and the deploy safety case: the seeder derives the same sections the report used
    // to infer, so no figure on an existing install moves.
    $this->seed(ChartOfAccountsSeeder::class);

    $cash = LedgerAccount::where('code', 'like', '111%')->where('is_postable', true)->firstOrFail();
    $nonCurrent = LedgerAccount::where('code', 'like', '121%')->where('is_postable', true)->firstOrFail();

    expect($cash->cash_flow_section)->toBe(CashFlowSection::CASH)
        ->and($nonCurrent->cash_flow_section)->toBe(CashFlowSection::INVESTING);

    // Accumulated depreciation is an operating add-back, not investing — the ordering rule the
    // prefix chain encoded and the backfill has to preserve.
    $accumulated = LedgerAccount::where('code', 'like', '122%')->first();

    if ($accumulated) {
        expect($accumulated->cash_flow_section)->toBe(CashFlowSection::OPERATING);
    }

    // Provisions sit under 22 but are NOT financing.
    $provision = LedgerAccount::where('code', 'like', '222%')->first();

    if ($provision) {
        expect($provision->cash_flow_section)->toBe(CashFlowSection::OPERATING);
    }
});

it('never lets revenue or expense carry a section', function () {
    // They net into income by TYPE. A section on them would let an operator move revenue into
    // investing and break the statement's own arithmetic.
    $this->seed(ChartOfAccountsSeeder::class);

    $offenders = LedgerAccount::whereIn('type', ['revenue', 'expense'])
        ->whereNotNull('cash_flow_section')
        ->pluck('code')
        ->all();

    expect(implode(', ', $offenders))->toBe('');
});

it('sends an unclassified account to operating, and equity to financing', function () {
    // The floor. Being wrong toward operating leaves the net change in cash correct; being wrong
    // toward investing misstates two subtotals.
    expect(CashFlowSection::for(null, 'asset'))->toBe(CashFlowSection::OPERATING)
        ->and(CashFlowSection::for(null, 'liability'))->toBe(CashFlowSection::OPERATING)
        ->and(CashFlowSection::for(null, 'equity'))->toBe(CashFlowSection::FINANCING)
        // A value nobody registered is not trusted.
        ->and(CashFlowSection::for('operatng', 'asset'))->toBe(CashFlowSection::OPERATING);
});

it('refuses a mistyped section at the model layer', function () {
    // A typo does not error on its own — it would silently send the movement to the operating
    // default — so the value set is what makes it loud.
    expect(fn () => cashFlowAccount('1500', 'asset', 'operatng'))
        ->toThrow(DomainException::class);
});
