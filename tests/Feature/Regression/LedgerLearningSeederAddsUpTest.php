<?php

/*
|--------------------------------------------------------------------------
| The teaching books must add up to what they claim (2026-08-25)
|--------------------------------------------------------------------------
| `LedgerLearningSeeder` exists so someone can read a trial balance and verify it by hand. Its
| docblock states the six lines and the 13,700 total — so the docblock is a promise, and a promise
| about numbers that nothing checks is exactly how a teaching dataset drifts into teaching something
| false. This test IS the check: it seeds the books and adds them up.
|
| The credit note is the row that earns the fixture. It credits receivables in full on ISSUE and is
| only half applied, so the tenant ledger and the AR control account disagree by 1,000 and both are
| right. A learner's dataset without one teaches that AR always equals the invoice list.
*/

use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use Database\Seeders\LedgerLearningSeeder;
use Illuminate\Support\Facades\DB;

/** Posted balance of the account behind a posting role, in its own normal direction. */
function roleBalance(string $role): float
{
    $id = DB::table('account_mappings')->where('key', $role)->value('ledger_account_id');
    expect($id)->not->toBeNull("role {$role} is not mapped");

    $account = LedgerAccount::find($id);
    $row = DB::table('journal_lines as jl')
        ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
        ->where('je.status', 'posted')->where('jl.ledger_account_id', $id)
        ->selectRaw('COALESCE(SUM(jl.debit),0) d, COALESCE(SUM(jl.credit),0) c')->first();

    return round($account->normal_balance === 'debit' ? $row->d - $row->c : $row->c - $row->d, 2);
}

beforeEach(function () {
    $this->seed(LedgerLearningSeeder::class);
});

it('balances at exactly 15,700 on each side', function () {
    // A TRIAL BALANCE is each account's NET balance in its own column — not the sum of every debit
    // line. My first version summed the lines, got 30,700 (gross movement, which counts the same
    // money on the way in and on the way out) and reported the fixture as broken. The distinction
    // is the first thing a trial balance teaches, so this test had better model it correctly.
    $net = DB::table('journal_lines as jl')
        ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
        ->where('je.status', 'posted')
        ->groupBy('jl.ledger_account_id')
        ->selectRaw('jl.ledger_account_id, SUM(jl.debit) - SUM(jl.credit) net')
        ->pluck('net');

    $debitColumn = round((float) $net->filter(fn ($n) => $n > 0)->sum(), 2);
    $creditColumn = round((float) abs($net->filter(fn ($n) => $n < 0)->sum()), 2);

    expect($debitColumn)->toBe(15700.0)->and($creditColumn)->toBe(15700.0);
});

it('puts each figure in the account the docblock names', function () {
    expect(roleBalance('bank'))->toBe(7000.0)                  // 10,000 in − 3,000 out
        ->and(roleBalance('accounts_receivable'))->toBe(3700.0) // 10,000 + 5,700 − 10,000 − 2,000
        ->and(roleBalance('rent_revenue'))->toBe(10000.0)
        // NOT 3,000. A credit note debits CONTRA-REVENUE and leaves the earned figure alone, so the
        // books keep saying the service charge earned 5,000 and separately say 2,000 came back. A
        // netted figure could never answer "how much did we credit back this year".
        ->and(roleBalance('service_charge_revenue'))->toBe(5000.0)
        // NEGATIVE in its own normal direction, and that is what "contra" means: the account is
        // classed as revenue (so it sits with revenue on the income statement) and carries a DEBIT
        // balance. Asserting +2,000 here would be asserting that credits given back are income.
        ->and(roleBalance('sales_returns'))->toBe(-2000.0)
        ->and(roleBalance('vat_payable'))->toBe(700.0);
});

it('leaves a real reconciling item — the tenant ledger and the ledger DISAGREE, correctly', function () {
    $invoiceBalances = round((float) Invoice::whereNotIn('status', ['draft', 'cancelled'])->sum('balance'), 2);
    $unapplied = round((float) DB::table('credit_notes')->whereIn('status', ['issued', 'applied'])->sum('balance'), 2);

    // The whole reason this document is in the fixture: 4,700 owed on the invoices, 1,000 of credit
    // standing, 3,700 in the control account. Anyone who can follow this can close a month.
    expect($invoiceBalances)->toBe(4700.0)
        ->and($unapplied)->toBe(1000.0)
        ->and(round($invoiceBalances - $unapplied, 2))->toBe(roleBalance('accounts_receivable'));
});

it('posts through the real path, so the entries can drift and self-heal', function () {
    // Written journal rows would look identical here and teach a ledger that cannot drift. The
    // proof is that every entry names the document it came from.
    $entries = JournalEntry::where('status', 'posted')->get();

    expect($entries)->toHaveCount(5)
        ->and($entries->whereNull('source_type'))->toHaveCount(0);
});

it('stays small enough to read', function () {
    // The property under test is legibility. If a later edit grows this to twenty documents it has
    // stopped being a teaching set and become a second DemoSeeder.
    expect(JournalEntry::count())->toBeLessThanOrEqual(6)
        ->and(DB::table('journal_lines')->count())->toBeLessThanOrEqual(14);
});
