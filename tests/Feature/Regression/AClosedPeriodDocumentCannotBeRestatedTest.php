<?php

/**
 * **A document whose entry sits in a CLOSED period may not have its money changed** —
 * `App\Support\SealedPeriod`, the other half of the sealed-period rule.
 *
 * REPRODUCED FROM PRODUCTION-SHAPED DATA, not imagined. Marketing spend #1 on the demo
 * database — 1,590.00, dated 2026-08-18, posted as `JE-0519` — with August closed at
 * month-end. Retyping the amount to 2,824.00 gave:
 *
 *     SAVE ACCEPTED.   GL says 1,590.   Document says 2,824.   wouldChange() = true, for ever.
 *
 * Every layer behaved as designed. The void-then-repost is atomic, so the BOOKS stayed
 * intact — CHANGE-IMPACT-PLAN §9 records that atomicity as correct and it is. What nothing
 * owned was the other side: the DOCUMENT had already committed. `billing:reconcile --deep`
 * then fails permanently, `books_tie_out` on `/health` is permanently red, and
 * `atriom:preflight` — which `deploy.sh` runs on every release — blocks the next deploy.
 *
 * `GuardsPostingDate` is `isDirty`-only by design and its docblock names the immutability
 * guards as the owner of this second rule. Those live on 7 of the 24 posting sources, and
 * `MarketingSpend` is not one of them: the rule had an owner in prose and none in code.
 *
 * **Every refusal here is paired with a control that must SUCCEED.** A guard that refused
 * everything would satisfy the refusals alone and read as a pass — and the three things it
 * must not block (a void, a no-op status flip, a settlement recompute) are each a workflow
 * that breaks loudly and only in production if this over-reaches.
 */

use App\Models\AccountingPeriod;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\MarketingBudget;
use App\Models\MarketingSpend;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\PeriodService;
use App\Services\VoidInvoiceService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);
    $this->asset = makeAsset();
});

/** Post everything, then seal the month through the real close service, as an operator would. */
function sealMonth(string $date): void
{
    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    app(PeriodService::class)->closePeriod(AccountingPeriod::forDate(Carbon::parse($date)));
}

function spendOn(string $date, float $amount = 1590): MarketingSpend
{
    $budget = MarketingBudget::create([
        'asset_id' => test()->asset->id,
        'period_year' => 2026,
        'accrued_amount' => 500000,
    ]);

    return MarketingSpend::create([
        'marketing_budget_id' => $budget->id,
        'spent_on' => $date,
        'amount' => $amount,
        'category' => 'event',
        'description' => 'Ramadan activation',
        'paid_from' => 'bank',
    ]);
}

it('refuses to restate a document whose entry is in a sealed period', function () {
    $spend = spendOn('2026-08-18');
    sealMonth('2026-08-18');

    // The exact act measured on the demo database.
    $spend->amount = 2824;

    expect(fn () => $spend->save())->toThrow(DomainException::class);
});

it('leaves the document and the ledger agreeing after the refusal', function () {
    // The defect itself, stated as the property that matters: not "an exception was raised"
    // but "the books and the register still say the same number". Without the guard this
    // assertion is the one that fails — 1,590 in the ledger against 2,824 on the row.
    $spend = spendOn('2026-08-18');
    sealMonth('2026-08-18');

    try {
        $spend->amount = 2824;
        $spend->save();
    } catch (DomainException) {
        // expected
    }

    $entry = JournalEntry::where('source_type', $spend->getMorphClass())
        ->where('source_id', $spend->id)->where('status', 'posted')->first();

    expect((float) $spend->fresh()->amount)->toBe(1590.0)
        ->and((float) $entry->lines->sum('debit'))->toBe(1590.0)
        ->and(app(LedgerPoster::class)->wouldChange($spend->fresh()))->toBeFalse();
});

it('still allows the change when the period is OPEN — the control', function () {
    // Pairs the refusal. A guard that refused every edit would pass the two tests above and
    // be useless; this is the everyday case it must not touch.
    $spend = spendOn('2026-08-18');
    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    $spend->amount = 2824;
    $spend->save();

    expect((float) $spend->fresh()->amount)->toBe(2824.0);

    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    $entry = JournalEntry::where('source_type', $spend->getMorphClass())
        ->where('source_id', $spend->id)->where('status', 'posted')->first();

    expect((float) $entry->lines->sum('debit'))->toBe(2824.0);
});

it('never blocks a change that REMOVES the ledger effect', function () {
    // Load-bearing, not an exception. `sync()` only VOIDS here, and a reversal falls back to
    // today's open period — so the correction succeeds. Blocking it would make a document
    // posted into a now-closed month impossible to void, which is the opposite of the intent.
    $expense = Expense::create([
        'asset_id' => $this->asset->id,
        'expense_date' => '2026-08-18',
        'category' => 'maintenance',
        'description' => 'Lift service',
        'amount' => 4000,
        'vat_amount' => 0,
        'total' => 4000,
        'status' => 'recorded',
        'paid_from' => 'bank',
    ]);

    sealMonth('2026-08-18');

    $expense->status = 'cancelled';
    $expense->save();

    expect($expense->fresh()->status)->toBe('cancelled');
});

it('never blocks a status move the ledger cannot see', function () {
    // `issued` → `paid` moves no journal line, so the payload comes back identical and
    // `sync()` no-ops. Refusing it would break receipting an invoice from a closed month —
    // and it reaches the guard, because `status` is DERIVED on Invoice.
    $invoice = makeInvoice(makeLease(makeUnit($this->asset)), ['issue_date' => '2026-08-18']);

    sealMonth('2026-08-18');

    $invoice->status = 'paid';
    $invoice->save();

    expect($invoice->fresh()->status)->toBe('paid');
});

it('never blocks voiding an invoice posted into a sealed month', function () {
    // The workflow reading of the rule above, driven through the REAL service rather than a
    // status assignment: an invoice raised in error in a month since closed must still be
    // voidable, or the only correction left is one nobody can perform.
    $invoice = makeInvoice(makeLease(makeUnit($this->asset)), ['issue_date' => '2026-08-18']);

    sealMonth('2026-08-18');

    app(VoidInvoiceService::class)->void($invoice, 'raised against the wrong lease');

    expect($invoice->fresh()->status)->toBe('cancelled');
});

it('does not charge a journalizer run for an ordinary note edit', function () {
    // The cheap pre-filter is what makes this affordable on every save of every money
    // document. A NEUTRAL field must not reach the poster at all — asserted as behaviour
    // (the save works under a sealed period) rather than by counting queries, which would
    // pin an implementation instead of the rule.
    $spend = spendOn('2026-08-18');
    sealMonth('2026-08-18');

    $spend->description = 'Ramadan activation — final invoice attached';
    $spend->save();

    expect($spend->fresh()->description)->toContain('final invoice');
});
