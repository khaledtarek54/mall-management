<?php

declare(strict_types=1);

use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\DepreciationService;
use App\Services\DisposeFixedAssetService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * **A DISPOSAL POSTS THROUGH THE PATH AN OPERATOR ACTUALLY TAKES.**
 *
 * CLAUDE.md states the rule and the precedent: *"a GL test that calls `LedgerPoster::post()`/
 * `sync()` directly proves only the journalizer's ARITHMETIC — at least one test per source must
 * drive the real service + the sweep and assert the tie-out"*, and it names what that gap already
 * cost — applied SLA penalties cut a vendor bill while posting nothing.
 *
 * Measured across all 24 registered GL sources on 2026-09-06, `FixedAssetDisposal` was the one with
 * no such test. Its arithmetic is thoroughly proven — `FixedAssetLedgerTest` asserts every line of
 * the loss and the gain case — but through `$this->poster->post($disposal)`, a DIRECT call. The
 * only sweep-driven coverage is `CrossModuleGlScenarioTest`, which disposes an asset and then
 * asserts a balanced trial balance and zero AR/AP deltas — and **a source that posts NOTHING
 * satisfies all of that**: the trial balance stays balanced, and a disposal touches neither AR nor
 * AP by design.
 *
 * ## What this actually adds, measured rather than assumed
 *
 * The inventory gap is real; it does **not** correspond to an uncaught defect class, and saying so
 * is the point. Two mutations were run against the layers that existed before this file:
 *
 *  - **the journalizer returns a null payload** → `CrossModuleGlScenarioTest` and
 *    `ADisposedAssetIsOffTheBooksInTheRegisterTotalsTest` stay GREEN, and `FixedAssetLedgerTest`
 *    catches it (14 of 20 red). Caught, by the direct-post file.
 *  - **the source is unwired from `LedgerRealtimeSync::SOURCE_DATE_COLUMNS`** →
 *    `GlRegistryConformanceTest` catches it. Caught, by the registry gate.
 *
 * So this is defence in depth, not a hole being closed: what it pins that nothing else pins in one
 * place is the WHOLE path — the service an operator's button calls, then the command the scheduler
 * runs, then an entry existing for that source. A fault in the SERVICE that left the row in a state
 * the sweep skips is the shape that would land here first, and it is the shape the SLA-penalty
 * defect had.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
});

it('produces a ledger entry when the real service and the real sweep have run', function () {
    $fixedAsset = FixedAsset::create([
        'asset_id' => makeAsset()->id,
        'name' => 'Chiller',
        'tag' => 'FA-SWEEP-1',
        'acquisition_date' => now()->startOfYear()->toDateString(),
        'acquisition_cost' => 12000,
        'salvage_value' => 0,
        'useful_life_months' => 12,
        'method' => 'straight_line',
        'funded_from' => 'cash',
    ]);

    app(DepreciationService::class)->run(CarbonImmutable::parse($fixedAsset->acquisition_date));

    // The service an operator's Dispose button calls — never the poster.
    $disposal = app(DisposeFixedAssetService::class)->dispose($fixedAsset, [
        'disposed_on' => now()->toDateString(),
        'proceeds' => 0,
    ]);

    // The command the scheduler runs. Near-real-time posting is off in the test environment, so
    // this IS the production path for anything the hook did not catch.
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    $entry = JournalEntry::query()
        ->where('source_type', (new FixedAssetDisposal)->getMorphClass())
        ->where('source_id', $disposal->id)
        ->where('status', 'posted')
        ->first();

    // THE question the existing coverage cannot ask: a source that posts nothing keeps the trial
    // balance balanced and the AR/AP deltas at zero.
    expect($entry)->not->toBeNull('The disposal produced no posted ledger entry through the real path.');
    expect($entry->isBalanced())->toBeTrue();

    // …and it is the disposal's own write-off, not some other document's entry: the gross cost
    // leaves the balance sheet and the accumulated depreciation comes back off it.
    $accounts = app(AccountResolver::class);
    $lines = $entry->lines->keyBy('ledger_account_id');

    expect((float) $lines[$accounts->id('furniture_equipment')]->credit)->toEqualWithDelta(12000.0, 0.001);
    expect($lines->has($accounts->id('accumulated_depreciation')))->toBeTrue();
    expect($lines->has($accounts->id('loss_on_disposal')))->toBeTrue();
});
