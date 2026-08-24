<?php

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\SetPostMonthService;
use App\Support\PostMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Post a correctly-dated document into a different month (phase 8, story MF-05).
 *
 * **The two bad remedies this replaces.** A February vendor bill that arrives after February closes
 * cannot post — its entry date comes from the document and that period is sealed. Before this the
 * operator could re-date the document, falsifying what the vendor actually invoiced and what the
 * tenant and the ETA payload show; or leave it unposted and let the books drift from the file.
 * Yardi carries a document date AND a post month on every transaction and runs its reports on the
 * post month (02-yardi-money-flow.md).
 *
 * **One override, not 24 columns.** Atriom has 24 GL posting sources. A `post_month` column on each
 * would be 24 migrations and 24 chances to forget one — and a half-implemented post month is worse
 * than none, because an operator cannot tell which documents obey it. The override is consulted
 * once, where `LedgerPoster` builds every payload, so it works for all 24 or for none.
 *
 * **The sweep trap is the one that matters.** The override has to be applied BEFORE the sync's
 * change-detection reads `entry_date`. Applied after, the comparison would see the raw document date
 * against an already-moved entry, call it a drift, and void-and-repost the same entry every night.
 * That is what `it re-posts nothing on a second sweep` pins.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** The calendar opens the whole year; this just hands back the month's period. */
function openMonth(string $month): AccountingPeriod
{
    return AccountingPeriod::forDate(CarbonImmutable::parse($month)->startOfMonth());
}

function billDated(string $date): VendorBill
{
    $asset = makeAsset();

    return VendorBill::create([
        'vendor_id' => Vendor::create([
            'name' => 'Supplier '.fake()->unique()->numberBetween(1, 99999),
            'status' => 'active',
        ])->id,
        'asset_id' => $asset->id,
        'number' => 'SUP-'.fake()->unique()->numberBetween(1000, 99999),
        'category' => 'maintenance',
        'bill_date' => $date,
        'due_date' => $date,
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000,
        'status' => 'approved',
    ]);
}

it('posts to the document’s own month when nothing was set', function () {
    // The default, and every document written before MF-05.
    openMonth('2026-02-01');
    $bill = billDated('2026-02-20');

    $entry = app(LedgerPoster::class)->sync($bill->fresh());

    expect($entry)->not->toBeNull()
        ->and($entry->entry_date->toDateString())->toBe('2026-02-20')
        ->and(PostMonth::isOverridden($bill))->toBeFalse();
});

it('moves the entry to the post month and leaves the document’s date alone', function () {
    // THE story: the tenant and the vendor still see February; the books see March.
    openMonth('2026-02-01');
    openMonth('2026-03-01');
    $bill = billDated('2026-02-20');
    app(LedgerPoster::class)->sync($bill->fresh());

    app(SetPostMonthService::class)->set($bill, '2026-03-01', 'Bill arrived after February closed.');

    $entry = JournalEntry::where('source_type', $bill->getMorphClass())
        ->where('source_id', $bill->id)
        ->where('status', 'posted')
        ->sole();

    expect($entry->entry_date->toDateString())->toBe('2026-03-20')   // the day is kept
        ->and($bill->fresh()->bill_date->toDateString())->toBe('2026-02-20');
});

it('re-posts nothing on a second sweep', function () {
    // The trap. If the override were applied AFTER the sync's change-detection, every sweep would
    // read the raw document date, decide the entry had drifted, and void-and-repost it for ever —
    // a growing pile of voided entries that ties out but reads as chaos in the audit trail.
    openMonth('2026-02-01');
    openMonth('2026-03-01');
    $bill = billDated('2026-02-20');
    $poster = app(LedgerPoster::class);
    $poster->sync($bill->fresh());

    app(SetPostMonthService::class)->set($bill, '2026-03-01', 'Late arrival.');

    $after = JournalEntry::where('source_id', $bill->id)->where('status', 'posted')->sole()->id;

    $poster->sync($bill->fresh());
    $poster->sync($bill->fresh());

    expect(JournalEntry::where('source_id', $bill->id)->where('status', 'posted')->sole()->id)
        ->toBe($after);
});

it('reports no drift to the reconciler once the override is applied', function () {
    // The SECOND half of the sweep trap, and the half that was missing. `sync()` applied the
    // override before comparing; `wouldChange()` compared the RAW document date. So an overridden
    // document sat in a permanent standoff: `sync()` correctly left the entry alone ("unchanged"),
    // while `wouldChange()` reported drift that no sweep could ever clear.
    //
    // That is not cosmetic. `wouldChange()` is what `billing:reconcile --deep` asks, so the run
    // failed, `books_tie_out` failed on /health, and BooksDriftDetectedNotification paged the GL
    // managers — permanently, on the one mechanism built to make REAL drift visible. Found on the
    // demo data, where the single post-month override in the database was the single document the
    // reconciler flagged.
    openMonth('2026-02-01');
    openMonth('2026-03-01');
    $bill = billDated('2026-02-20');
    $poster = app(LedgerPoster::class);
    $poster->sync($bill->fresh());

    app(SetPostMonthService::class)->set($bill, '2026-03-01', 'Bill arrived after February closed.');

    // The entry really did move — otherwise this asserts agreement about a no-op.
    expect(JournalEntry::where('source_id', $bill->id)->where('status', 'posted')->sole()
        ->entry_date->toDateString())->toBe('2026-03-20')
        ->and($poster->wouldChange($bill->fresh()))->toBeFalse();

    // The control: `wouldChange()` must still SEE a real change, or this test would pass just as
    // happily against a method hard-wired to false. Moving the override behind the service's back
    // is the control that stays ON the path this fix touched — the entry is dated March while the
    // override now says April, which is exactly the drift the reconciler exists to catch.
    openMonth('2026-04-01');
    DB::table('posting_month_overrides')
        ->where('source_type', $bill->getMorphClass())
        ->where('source_id', $bill->id)
        ->update(['post_month' => '2026-04-01']);

    expect($poster->wouldChange($bill->fresh()))->toBeTrue();
});

it('clamps a day the target month does not have', function () {
    // 31 January posted to February must land on the 28th, never roll into 2 March — which would
    // put it in a period the operator did not choose, silently.
    openMonth('2026-01-01');
    openMonth('2026-02-01');
    $bill = billDated('2026-01-31');
    app(LedgerPoster::class)->sync($bill->fresh());

    app(SetPostMonthService::class)->set($bill, '2026-02-01', 'Reclassified.');

    expect(JournalEntry::where('source_id', $bill->id)->where('status', 'posted')->sole()
        ->entry_date->toDateString())->toBe('2026-02-28');
});

it('still refuses a CLOSED post month', function () {
    // The whole point is to reach an OPEN month with a correctly-dated document, never to reopen a
    // sealed one. `PostingDateGuards` is untouched.
    openMonth('2026-03-01');
    $closed = openMonth('2026-02-01');
    $closed->update(['status' => 'closed']);

    $bill = billDated('2026-03-05');
    app(LedgerPoster::class)->sync($bill->fresh());

    expect(fn () => app(SetPostMonthService::class)->set($bill, '2026-02-01', 'Belongs in February.'))
        ->toThrow(DomainException::class);

    // The control: an OPEN month is accepted, so the refusal above is about the closure and not
    // about the mechanism being broken.
    openMonth('2026-04-01');
    app(SetPostMonthService::class)->set($bill, '2026-04-01', 'Accrued late.');

    expect(PostMonth::isOverridden($bill->fresh()))->toBeTrue();
});

it('refuses a move with no stated reason', function () {
    openMonth('2026-02-01');
    openMonth('2026-03-01');
    $bill = billDated('2026-02-20');

    expect(fn () => app(SetPostMonthService::class)->set($bill, '2026-03-01', '  '))
        ->toThrow(DomainException::class);
});

it('puts the entry back on the document’s date when the override is cleared', function () {
    openMonth('2026-02-01');
    openMonth('2026-03-01');
    $bill = billDated('2026-02-20');
    app(LedgerPoster::class)->sync($bill->fresh());

    $service = app(SetPostMonthService::class);
    $service->set($bill, '2026-03-01', 'Late arrival.');
    $service->clear($bill->fresh());

    expect(JournalEntry::where('source_id', $bill->id)->where('status', 'posted')->sole()
        ->entry_date->toDateString())->toBe('2026-02-20');
});

it('refuses a post month on a document that posts nothing to the ledger', function () {
    // A setting with no effect is worse than a missing one: it reads as done. The source list is
    // DERIVED from LedgerPoster::JOURNALIZERS, so it can never disagree with what actually posts.
    $tenant = makeTenant();

    expect(fn () => app(SetPostMonthService::class)->set($tenant, '2026-03-01', 'Nonsense.'))
        ->toThrow(DomainException::class);
});
