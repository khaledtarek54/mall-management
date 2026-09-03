<?php

use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Support\PostingRoleExposure;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * **Re-pointing a posting role silently restates history, and nothing said so** (SW-134, piece a).
 *
 * Accounts are resolved at PAYLOAD time and never frozen onto the entry: every journalizer asks
 * `AccountResolver::account()`, which reads `account_mappings` live, and `LedgerPoster::matches()`
 * includes `ledger_account_id` in its line signature. So changing one row means the next
 * `accounting:sync-ledger` sweep finds every historical document's entry no longer matching, voids
 * it, and re-posts it against the new account — up to a week later, with nobody having confirmed it.
 *
 * Nothing gated it. `AccountMapping::booted()` guards duplicates and the deletion of a global
 * default; `SealedPeriod::guard()` returns immediately because `AccountMapping` is not a GL SOURCE;
 * `ChangeImpact::POLICY` classifies the columns of sources, not of a configuration table. Measured
 * on the QA baseline: **487 posted lines on `accounts_receivable`**.
 *
 * **The open/closed split is the substance.** An open-period entry is re-derived and the books stay
 * coherent. A closed-period one CANNOT be — the re-post is refused, so the entry keeps the old
 * account while the mapping says otherwise, and `billing:reconcile --deep` reports drift for ever,
 * turning `atriom:preflight` permanently red and blocking the next deploy for an unrelated reason.
 *
 * This WARNS rather than refuses, deliberately: whether a mapping change should be PROSPECTIVE is an
 * accounting decision nobody has taken (Yardi's answer is that it is), and refusing on the operator's
 * behalf would be taking it for them. The other two pieces stay open in the sweep document.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset(['code' => 'RPT']);
    $this->account = LedgerAccount::query()->where('is_postable', true)->firstOrFail();

    // The seeders lay down a chart and a posting map but no fiscal year, and `accounting_periods`
    // requires one.
    $this->periodNo = 0;
    // The other side of every probe entry — an entry has to balance to post.
    $this->contra = LedgerAccount::query()->where('is_postable', true)
        ->whereKeyNot($this->account->id)->firstOrFail();
    $this->year = FiscalYear::query()->firstOr(fn () => FiscalYear::create([
        'year' => 2026, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open',
    ]));
});

/** A posted entry in `$status` period, with one line on the fixture account. */
function postedLineIn(string $periodStatus): JournalLine
{
    test()->periodNo++;

    $period = AccountingPeriod::create([
        'fiscal_year_id' => test()->year->id,
        'period_no' => test()->periodNo,
        'starts_on' => '2026-'.str_pad((string) test()->periodNo, 2, '0', STR_PAD_LEFT).'-01',
        'ends_on' => '2026-'.str_pad((string) test()->periodNo, 2, '0', STR_PAD_LEFT).'-28',
        'status' => $periodStatus,
    ]);

    $entry = JournalEntry::create([
        'asset_id' => test()->asset->id,
        'accounting_period_id' => $period->id,
        'number' => 'JE-PROBE-'.test()->periodNo,
        'entry_date' => $period->starts_on,
        'is_manual' => false,
        'is_closing' => false,
        // DRAFT while the lines are written: a posted entry refuses a line change, because debits
        // would stop equalling credits. Posted after, the way the poster does it.
        'status' => 'draft',
        'description_en' => 'Probe', 'description_ar' => 'Probe',
    ]);

    $line = JournalLine::create([
        'journal_entry_id' => $entry->id,
        'ledger_account_id' => test()->account->id,
        'debit' => 1000, 'credit' => 0,
    ]);

    JournalLine::create([
        'journal_entry_id' => $entry->id,
        'ledger_account_id' => test()->contra->id,
        'debit' => 0, 'credit' => 1000,
    ]);

    $entry->update(['status' => 'posted']);

    return $line->fresh();
}

it('says nothing about an account nothing has posted to', function () {
    // Null, not "0 documents": a warning shown on an unused mapping is one trained away before the
    // one that matters.
    expect(PostingRoleExposure::warningFor($this->account->id))->toBeNull()
        ->and(PostingRoleExposure::on($this->account->id))->toBe(['total' => 0, 'open' => 0, 'closed' => 0]);
});

it('counts what is posted, and calls an open period re-derivable', function () {
    postedLineIn('open');
    postedLineIn('open');

    expect(PostingRoleExposure::on($this->account->id))
        ->toBe(['total' => 2, 'open' => 2, 'closed' => 0])
        ->and(PostingRoleExposure::warningFor($this->account->id))
        ->toBe(__('admin.helpers.posting_role_repoint_open', ['total' => 2, 'open' => 2, 'closed' => 0]));
});

it('separates the lines that can never be re-derived', function () {
    // The half that matters: a closed-period entry keeps the old account for ever and reports as
    // permanent drift.
    postedLineIn('open');
    postedLineIn('closed');

    expect(PostingRoleExposure::on($this->account->id))
        ->toBe(['total' => 2, 'open' => 1, 'closed' => 1]);

    expect(PostingRoleExposure::warningFor($this->account->id))
        ->toBe(__('admin.helpers.posting_role_repoint_closed', ['total' => 2, 'open' => 1, 'closed' => 1]));
});

it('counts a VOIDED entry as history too', function () {
    // `REPORTABLE_STATUSES`, not `posted` alone. A void and the entry that replaced it are both real
    // history, and counting one without the other is how a negative figure got quoted on this
    // project once already.
    $line = postedLineIn('open');
    $line->entry->update(['status' => 'void']);

    expect(PostingRoleExposure::on($this->account->id)['total'])->toBe(1);
});

it('ignores an account nobody asked about', function () {
    // The control: the count is per account, not per ledger.
    postedLineIn('open');
    // A THIRD account: `$this->contra` carries the balancing credit of every probe entry, so it is
    // not an untouched one.
    $other = LedgerAccount::query()->where('is_postable', true)
        ->whereKeyNot($this->account->id)->whereKeyNot($this->contra->id)->firstOrFail();

    expect(PostingRoleExposure::on($other->id)['total'])->toBe(0)
        ->and(PostingRoleExposure::warningFor($other->id))->toBeNull();
});

it('reads the warning in the operator’s language', function () {
    postedLineIn('closed');

    $en = PostingRoleExposure::warningFor($this->account->id);
    app()->setLocale('ar');
    $ar = PostingRoleExposure::warningFor($this->account->id);
    app()->setLocale('en');

    expect($ar)->not->toBe($en)
        ->and($ar)->toMatch('/\p{Arabic}/u');
});
