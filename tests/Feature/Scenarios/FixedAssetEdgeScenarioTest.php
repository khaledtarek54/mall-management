<?php

use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\LedgerAccount;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\LedgerReportService;
use App\Services\DepreciationService;
use App\Services\DisposeFixedAssetService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Edge cases for the fixed-asset register (module 23) — the negative / boundary /
 * state-transition / scoping / balanced-GL classes NOT covered by the happy-path
 * FixedAssetScenarioTest. Service/model-level assertions only.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
    $this->poster = app(LedgerPoster::class);
    $this->report = app(LedgerReportService::class);
    $this->depr = app(DepreciationService::class);
});

function edgeAsset(array $attrs = []): FixedAsset
{
    return FixedAsset::create(array_merge([
        'asset_id' => makeAsset()->id,
        'name' => 'Escalator',
        'tag' => 'FA-'.uniqid(),
        'acquisition_date' => now()->startOfYear()->toDateString(),
        'acquisition_cost' => 12000,
        'salvage_value' => 0,
        'useful_life_months' => 12,
        'method' => 'straight_line',
        'funded_from' => 'bank',
    ], $attrs));
}

function edgeClosing(string $code): float
{
    $account = LedgerAccount::where('code', $code)->first();

    return round((float) app(LedgerReportService::class)->accountLedger($account)['closing'], 2);
}

it('rejects disposing an already-disposed asset (state-transition: terminal, no double-dispose)', function () {
    $fa = edgeAsset();
    $service = app(DisposeFixedAssetService::class);

    $service->dispose($fa, [
        'disposed_on' => now()->toDateString(), 'proceeds' => 1000, 'proceeds_account' => 'bank',
    ]);
    expect($fa->fresh()->status)->toBe('disposed');

    // A second dispose on the terminal record is guarded (422) — status re-checked
    // under the lock inside the transaction.
    expect(fn () => $service->dispose($fa->fresh(), [
        'disposed_on' => now()->toDateString(), 'proceeds' => 500, 'proceeds_account' => 'bank',
    ]))->toThrow(HttpException::class);

    // Still exactly one disposal row and the status is unchanged.
    expect(FixedAssetDisposal::where('fixed_asset_id', $fa->id)->count())->toBe(1);
    expect($fa->fresh()->status)->toBe('disposed');
});

it('accrues no depreciation on a disposed asset (state-transition: disposal stops the monthly run)', function () {
    $fa = edgeAsset(['acquisition_cost' => 12000, 'useful_life_months' => 12]);

    // One month while active → one entry.
    expect($this->depr->run(CarbonImmutable::parse($fa->acquisition_date)))->toBe(1);
    expect(DepreciationEntry::where('fixed_asset_id', $fa->id)->count())->toBe(1);

    app(DisposeFixedAssetService::class)->dispose($fa, [
        'disposed_on' => now()->toDateString(), 'proceeds' => 1000, 'proceeds_account' => 'bank',
    ]);

    // The run scopes to active(); a disposed asset is skipped for the next month.
    expect($this->depr->run(CarbonImmutable::parse($fa->acquisition_date)->addMonth()))->toBe(0);
    expect(DepreciationEntry::where('fixed_asset_id', $fa->id)->count())->toBe(1);
});

it('rejects a negative-proceeds disposal (negative: tampered value would unbalance the entry)', function () {
    $fa = edgeAsset();

    expect(fn () => app(DisposeFixedAssetService::class)->dispose($fa, [
        'disposed_on' => now()->toDateString(), 'proceeds' => -500, 'proceeds_account' => 'bank',
    ]))->toThrow(HttpException::class);

    // Guard fires before any mutation — asset stays active, no disposal row written.
    expect($fa->fresh()->status)->toBe('active');
    expect(FixedAssetDisposal::where('fixed_asset_id', $fa->id)->count())->toBe(0);
});

it('stops at the salvage floor and accrues nothing further once fully depreciated (boundary)', function () {
    // Cost 10000, salvage 4000, life 6 → base 6000, 1000/mo, caps at 6000 accumulated.
    $fa = edgeAsset(['acquisition_cost' => 10000, 'salvage_value' => 4000, 'useful_life_months' => 6]);

    for ($m = 0; $m < 6; $m++) {
        $this->depr->run(CarbonImmutable::parse($fa->acquisition_date)->addMonths($m));
    }

    // Accumulated is exactly the depreciable base; NBV rests at the salvage floor.
    expect($this->depr->accumulatedFor($fa))->toBe(6000.0);
    expect($this->depr->netBookValue($fa))->toBe(4000.0);
    expect(DepreciationEntry::where('fixed_asset_id', $fa->id)->count())->toBe(6);

    // A 7th run adds nothing — remaining base is 0.
    expect($this->depr->run(CarbonImmutable::parse($fa->acquisition_date)->addMonths(6)))->toBe(0);
    expect($this->depr->accumulatedFor($fa))->toBe(6000.0);
});

it('clamps the last charge so accumulated never overshoots on an uneven split (boundary)', function () {
    // Cost 10000, life 6 → 1666.67/mo; six unclamped charges would be 10000.02, so the
    // final charge is clamped down to land accumulated exactly on the base (10000.00).
    $fa = edgeAsset(['acquisition_cost' => 10000, 'salvage_value' => 0, 'useful_life_months' => 6]);

    for ($m = 0; $m < 6; $m++) {
        $this->depr->run(CarbonImmutable::parse($fa->acquisition_date)->addMonths($m));
    }

    expect($this->depr->accumulatedFor($fa))->toBe(10000.0);
    expect($this->depr->netBookValue($fa))->toBe(0.0);
    // No single entry exceeds the plain monthly amount (the clamp only ever reduces).
    expect((float) DepreciationEntry::where('fixed_asset_id', $fa->id)->max('amount'))->toBeLessThanOrEqual(1666.67);
});

it('isolates depreciation entries and disposals per asset via the fixedAsset relation (scoping)', function () {
    $a = edgeAsset(['acquisition_cost' => 12000, 'useful_life_months' => 12]);
    $b = edgeAsset(['acquisition_cost' => 6000, 'useful_life_months' => 12]);

    // One shared portfolio-wide run charges BOTH assets one month each.
    $this->depr->run(CarbonImmutable::parse($a->acquisition_date));

    expect($a->depreciationEntries()->count())->toBe(1);
    expect($b->depreciationEntries()->count())->toBe(1);
    // Each asset's accumulated is its OWN monthly amount — no cross-bleed.
    expect($this->depr->accumulatedFor($a))->toBe(1000.0);
    expect($this->depr->accumulatedFor($b))->toBe(500.0);

    // Disposing A leaves B active and undisposed.
    app(DisposeFixedAssetService::class)->dispose($a, [
        'disposed_on' => now()->toDateString(), 'proceeds' => 1000, 'proceeds_account' => 'bank',
    ]);
    expect($a->disposal()->exists())->toBeTrue();
    expect($b->disposal()->exists())->toBeFalse();
    expect($b->fresh()->status)->toBe('active');
});

it('books a balanced disposal at a loss with the cost and accumulated fully cleared (balanced GL)', function () {
    // Cost 12000, life 12 → 1000/mo. One month depreciation → accumulated 1000, NBV 11000.
    $fa = edgeAsset(['acquisition_cost' => 12000, 'useful_life_months' => 12]);
    $this->depr->run(CarbonImmutable::parse($fa->acquisition_date));
    $this->poster->sync($fa->fresh());
    $this->poster->sync(DepreciationEntry::where('fixed_asset_id', $fa->id)->firstOrFail()->fresh());

    // Dispose for 9000 (below NBV 11000 → a 2000 loss).
    $disposal = app(DisposeFixedAssetService::class)->dispose($fa, [
        'disposed_on' => now()->toDateString(), 'proceeds' => 9000, 'proceeds_account' => 'bank',
    ]);
    $this->poster->sync($disposal->fresh());

    // Cost + accumulated wiped off the balance sheet; the loss lands on the P&L.
    expect(edgeClosing('12101001'))->toBe(0.0);   // Furniture at cost cleared
    expect(edgeClosing('12201001'))->toBe(0.0);   // Accumulated cleared
    expect(edgeClosing('52102001'))->toBe(2000.0); // Loss on disposal = NBV 11000 − proceeds 9000

    // Debits equal credits across the whole book.
    $tb = $this->report->trialBalance();
    expect($tb['balanced'])->toBeTrue();
    expect($tb['total_debit'])->toEqualWithDelta($tb['total_credit'], 0.001);
});
