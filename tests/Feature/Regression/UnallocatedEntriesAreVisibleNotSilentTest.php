<?php

use App\Models\Asset;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\Accounting\LedgerReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * EG-27, the half that is unambiguous — a journal entry filed against no property was invisible in
 * every financial statement, and nothing said so (finding S-3).
 *
 * `aggregate()` and `accountLedger()` both scope with `whereIn('je.asset_id', $ids)`, and `whereIn`
 * never matches NULL. The year-end close already knew better: `plByAssetAndAccount()` buckets those
 * rows under `asset_id => null` precisely *"so no P&L is ever stranded"*. The close and the reports
 * disagreed, and the reports were the ones an operator reads.
 *
 * **They are surfaced, not folded in, and that is the decision the operator took.** A null
 * `asset_id` on a money document is portfolio-level overhead visible from every mall
 * (`#[PropertyOwned(portfolioRowsWhenNull: true)]`), so absorbing it into each property's statement
 * would show one operator-wide insurance bill in full on all three malls and none of their figures
 * would be right. `atriom:audit-property-dimension` is what finds and fixes it; this makes the
 * statement admit it exists.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);

    $this->mall = makeAsset(['code' => 'MALL-U1']);
    $this->service = app(LedgerReportService::class);

    $this->debit = LedgerAccount::where('type', 'expense')->where('is_postable', true)->firstOrFail();
    $this->credit = LedgerAccount::where('type', 'liability')->where('is_postable', true)->firstOrFail();
});

/** A posted entry for `$asset` (null = filed against no property). */
function postedEntry(?Asset $asset, string $on, float $amount): JournalEntry
{
    // Draft FIRST, then post. `JournalLine` refuses a line on a posted entry — correctly: debits
    // would stop equalling credits and every report built on the trial balance would be wrong.
    $entry = JournalEntry::create([
        'number' => 'JE-'.uniqid(),
        'entry_date' => $on,
        'status' => 'draft',
        'is_manual' => true,
        'asset_id' => $asset?->id,
    ]);

    $entry->lines()->create(['ledger_account_id' => test()->debit->id, 'debit' => $amount, 'credit' => 0]);
    $entry->lines()->create(['ledger_account_id' => test()->credit->id, 'debit' => 0, 'credit' => $amount]);

    $entry->update(['status' => 'posted']);

    return $entry->fresh();
}

it('says nothing when every entry in the period has a property', function () {
    // The control. A notice that appeared on clean books would be trained away in a week, and then
    // it would be there for the one period that mattered and nobody would read it.
    postedEntry($this->mall, '2026-03-10', 5000);

    expect($this->service->unallocated([$this->mall->id], CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')))
        ->toBeNull();
});

it('reports the money a property-scoped statement is leaving out', function () {
    postedEntry($this->mall, '2026-03-10', 5000);
    postedEntry(null, '2026-03-12', 84300);
    postedEntry(null, '2026-03-20', 1200);

    $notice = $this->service->unallocated([$this->mall->id], CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

    expect($notice)->toBe(['count' => 2, 'total' => 85500.0]);
});

it('sizes an entry by its debits, not by both sides', function () {
    // An entry balances, so summing debit AND credit doubles every figure — a notice reading
    // 169,000 against 84,500 of real exposure is a worse number than no notice.
    postedEntry(null, '2026-03-12', 84500);

    expect($this->service->unallocated([$this->mall->id], CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')))
        ->toBe(['count' => 1, 'total' => 84500.0]);
});

it('stays silent on an unscoped read, because nothing is being excluded', function () {
    // A consolidated read has no `whereIn`, so the entries ARE in the figures. Warning there would
    // tell the operator something is missing from a statement that contains it.
    postedEntry(null, '2026-03-12', 84300);

    expect($this->service->unallocated(null, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')))
        ->toBeNull();
});

it('honours the period, so last year is not reported against this month', function () {
    postedEntry(null, '2025-11-02', 9000);

    expect($this->service->unallocated([$this->mall->id], CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')))
        ->toBeNull()
        // …and an "as at" read with no lower bound, which is what the balance sheet passes, does
        // see it.
        ->and($this->service->unallocated([$this->mall->id], null, CarbonImmutable::parse('2026-03-31')))
        ->toBe(['count' => 1, 'total' => 9000.0]);
});

it('leaves the statement figures themselves untouched', function () {
    // The whole point of surfacing rather than absorbing: the property's numbers must not move.
    postedEntry($this->mall, '2026-03-10', 5000);

    $before = $this->service->incomeStatement([$this->mall->id], CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

    postedEntry(null, '2026-03-12', 84300);

    $after = $this->service->incomeStatement([$this->mall->id], CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

    expect($after)->toEqual($before);
});
