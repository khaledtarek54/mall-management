<?php

use App\Models\MarketingBudget;
use App\Models\MarketingSpend;
use App\Models\JournalEntry;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Marketing fund — the close-out's CLEAN result, given a witness.
 *
 * The 2026-08-11 money-lens pass found nothing to fix here, and that is the finding. Recording why,
 * because "we looked and it was fine" is worth exactly as much as a fix when it is written down —
 * and worth nothing when it is not.
 *
 * Both figures on the budget are DERIVED from their sources, not accumulated:
 *   - `spent_amount` ← Σ non-trashed spends (`recomputeSpent`, fired from the spend's
 *     saved / deleted / restored hooks);
 *   - `accrued_amount` ← Σ billed `marketing` invoice lines for that property and year, excluding
 *     cancelled invoices (`recomputeAccrued`, fired from `InvoiceItem`'s hooks).
 *
 * So the pattern that produced every other finding in this batch — a stored figure that a second
 * writer can move away from its source — has no purchase here.
 *
 * **Why a posted spend is deliberately NOT frozen**, unlike the disposed asset, the settled عهدة, the
 * approved payroll and the drawn-on deposit. Those freezes exist because editing them left a WRONG
 * number on the books: a restated disposal, a negative outstanding, a header no payslip supported.
 * Editing a marketing spend does not. Its journal entry re-derives from the row, the posting-date
 * guard already refuses a move into a closed period, and the budget re-derives through the same
 * hooks — the end state is correct. Freezing it would buy nothing and cost the operator the ordinary
 * correction; `DeletionPolicy` classifies it `ALLOWED` ("operational: a spend line") and the
 * soft-delete voids its entry through the sweep, which is the reversal path.
 *
 * These tests pin that reasoning. If a future change makes an edit diverge — a cached total, a
 * frozen figure copied somewhere — one of them fails, and the "no freeze needed" decision is
 * revisited rather than inherited.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset(['code' => 'MKT1']);
    $this->budget = MarketingBudget::forPeriod($this->asset->id, 2026);

    $this->spend = MarketingSpend::create([
        'marketing_budget_id' => $this->budget->id,
        'description' => 'Ramadan campaign — mall dressing',
        'amount' => 40000,
        'spent_on' => '2026-06-01',
        'paid_from' => 'bank',
    ]);
});

it('derives spent_amount from the spends, so an edit cannot leave it stale', function () {
    expect(round((float) $this->budget->fresh()->spent_amount, 2))->toBe(40000.0);

    $this->spend->update(['amount' => 25000]);

    expect(round((float) $this->budget->fresh()->spent_amount, 2))->toBe(25000.0);
});

it('returns the money to the fund when a spend is deleted, and takes it back on restore', function () {
    // The reversal path DeletionPolicy names, which is why no freeze is needed.
    $this->spend->delete();
    expect(round((float) $this->budget->fresh()->spent_amount, 2))->toBe(0.0);

    $this->spend->restore();
    expect(round((float) $this->budget->fresh()->spent_amount, 2))->toBe(40000.0);
});

it('re-derives the ledger when a posted spend is edited, rather than leaving the old figure', function () {
    // The claim that makes the no-freeze decision safe: the books follow the row.
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    $entry = JournalEntry::where('source_type', MarketingSpend::class)
        ->where('source_id', $this->spend->id)->whereNull('voided_at')->first();

    expect($entry)->not->toBeNull()
        ->and(round((float) $entry->lines->sum('debit'), 2))->toBe(40000.0);

    $this->spend->update(['amount' => 25000]);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    $live = JournalEntry::where('source_type', MarketingSpend::class)
        ->where('source_id', $this->spend->id)->whereNull('voided_at')->get();

    // Exactly one live entry, at the corrected figure — not two, and not the stale 40,000.
    expect($live)->toHaveCount(1)
        ->and(round((float) $live->first()->lines->sum('debit'), 2))->toBe(25000.0);
});

it('voids the entry when the spend is deleted', function () {
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    $this->spend->delete();
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    expect(JournalEntry::where('source_type', MarketingSpend::class)
        ->where('source_id', $this->spend->id)
        ->whereNull('voided_at')
        ->count())->toBe(0);
});
