<?php

use App\Models\LedgerAccount;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\ImportOpeningBalancesService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * **An opening-balance line names the account it is for** — SW-139.
 *
 * `ImportOpeningBalancesService::preview()` built each row with `$account?->name`, and
 * `ledger_accounts` has NO `name` column: the chart carries `name_en` and `name_ar`
 * (2026_06_30_000001_create_ledger_accounts_table). Eloquent answers null for an attribute that is
 * not there rather than failing, so EVERY row came back nameless — measured at HEAD (2026-09-04)
 * against the seeded chart.
 *
 * It was silent twice over, which is why it survived:
 *
 *  - the preview's `@if ($row['name'])` guard printed the bare account code, so an operator checking
 *    a forty-row paste never saw WHICH account each code is — the one thing the preview exists to
 *    confirm before an opening balance is committed, and a mistake in an opening balance follows
 *    every report made afterwards;
 *  - `import()` copies the same value onto every line of the draft entry, so all forty landed with
 *    `description => null`. The migration that made narratives a key states the cost of that in its
 *    own words: on a general ledger a missing description is indistinguishable from an entry nobody
 *    described.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->svc = app(ImportOpeningBalancesService::class);

    // Two real postable accounts off the seeded chart, found by querying rather than by hard-coded
    // codes so a chart renumbering does not silently rewrite what this asserts.
    $accounts = LedgerAccount::query()
        ->where('is_postable', true)->where('is_active', true)
        ->orderBy('code')->limit(2)->get();

    [$this->a, $this->b] = [$accounts[0], $accounts[1]];

    // The premise: the chart really does carry names, in both languages, and they differ. Without
    // this every expectation below could be satisfied by two empty strings.
    expect($this->a->name_en)->not->toBe('')
        ->and($this->a->name_ar)->not->toBe('')
        ->and($this->a->name_ar)->not->toBe($this->a->name_en);
});

it('names the account on every previewed row', function () {
    $preview = $this->svc->preview("{$this->a->code}, 250000, 0\n{$this->b->code}, 0, 250000");

    expect($preview['rows'][0]['name'])->toBe($this->a->name_en)
        ->and($preview['rows'][1]['name'])->toBe($this->b->name_en)
        ->and($preview['balanced'])->toBeTrue();
});

it('carries that name onto every line of the draft entry', function () {
    $entry = $this->svc->import(
        "{$this->a->code}, 250000, 0\n{$this->b->code}, 0, 250000",
        CarbonImmutable::parse('2026-08-31'),
        $this->asset->id,
    );

    expect($entry->lines)->toHaveCount(2)
        ->and($entry->lines->pluck('description')->all())
        ->toBe([$this->a->name_en, $this->b->name_en]);
});

it('answers in the reader\'s language', function () {
    // `displayName()` is the ONE locale-aware reading of an account's name — the picker, the report
    // filters and the posting map all take it — and it falls back to the other language rather than
    // returning null, which a half-translated imported chart needs.
    app()->setLocale('ar');

    $preview = $this->svc->preview("{$this->a->code}, 100, 0\n{$this->b->code}, 0, 100");

    expect($preview['rows'][0]['name'])->toBe($this->a->name_ar)
        ->and($preview['rows'][0]['name'])->not->toBe($this->a->name_en);
});

it('still says nothing about a code the chart does not carry', function () {
    // The control for the fix, not for the bug: an UNKNOWN code has no name and must not acquire
    // one. It is reported as an error instead, which is what the operator can act on.
    $preview = $this->svc->preview('99999999, 100, 0');

    expect($preview['rows'][0]['name'])->toBeNull()
        ->and($preview['errors'])->not->toBe([])
        ->and(implode(' ', $preview['errors']))->toContain('99999999');
});
