<?php

/**
 * Egyptian income-tax depreciation (Law 91/2005, Art. 25) — the second BASIS, not a second ledger.
 *
 * The accounting book depreciates straight-line over a chosen useful life and posts to the GL. Tax
 * is a different calculation on the same assets: statutory rates, and for most of them a POOLED
 * diminishing-value base. Until this existed no tax-basis figure could be produced at all, so a
 * corporate return could not be prepared from the register however complete it was.
 *
 * Nothing here posts — Egypt files single-book, so the tax figure is a computation attached to the
 * return and the difference from the book figure is the temporary difference. These tests are about
 * the arithmetic, because a tax basis that quietly disagrees with the register does not look broken:
 * it gets filed.
 */

use App\Models\FixedAsset;
use App\Services\Accounting\TaxDepreciationService;
use App\Support\TaxDepreciation;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->svc = app(TaxDepreciationService::class);
});

function taxAsset(array $attrs = []): FixedAsset
{
    return FixedAsset::create(array_merge([
        'asset_id' => test()->asset->id,
        'name' => 'Thing',
        'tag' => 'FA-'.bin2hex(random_bytes(4)),
        'acquisition_date' => '2024-01-01',
        'acquisition_cost' => 100000,
        'salvage_value' => 0,
        'useful_life_months' => 60,
        'method' => 'straight_line',
        'status' => 'active',
        'tax_pool' => TaxDepreciation::GENERAL,
    ], $attrs));
}

/** The row for one pool in a given year. */
function poolRow(array $schedule, string $pool): ?array
{
    foreach ($schedule['pools'] as $row) {
        if ($row['pool'] === $pool) {
            return $row;
        }
    }

    return null;
}

/* ---- pooled diminishing value ------------------------------------------- */

it('applies 25% to the whole pool in the year of acquisition', function () {
    taxAsset(['acquisition_cost' => 100000]);

    $row = poolRow($this->svc->schedule(2024, [$this->asset->id]), TaxDepreciation::GENERAL);

    expect($row['rate'])->toBe(25.0)
        ->and($row['pooled'])->toBeTrue()
        ->and($row['base'])->toBe(100000.0)
        ->and($row['depreciation'])->toBe(25000.0)
        ->and($row['closing'])->toBe(75000.0);
});

it('rolls the written-down value forward — the point of diminishing value', function () {
    // 100,000 → 25,000 → 18,750 → 14,062.50. Straight line would charge the same every year; this
    // is the difference in KIND that makes a tax schedule not just a re-rated book schedule.
    taxAsset(['acquisition_cost' => 100000]);

    expect(poolRow($this->svc->schedule(2025, [$this->asset->id]), TaxDepreciation::GENERAL)['depreciation'])
        ->toBe(18750.0)
        ->and(poolRow($this->svc->schedule(2026, [$this->asset->id]), TaxDepreciation::GENERAL)['depreciation'])
        ->toBe(14062.50);
});

it('adds a later acquisition to the existing pool rather than tracking it alone', function () {
    // The defining property of a pool: the second asset does not get its own opening balance, it
    // joins the first one's written-down value and the rate applies to the total.
    taxAsset(['acquisition_cost' => 100000, 'acquisition_date' => '2024-01-01']);
    taxAsset(['acquisition_cost' => 40000, 'acquisition_date' => '2025-06-01']);

    $row = poolRow($this->svc->schedule(2025, [$this->asset->id]), TaxDepreciation::GENERAL);

    // opening 75,000 + additions 40,000 = 115,000 base; 25% = 28,750.
    expect($row['opening'])->toBe(75000.0)
        ->and($row['additions'])->toBe(40000.0)
        ->and($row['base'])->toBe(115000.0)
        ->and($row['depreciation'])->toBe(28750.0);
});

it('removes a disposal from the pool at COST, not at book value', function () {
    // Using NBV here would leave a permanent residue in the pool that depreciates forever.
    taxAsset(['acquisition_cost' => 100000, 'acquisition_date' => '2024-01-01']);
    taxAsset(['acquisition_cost' => 40000, 'acquisition_date' => '2024-01-01', 'disposed_on' => '2025-03-01']);

    $row = poolRow($this->svc->schedule(2025, [$this->asset->id]), TaxDepreciation::GENERAL);

    // 2024: base 140,000, 25% = 35,000, closing 105,000. 2025: 105,000 − 40,000 cost = 65,000.
    expect($row['opening'])->toBe(105000.0)
        ->and($row['disposals'])->toBe(40000.0)
        ->and($row['base'])->toBe(65000.0);
});

it('uses 50% for computers and information systems', function () {
    taxAsset(['acquisition_cost' => 60000, 'tax_pool' => TaxDepreciation::COMPUTERS]);

    $row = poolRow($this->svc->schedule(2024, [$this->asset->id]), TaxDepreciation::COMPUTERS);

    expect($row['rate'])->toBe(50.0)->and($row['depreciation'])->toBe(30000.0);
});

/* ---- straight-line classes ---------------------------------------------- */

it('charges buildings 5% of COST, not of the written-down value', function () {
    taxAsset(['acquisition_cost' => 1000000, 'tax_pool' => TaxDepreciation::BUILDINGS]);

    $y1 = poolRow($this->svc->schedule(2024, [$this->asset->id]), TaxDepreciation::BUILDINGS);
    $y2 = poolRow($this->svc->schedule(2025, [$this->asset->id]), TaxDepreciation::BUILDINGS);

    expect($y1['pooled'])->toBeFalse()
        ->and($y1['depreciation'])->toBe(50000.0)
        // Same amount again — that is what straight-line on cost means, and the control that
        // proves this class is NOT being run through the pooled path.
        ->and($y2['depreciation'])->toBe(50000.0);
});

it('stops once a straight-line asset is fully relieved', function () {
    // Twenty years at 5% exhausts a building; the twenty-first must charge nothing rather than
    // carry on into negative written-down value.
    taxAsset(['acquisition_cost' => 1000000, 'tax_pool' => TaxDepreciation::BUILDINGS, 'acquisition_date' => '2000-01-01']);

    $row = poolRow($this->svc->schedule(2025, [$this->asset->id]), TaxDepreciation::BUILDINGS);

    expect($row['depreciation'])->toBe(0.0)->and($row['closing'])->toBe(0.0);
});

/* ---- what is excluded, and what the accountant actually reads ----------- */

it('leaves land out of the schedule entirely', function () {
    taxAsset(['acquisition_cost' => 500000, 'tax_pool' => TaxDepreciation::NONE]);

    expect(poolRow($this->svc->schedule(2024, [$this->asset->id]), TaxDepreciation::NONE))->toBeNull()
        ->and($this->svc->schedule(2024, [$this->asset->id])['tax_total'])->toBe(0.0);
});

it('reports the difference from the accounting book — the temporary difference', function () {
    // The number this whole feature exists to produce. Tax relieves 25,000 in year one; the book
    // charges twelve monthly instalments. The gap is what defers.
    //
    // The book figure is 20,000.04, not 20,000 — and that is CORRECT, not a rounding bug to smooth
    // over. 100,000 / 60 is 1,666.6667, the posting run charges the ROUNDED 1,666.67 every month,
    // and twelve of those really are 20,000.04. Reporting a tidier 20,000 here would make the
    // schedule disagree with the ledger it is being compared against, which is the one thing a
    // book-versus-tax comparison must never do.
    taxAsset(['acquisition_cost' => 100000, 'useful_life_months' => 60]);

    $schedule = $this->svc->schedule(2024, [$this->asset->id]);

    expect($schedule['tax_total'])->toBe(25000.0)
        ->and($schedule['book_total'])->toBe(20000.04)
        ->and($schedule['difference'])->toBe(4999.96);
});

it('scopes to the properties asked for', function () {
    $other = makeAsset();
    taxAsset(['acquisition_cost' => 100000]);
    FixedAsset::create([
        'asset_id' => $other->id, 'name' => 'Other', 'tag' => 'FA-OTHER',
        'acquisition_date' => '2024-01-01',
        'acquisition_cost' => 800000, 'salvage_value' => 0, 'useful_life_months' => 60,
        'method' => 'straight_line', 'status' => 'active', 'tax_pool' => TaxDepreciation::GENERAL,
    ]);

    expect($this->svc->schedule(2024, [$this->asset->id])['tax_total'])->toBe(25000.0)
        ->and($this->svc->schedule(2024, [$other->id])['tax_total'])->toBe(200000.0);
});

it('treats an unclassified asset as the law does — general, never dropped', function () {
    // `general` is the statute's own residual category. Silently omitting an unclassified asset
    // would understate the relief and nobody would see the omission.
    taxAsset(['acquisition_cost' => 100000, 'tax_pool' => null]);

    expect(poolRow($this->svc->schedule(2024, [$this->asset->id]), TaxDepreciation::GENERAL)['depreciation'])
        ->toBe(25000.0);
});
