<?php

/**
 * Loading the operator's opening trial balance at go-live.
 *
 * Opening AR arrives through `OpeningInvoiceImporter` and opening fixed assets through
 * `FixedAssetImporter`, so the two SUB-ledgers were covered and the general ledger was not: cash,
 * bank, AP, accruals, capital and retained earnings had to be typed into the manual journal screen
 * one line at a time, from a trial balance that is routinely forty rows. A mistake in an opening
 * balance follows every report made afterwards.
 *
 * **It creates a DRAFT, and that is the load-bearing decision.** An import run twice would
 * otherwise double the whole balance sheet in silence; as drafts, two runs are two entries sitting
 * side by side — visible, comparable, deletable. Posting stays the accountant's act, through the
 * journal-entry screen, which is also where the closed-period guard lives.
 */

use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\ImportOpeningBalancesService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->svc = app(ImportOpeningBalancesService::class);

    // Three real postable accounts off the seeded chart — picked by querying rather than by
    // hard-coding codes, so a chart renumbering does not silently rewrite what this test asserts.
    $postable = LedgerAccount::query()->where('is_postable', true)->where('is_active', true)
        ->orderBy('code')->limit(3)->pluck('code')->all();

    [$this->a, $this->b, $this->c] = $postable;
});

/* ---- the control: a clean trial balance imports ------------------------- */

it('creates ONE balanced draft entry from a pasted trial balance', function () {
    $entry = $this->svc->import(
        "{$this->a}, 250000, 0\n{$this->b}, 0, 180000\n{$this->c}, 0, 70000",
        CarbonImmutable::parse('2026-08-31'),
        $this->asset->id,
    );

    expect($entry->status)->toBe('draft')          // never posted by the importer
        ->and($entry->is_manual)->toBeTrue()
        ->and($entry->asset_id)->toBe($this->asset->id)
        ->and($entry->lines)->toHaveCount(3)
        ->and(round((float) $entry->lines->sum('debit'), 2))->toBe(250000.0)
        ->and(round((float) $entry->lines->sum('credit'), 2))->toBe(250000.0);
});

it('reaches the ledger only when somebody posts it', function () {
    // The whole reason for the draft. Until an accountant acts, the books have not moved.
    $this->svc->import(
        "{$this->a}, 100, 0\n{$this->b}, 0, 100",
        CarbonImmutable::parse('2026-08-31'),
        $this->asset->id,
    );

    expect(JournalEntry::where('status', 'posted')->count())->toBe(0)
        ->and(JournalEntry::where('status', 'draft')->count())->toBe(1);
});

it('leaves two drafts rather than doubling the balance sheet when run twice', function () {
    // The catastrophe this design exists to prevent, stated as a test.
    $tb = "{$this->a}, 100, 0\n{$this->b}, 0, 100";

    $this->svc->import($tb, CarbonImmutable::parse('2026-08-31'), $this->asset->id);
    $this->svc->import($tb, CarbonImmutable::parse('2026-08-31'), $this->asset->id);

    expect(JournalEntry::where('status', 'draft')->count())->toBe(2)
        ->and(JournalEntry::where('status', 'posted')->count())->toBe(0);
});

/* ---- the parser earns its keep on real spreadsheet output --------------- */

it('accepts what Excel actually produces', function () {
    // Tabs, a header row, thousands separators and a currency suffix — the paste as it arrives,
    // not the paste after someone hand-cleans it, which is the error-prone step this removes.
    $raw = "Account\tDebit\tCredit\n{$this->a}\t1,250,000.50\t0\n{$this->b}\t0\t1,250,000.50 EGP\n\n";

    $preview = $this->svc->preview($raw);

    expect($preview['rows'])->toHaveCount(2)
        ->and($preview['debit'])->toBe(1250000.50)
        ->and($preview['credit'])->toBe(1250000.50)
        ->and($preview['balanced'])->toBeTrue();
});

/* ---- refusals, each with the control above proving they are not blanket -- */

it('refuses an unbalanced trial balance instead of posting a plug', function () {
    expect(fn () => $this->svc->import(
        "{$this->a}, 100, 0\n{$this->b}, 0, 90",
        CarbonImmutable::parse('2026-08-31'),
        $this->asset->id,
    ))->toThrow(DomainException::class);

    expect(JournalEntry::count())->toBe(0);
});

it('names every bad row at once rather than one exception at a time', function () {
    // Forty rows fixed one exception at a time is forty round trips, which is why preview()
    // collects instead of throwing.
    $summary = LedgerAccount::query()->where('is_postable', false)->value('code');

    $preview = $this->svc->preview(
        "99999999, 100, 0\n{$this->a}, 50, 50\n".($summary ? "{$summary}, 10, 0\n" : '')
    );

    expect(count($preview['errors']))->toBeGreaterThanOrEqual(2)
        ->and($preview['balanced'])->toBeFalse()
        ->and(implode(' ', $preview['errors']))->toContain('99999999');
});

it('refuses an empty paste', function () {
    expect(fn () => $this->svc->import('   ', CarbonImmutable::parse('2026-08-31'), $this->asset->id))
        ->toThrow(DomainException::class);
});

it('refuses a line that is both debit and credit', function () {
    $preview = $this->svc->preview("{$this->a}, 100, 100");

    expect($preview['errors'])->not->toBe([])
        ->and($preview['balanced'])->toBeFalse();
});

it('stamps the entry to the property it was imported under', function () {
    $other = makeAsset();

    $entry = $this->svc->import(
        "{$this->a}, 100, 0\n{$this->b}, 0, 100",
        CarbonImmutable::parse('2026-08-31'),
        $other->id,
    );

    expect($entry->asset_id)->toBe($other->id)
        ->and($entry->lines->pluck('asset_id')->unique()->all())->toBe([$other->id]);
});

it('honours CSV quoting, where a real export puts a thousands separator', function () {
    // The other half of "what Excel actually produces": a .csv export quotes any value containing
    // a comma. Splitting on every separator at once shredded this into three cells and read a
    // 1.25m opening balance as 1 — which is why the separator is now chosen per line.
    $preview = $this->svc->preview(
        "{$this->a},\"1,250,000.50\",0\n{$this->b},0,\"1,250,000.50\""
    );

    expect($preview['debit'])->toBe(1250000.50)
        ->and($preview['credit'])->toBe(1250000.50)
        ->and($preview['balanced'])->toBeTrue();
});
