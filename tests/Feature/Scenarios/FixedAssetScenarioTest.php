<?php

use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use App\Models\LedgerAccount;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\LedgerReportService;
use App\Services\DepreciationService;
use App\Services\DisposeFixedAssetService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * End-to-end fixed-asset lifecycle: acquire → depreciate → dispose → the whole GL
 * footprint ties out and the trial balance stays balanced throughout.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
    $this->poster = app(LedgerPoster::class);
    $this->report = app(LedgerReportService::class);
    $this->depr = app(DepreciationService::class);
});

function scenarioAsset(array $attrs = []): FixedAsset
{
    return FixedAsset::create(array_merge([
        'asset_id' => makeAsset()->id,
        'name' => 'Rooftop Chiller',
        'tag' => 'FA-'.uniqid(),
        'acquisition_date' => now()->startOfYear()->toDateString(),
        'acquisition_cost' => 12000,
        'salvage_value' => 0,
        'useful_life_months' => 12,
        'method' => 'straight_line',
        'funded_from' => 'bank',
    ], $attrs));
}

function closing(string $code): float
{
    $account = LedgerAccount::where('code', $code)->first();

    return round((float) app(LedgerReportService::class)->accountLedger($account)['closing'], 2);
}

it('runs the full acquire → depreciate-to-salvage → dispose lifecycle with a balanced GL', function () {
    // Cost 12000, salvage 2000, life 10 → 1000/mo, accumulated caps at 10000.
    $fa = scenarioAsset(['acquisition_cost' => 12000, 'salvage_value' => 2000, 'useful_life_months' => 10]);

    // Depreciate all 10 months (Jan..Oct of the current year).
    for ($m = 0; $m < 10; $m++) {
        $this->depr->run(CarbonImmutable::parse($fa->acquisition_date)->addMonths($m));
    }
    // An 11th run must add nothing — fully depreciated to salvage.
    expect($this->depr->run(CarbonImmutable::parse($fa->acquisition_date)->addMonths(10)))->toBe(0);

    expect(DepreciationEntry::where('fixed_asset_id', $fa->id)->count())->toBe(10);
    expect($this->depr->accumulatedFor($fa))->toBe(10000.0);
    expect($this->depr->netBookValue($fa))->toBe(2000.0); // = salvage

    // Sync the acquisition + all depreciation to the GL.
    $this->poster->sync($fa->fresh());
    DepreciationEntry::where('fixed_asset_id', $fa->id)->get()->each(fn ($c) => $this->poster->sync($c->fresh()));

    expect(closing('12101001'))->toBe(12000.0);   // Furniture at cost
    expect(closing('12201001'))->toBe(-10000.0);  // Accumulated (contra-asset, credit)
    expect(closing('51107001'))->toBe(10000.0);   // Depreciation expense on the P&L

    // Dispose for 1500 (below NBV 2000 → a 500 loss).
    $disposal = app(DisposeFixedAssetService::class)->dispose($fa, [
        'disposed_on' => now()->toDateString(), 'proceeds' => 1500, 'proceeds_account' => 'bank',
    ]);
    $this->poster->sync($disposal->fresh());

    // The asset is removed from the balance sheet; the loss lands on the P&L.
    expect(closing('12101001'))->toBe(0.0);   // Furniture cleared
    expect(closing('12201001'))->toBe(0.0);   // Accumulated cleared
    expect(closing('52102001'))->toBe(500.0); // Loss on disposal (NBV 2000 − proceeds 1500)

    // The trial balance ties out through the whole lifecycle.
    $tb = $this->report->trialBalance();
    expect($tb['balanced'])->toBeTrue();
    expect($tb['total_debit'])->toEqualWithDelta($tb['total_credit'], 0.001);
});

it('recognises a gain when disposed above net book value', function () {
    $fa = scenarioAsset(['acquisition_cost' => 12000, 'useful_life_months' => 12]);
    $this->depr->run(CarbonImmutable::parse($fa->acquisition_date)); // accumulated 1000, NBV 11000
    $this->poster->sync($fa->fresh());
    $this->poster->sync(DepreciationEntry::where('fixed_asset_id', $fa->id)->firstOrFail()->fresh());

    $disposal = app(DisposeFixedAssetService::class)->dispose($fa, [
        'disposed_on' => now()->toDateString(), 'proceeds' => 13000, 'proceeds_account' => 'bank',
    ]);
    $this->poster->sync($disposal->fresh());

    // Gain-on-disposal is a revenue account (credit-normal), so its closing is POSITIVE:
    // proceeds 13000 − NBV 11000 = 2000 gain recognised on the P&L.
    expect(closing('42102001'))->toBe(2000.0);
    expect(closing('12101001'))->toBe(0.0);
    $tb = $this->report->trialBalance();
    expect($tb['balanced'])->toBeTrue();
});

it('soft-deleting a mid-life asset voids its entire GL footprint via the windowed sweep', function () {
    $fa = scenarioAsset(['acquisition_cost' => 12000, 'useful_life_months' => 12]);
    $this->depr->run(CarbonImmutable::parse($fa->acquisition_date));
    $charge = DepreciationEntry::where('fixed_asset_id', $fa->id)->firstOrFail();
    $this->poster->sync($fa->fresh());
    $this->poster->sync($charge->fresh());

    // Age the charge outside the sweep window; the cascade must re-touch it.
    \Illuminate\Support\Facades\DB::table('depreciation_entries')->where('id', $charge->id)->update(['updated_at' => now()->subDays(30)]);

    $fa->delete();
    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    // No phantom left anywhere.
    foreach (['12101001', '11102001', '51107001', '12201001'] as $code) {
        expect(closing($code))->toBe(0.0);
    }
    $tb = $this->report->trialBalance();
    expect($tb['total_debit'])->toEqualWithDelta($tb['total_credit'], 0.001);
});
