<?php

use App\Models\Asset;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\MarketingBudget;
use App\Services\MarketingLevyService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| marketing:ensure-budgets  +  marketing:backfill-budgets
|
| ensure-budgets   — auto-provisions ONE marketing budget per real property
|                    for a year (firstOrCreate). Safe to schedule; idempotent.
| backfill-budgets — seeds a marketing levy Charge on every active, rent-bearing
|                    lease lacking one, then re-derives accrued/spent on every
|                    budget. Safe to re-run.
|--------------------------------------------------------------------------
*/

// A billable lease carrying a base-rent Charge — the shape a real lease has
// once LeaseCreationService seeds it (mirrors the Marketing scenario helper).
function budgetCmdLeaseWithRent(float $rent, array $leaseAttrs = []): Lease
{
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit, null, array_merge(
        ['base_rent_monthly' => $rent, 'commencement_date' => '2026-01-01'],
        $leaseAttrs,
    ));

    Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Base Rent',
        'type' => 'base_rent',
        'amount' => $rent,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => false,
        'vat_rate' => 0,
        'start_date' => $lease->commencement_date,
        'is_active' => true,
    ]);

    return $lease;
}

/*
| ---- marketing:ensure-budgets ------------------------------------------ |
*/

it('marketing:ensure-budgets provisions exactly one budget per real property for the year', function () {
    $a1 = makeAsset();
    $a2 = makeAsset();
    $a3 = makeAsset();

    expect(MarketingBudget::where('period_year', 2026)->count())->toBe(0);

    $this->artisan('marketing:ensure-budgets', ['--year' => 2026])
        ->expectsOutputToContain('Ensured marketing budgets for 3 property(ies) for 2026.')
        ->assertExitCode(0);

    // One budget row per real property, for that year.
    expect(MarketingBudget::where('period_year', 2026)->count())->toBe(3);
    foreach ([$a1, $a2, $a3] as $asset) {
        expect(MarketingBudget::where('period_year', 2026)->where('asset_id', $asset->id)->count())->toBe(1);
    }
});

it('marketing:ensure-budgets skips the synthetic "All Properties" pseudo-asset', function () {
    $allProps = ensureAllPropertiesAsset();
    $real = makeAsset();

    $this->artisan('marketing:ensure-budgets', ['--year' => 2026])
        ->expectsOutputToContain('Ensured marketing budgets for 1 property(ies) for 2026.')
        ->assertExitCode(0);

    // The "ALL" pseudo-asset never gets a budget; only the real property does.
    expect(MarketingBudget::where('asset_id', $allProps->id)->exists())->toBeFalse()
        ->and(MarketingBudget::where('asset_id', $real->id)->where('period_year', 2026)->exists())->toBeTrue()
        ->and(MarketingBudget::where('period_year', 2026)->count())->toBe(1);
});

it('marketing:ensure-budgets is idempotent — re-running creates no duplicates', function () {
    $a1 = makeAsset();
    $a2 = makeAsset();

    $this->artisan('marketing:ensure-budgets', ['--year' => 2026])->assertExitCode(0);
    expect(MarketingBudget::where('period_year', 2026)->count())->toBe(2);

    // Stamp the existing rows so we can prove a re-run does not replace them.
    $ids = MarketingBudget::where('period_year', 2026)->pluck('id')->sort()->values();
    MarketingBudget::where('period_year', 2026)->update(['accrued_amount' => 999]);

    $this->artisan('marketing:ensure-budgets', ['--year' => 2026])->assertExitCode(0);
    $this->artisan('marketing:ensure-budgets', ['--year' => 2026])->assertExitCode(0);

    // Still exactly two rows, and they are the SAME rows (firstOrCreate, not re-created).
    expect(MarketingBudget::where('period_year', 2026)->count())->toBe(2)
        ->and(MarketingBudget::where('period_year', 2026)->pluck('id')->sort()->values()->all())
        ->toBe($ids->all())
        // firstOrCreate left the existing rows (and their accrued stamp) untouched.
        ->and((float) MarketingBudget::where('asset_id', $a1->id)->where('period_year', 2026)->value('accrued_amount'))->toBe(999.0)
        ->and((float) MarketingBudget::where('asset_id', $a2->id)->where('period_year', 2026)->value('accrued_amount'))->toBe(999.0);
});

it('marketing:ensure-budgets defaults to the current year when --year is omitted', function () {
    Carbon::setTestNow('2026-06-29');
    $asset = makeAsset();

    $this->artisan('marketing:ensure-budgets')
        ->expectsOutputToContain('for 2026.')
        ->assertExitCode(0);

    expect(MarketingBudget::where('asset_id', $asset->id)->where('period_year', 2026)->exists())->toBeTrue();

    Carbon::setTestNow();
});

it('marketing:ensure-budgets picks up a newly-added property on a later run, leaving prior budgets intact', function () {
    $existing = makeAsset();
    $this->artisan('marketing:ensure-budgets', ['--year' => 2026])->assertExitCode(0);
    expect(MarketingBudget::where('period_year', 2026)->count())->toBe(1);

    // A property added after the first run...
    $added = makeAsset();
    $this->artisan('marketing:ensure-budgets', ['--year' => 2026])
        ->expectsOutputToContain('for 2 property(ies)')
        ->assertExitCode(0);

    // ...gets its own budget without duplicating the existing one.
    expect(MarketingBudget::where('period_year', 2026)->count())->toBe(2)
        ->and(MarketingBudget::where('asset_id', $existing->id)->where('period_year', 2026)->count())->toBe(1)
        ->and(MarketingBudget::where('asset_id', $added->id)->where('period_year', 2026)->count())->toBe(1);
});

it('marketing:ensure-budgets provisions a distinct budget per year for the same property', function () {
    $asset = makeAsset();

    $this->artisan('marketing:ensure-budgets', ['--year' => 2025])->assertExitCode(0);
    $this->artisan('marketing:ensure-budgets', ['--year' => 2026])->assertExitCode(0);

    expect(MarketingBudget::where('asset_id', $asset->id)->count())->toBe(2)
        ->and(MarketingBudget::where('asset_id', $asset->id)->pluck('period_year')->sort()->values()->all())
        ->toBe([2025, 2026]);
});

/*
| ---- marketing:backfill-budgets ---------------------------------------- |
*/

it('marketing:backfill-budgets seeds a marketing levy charge on an active rent-bearing lease that lacks one', function () {
    $lease = budgetCmdLeaseWithRent(10000);

    // Precondition: the lease has a base-rent charge but NO marketing charge yet.
    expect($lease->charges()->where('type', 'marketing')->exists())->toBeFalse();

    $this->artisan('marketing:backfill-budgets')
        ->expectsOutputToContain('Seeded 1 marketing levy charge(s) on active leases')
        ->assertExitCode(0);

    // A single marketing levy charge now exists, captured at 5% of base rent.
    $levies = $lease->charges()->where('type', 'marketing')->get();
    expect($levies)->toHaveCount(1)
        ->and((float) $levies->first()->amount)->toBe(500.0)
        ->and((bool) $levies->first()->vat_applicable)->toBeFalse();
});

it('marketing:backfill-budgets is idempotent — re-running adds no second levy charge', function () {
    $lease = budgetCmdLeaseWithRent(10000);

    $this->artisan('marketing:backfill-budgets')
        ->expectsOutputToContain('Seeded 1 marketing levy charge(s)')
        ->assertExitCode(0);
    expect($lease->charges()->where('type', 'marketing')->count())->toBe(1);

    // Second run: the lease already carries its levy charge, so nothing is seeded.
    $this->artisan('marketing:backfill-budgets')
        ->expectsOutputToContain('Seeded 0 marketing levy charge(s)')
        ->assertExitCode(0);

    // Still exactly one marketing charge — no duplication.
    expect($lease->charges()->where('type', 'marketing')->count())->toBe(1);
});

it('marketing:backfill-budgets skips inactive leases and zero-rent leases', function () {
    $asset = makeAsset();

    // Active + rent-bearing → should be seeded.
    $billable = budgetCmdLeaseWithRent(10000);

    // Inactive lease (draft) with rent → skipped by the status=active filter.
    $draftUnit = makeUnit(makeAsset());
    $draft = makeLease($draftUnit, null, ['base_rent_monthly' => 10000, 'status' => 'draft']);

    // Active lease with zero base rent → skipped by the base_rent_monthly > 0 filter.
    $zeroUnit = makeUnit(makeAsset());
    $zeroRent = makeLease($zeroUnit, null, ['base_rent_monthly' => 0]);

    $this->artisan('marketing:backfill-budgets')
        ->expectsOutputToContain('Seeded 1 marketing levy charge(s)')
        ->assertExitCode(0);

    expect($billable->charges()->where('type', 'marketing')->exists())->toBeTrue()
        ->and($draft->charges()->where('type', 'marketing')->exists())->toBeFalse()
        ->and($zeroRent->charges()->where('type', 'marketing')->exists())->toBeFalse();
});

it('marketing:backfill-budgets re-derives accrued and spent on existing budgets from source', function () {
    // Bill a lease so a real marketing line item (the accrual source) exists.
    $lease = budgetCmdLeaseWithRent(8000);
    app(MarketingLevyService::class)->createLevyCharge($lease);
    app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-02-01'));

    $budget = MarketingBudget::forPeriod($lease->unit->asset_id, 2026);
    // Record a spend so spent_amount has a source to re-derive from.
    $budget->spends()->create([
        'category' => 'event',
        'description' => 'Launch',
        'amount' => 120,
        'spent_on' => '2026-02-10',
    ]);

    // Corrupt the derived columns to prove the backfill recomputes them from source.
    MarketingBudget::query()->update(['accrued_amount' => 0, 'spent_amount' => 0]);
    expect((float) $budget->refresh()->accrued_amount)->toBe(0.0)
        ->and((float) $budget->refresh()->spent_amount)->toBe(0.0);

    $this->artisan('marketing:backfill-budgets')
        ->expectsOutputToContain('re-derived')
        ->assertExitCode(0);

    // accrued = 5% of 8,000 billed rent = 400; spent = sum of spends = 120.
    $budget->refresh();
    expect((float) $budget->accrued_amount)->toBe(400.0)
        ->and((float) $budget->spent_amount)->toBe(120.0)
        ->and($budget->balance())->toBe(280.0);
});

it('marketing:backfill-budgets runs clean with no leases or budgets present', function () {
    expect(Lease::count())->toBe(0)
        ->and(MarketingBudget::count())->toBe(0);

    $this->artisan('marketing:backfill-budgets')
        ->expectsOutputToContain('Seeded 0 marketing levy charge(s) on active leases; re-derived 0 budget(s).')
        ->assertExitCode(0);
});
