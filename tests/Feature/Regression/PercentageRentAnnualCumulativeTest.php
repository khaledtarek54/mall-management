<?php

use App\Models\Invoice;
use App\Models\TenantSalesDeclaration;
use App\Services\PercentageRentCalculationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Cache;

/**
 * Annual (cumulative) percentage rent — module 09 close-out follow-on (operator opted in after
 * confirming annual-breakpoint leases exist / are being signed).
 *
 * For an annual-frequency lease the breakpoint is a YEARLY figure. Each locked month bills the DELTA
 * that trues the year's total billed % rent up to overage(cumulative sales this year) — so a month
 * under the running breakpoint bills 0, the month it crosses bills only the increment, and the year's
 * invoices sum to the true annual overage. A seasonal spike is netted against slow months instead of
 * over-charged against a monthly breakpoint (which is the whole point of the annual structure).
 *
 * The billing PATH is identical to the monthly one (billOverageImmediately → a `percentage_rent`
 * invoice item → percentage_rent_revenue in the GL), so the GL tie-out is already covered by
 * PercentageRentGlTieOutTest; these tests pin the cumulative ARITHMETIC + the frequency branch.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->unit = makeUnit($this->asset);
    $this->operator = makeUser('manager', [$this->asset->id]);
    $this->svc = app(PercentageRentCalculationService::class);
});

function annualPctLease($ctx, array $overrides = [])
{
    return makeLease($ctx->unit, $ctx->tenant, array_merge([
        'status' => 'active',
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_frequency' => 'annual',
        'percentage_rent_threshold' => 150000, // ANNUAL breakpoint
        'percentage_rent_rate' => 10,
    ], $overrides));
}

function declareMonth($lease, string $month, float $sales): TenantSalesDeclaration
{
    $start = $month.'-01';

    return TenantSalesDeclaration::create([
        'lease_id' => $lease->id,
        'period_start' => $start,
        'period_end' => CarbonImmutable::parse($start)->endOfMonth()->toDateString(),
        'declared_sales' => $sales,
        'calculated_percentage_rent' => 0,
        'status' => 'submitted',
        'declared_at' => now(),
        'declared_by_type' => $lease->tenant::class,
        'declared_by_id' => $lease->tenant_id,
    ]);
}

/** Total of the lease's LIVE percentage-rent overage invoices (what the tenant is actually billed). */
function annualOverageBilled($lease): float
{
    return (float) Invoice::whereHas('items', fn ($q) => $q->where('type', 'percentage_rent'))
        ->where('lease_id', $lease->id)
        ->whereNotIn('status', ['cancelled', 'credited'])
        ->sum('total');
}

it('bills the delta each month so the year totals the true annual overage', function () {
    $lease = annualPctLease($this); // annual breakpoint 150,000, rate 10%

    // Jan 60k → cumulative 60k < 150k → bills nothing.
    $this->svc->lock(declareMonth($lease, '2026-01', 60000), $this->operator);
    expect(annualOverageBilled($lease))->toBe(0.0);

    // Feb 60k → cumulative 120k, still < 150k → nothing.
    $this->svc->lock(declareMonth($lease, '2026-02', 60000), $this->operator);
    expect(annualOverageBilled($lease))->toBe(0.0);

    // Mar 60k → cumulative 180k. overage (180k − 150k) × 10% = 3,000. Bills only the increment.
    $mar = declareMonth($lease, '2026-03', 60000);
    $this->svc->lock($mar, $this->operator);
    expect(annualOverageBilled($lease))->toBe(3000.0)
        ->and((float) $mar->fresh()->calculated_percentage_rent)->toBe(3000.0);

    // Apr 40k → cumulative 220k. overage (220k − 150k) × 10% = 7,000; already 3,000 → delta 4,000.
    $apr = declareMonth($lease, '2026-04', 40000);
    $this->svc->lock($apr, $this->operator);
    expect(annualOverageBilled($lease))->toBe(7000.0)       // 3,000 + 4,000 = full annual overage
        ->and((float) $apr->fresh()->calculated_percentage_rent)->toBe(4000.0);
});

it('nets a seasonal spike against slow months (annual bills less than a monthly breakpoint)', function () {
    // ANNUAL breakpoint 150k, rate 10%. Two slow months + a spike.
    $annual = annualPctLease($this);
    foreach (['2026-01' => 40000, '2026-02' => 40000, '2026-03' => 120000] as $m => $s) {
        $this->svc->lock(declareMonth($annual, $m, $s), $this->operator);
    }
    // cumulative 200k → (200k − 150k) × 10% = 5,000.
    expect(annualOverageBilled($annual))->toBe(5000.0);

    // Same sales on a MONTHLY-breakpoint lease (50k/mo) bills the spike in full — no netting.
    $monthly = makeLease(makeUnit($this->asset), makeTenant(), [
        'status' => 'active',
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_frequency' => 'monthly',
        'percentage_rent_threshold' => 50000,
        'percentage_rent_rate' => 10,
    ]);
    foreach (['2026-01' => 40000, '2026-02' => 40000, '2026-03' => 120000] as $m => $s) {
        $this->svc->lock(declareMonth($monthly, $m, $s), $this->operator);
    }
    // Jan/Feb under 50k → 0; Mar (120k − 50k) × 10% = 7,000.
    expect(annualOverageBilled($monthly))->toBe(7000.0);

    // The annual tenant pays LESS: the two slow months (20k under breakpoint) shelter the spike.
    expect(annualOverageBilled($annual))->toBeLessThan(annualOverageBilled($monthly));
});

it('trues up correctly (and deterministically) when months are locked out of order', function () {
    $lease = annualPctLease($this); // 150k annual, 10%

    // Lock MARCH first (Jan/Feb reports arrived late): cumulative 120k < 150k → nothing.
    $mar = declareMonth($lease, '2026-03', 120000);
    $this->svc->lock($mar, $this->operator);
    expect(annualOverageBilled($lease))->toBe(0.0);

    // The late JAN report (100k) → the year now owes overage on 220k = (220k − 150k) × 10% = 7,000.
    // Attribution is by CALENDAR order, not lock order: the cumulative crosses the breakpoint in March
    // (Jan alone is 100k < 150k), so re-truing the year attributes the 7,000 to March — not the
    // later-locked January.
    $jan = declareMonth($lease, '2026-01', 100000);
    $this->svc->lock($jan, $this->operator);
    expect(annualOverageBilled($lease))->toBe(7000.0)
        ->and((float) $jan->fresh()->calculated_percentage_rent)->toBe(0.0)
        ->and((float) $mar->fresh()->calculated_percentage_rent)->toBe(7000.0);
});

it('re-trues the whole year when a month is VOIDED (no stale over-bill on the other months)', function () {
    // The bug this guards: in the cumulative model the overage invoice lands on whichever month was
    // locked when the total crossed the breakpoint. Voiding a DIFFERENT month used to leave that
    // invoice stranded — sized against a cumulative that included the now-removed month — over-billing
    // the tenant with no self-correction.
    $lease = annualPctLease($this); // 150k annual, 10%

    $jan = declareMonth($lease, '2026-01', 200000);
    $this->svc->lock($jan, $this->operator);              // cumulative 200k → Jan carries 5,000
    $feb = declareMonth($lease, '2026-02', 100000);
    $this->svc->lock($feb, $this->operator);              // cumulative 300k → Feb carries 10,000
    expect(annualOverageBilled($lease))->toBe(15000.0);

    // Void January. The true overage on the remaining 100k is 0 — and Feb's 10,000 invoice, sized
    // against a cumulative that INCLUDED January's 200k, must be re-trued to 0, not left stranded.
    $this->svc->voidLocked($jan->fresh(), $this->operator, 'January figure was wrong');
    expect(annualOverageBilled($lease))->toBe(0.0)                 // was 10,000 before the re-true fix
        ->and((float) $feb->fresh()->calculated_percentage_rent)->toBe(0.0);
});

it('re-trues the year on a down-revision — re-locking the REVISED month clears the over-bill', function () {
    // The operator restates the month whose figure was wrong; they must NOT have to know which month
    // happens to carry the overage invoice. Re-truing the whole year on that re-lock fixes both.
    $lease = annualPctLease($this); // 150k annual, 10%

    $jan = declareMonth($lease, '2026-01', 100000);
    $this->svc->lock($jan, $this->operator);              // 100k < 150k → 0
    $feb = declareMonth($lease, '2026-02', 100000);
    $this->svc->lock($feb, $this->operator);              // cumulative 200k → Feb carries 5,000
    expect(annualOverageBilled($lease))->toBe(5000.0);

    // Restate JANUARY down to 20k. Void + re-lock JANUARY (the changed month).
    $this->svc->voidLocked($jan->fresh(), $this->operator, 'January overstated');
    $jan->fresh()->update(['status' => 'submitted', 'declared_sales' => 20000]);
    $this->svc->lock($jan->fresh(), $this->operator);

    // Year sales now 120k < 150k → owes 0. Re-truing the whole year on the January re-lock clears
    // Feb's stale 5,000 even though the operator only touched January.
    expect(annualOverageBilled($lease))->toBe(0.0)
        ->and((float) $feb->fresh()->calculated_percentage_rent)->toBe(0.0);
});

it('leaves monthly-frequency leases billing fresh per month (default unchanged)', function () {
    $lease = makeLease($this->unit, $this->tenant, [
        'status' => 'active',
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        // percentage_rent_frequency omitted → defaults to 'monthly'
        'percentage_rent_threshold' => 50000,
        'percentage_rent_rate' => 5,
    ]);
    expect($lease->percentage_rent_frequency)->toBe('monthly');

    $jan = declareMonth($lease, '2026-01', 100000);
    $this->svc->lock($jan, $this->operator);
    expect((float) $jan->fresh()->calculated_percentage_rent)->toBe(2500.0) // (100k − 50k) × 5%
        ->and(annualOverageBilled($lease))->toBe(2500.0);

    // Next month bills fresh — NOT netted against the first (that's the monthly behaviour).
    $feb = declareMonth($lease, '2026-02', 100000);
    $this->svc->lock($feb, $this->operator);
    expect((float) $feb->fresh()->calculated_percentage_rent)->toBe(2500.0)
        ->and(annualOverageBilled($lease))->toBe(5000.0);
});

it('re-locking an annual month after a void does not double-bill', function () {
    $lease = annualPctLease($this); // 150k annual, 10%
    $this->svc->lock(declareMonth($lease, '2026-01', 100000), $this->operator); // 100k < 150k → 0

    $mar = declareMonth($lease, '2026-03', 100000);
    $this->svc->lock($mar, $this->operator);
    expect(annualOverageBilled($lease))->toBe(5000.0); // cumulative 200k → (200k − 150k) × 10%

    // Void March: its delta invoice is cancelled and its sales leave the cumulative (Jan 100k < 150k).
    $this->svc->voidLocked($mar->fresh(), $this->operator, 'restate the figure');
    expect(annualOverageBilled($lease))->toBe(0.0);

    // Re-submit + re-lock the SAME March declaration (the real correction flow — one declaration per
    // period) → back to 5,000 on exactly ONE live overage invoice, not two.
    $mar->fresh()->update(['status' => 'submitted']);
    $this->svc->lock($mar->fresh(), $this->operator);
    expect(annualOverageBilled($lease))->toBe(5000.0);

    $liveInvoices = Invoice::whereHas('items', fn ($q) => $q->where('type', 'percentage_rent'))
        ->where('lease_id', $lease->id)
        ->whereNotIn('status', ['cancelled', 'credited'])
        ->count();
    expect($liveInvoices)->toBe(1);
});

it('does not gate a monthly lease behind the per-lease mutex (annual-only serialization)', function () {
    // Annual locks serialize behind Cache::lock('pct-rent:lock:lease:{id}') so two concurrent months
    // of one lease can't each miss the other's cumulative and under-bill. Monthly months are
    // independent, so they must NOT take that lock — proven here by holding the would-be key and
    // confirming a monthly lock proceeds immediately (an annual lock would instead block on it).
    $lease = makeLease($this->unit, $this->tenant, [
        'status' => 'active',
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_frequency' => 'monthly',
        'percentage_rent_threshold' => 50000,
        'percentage_rent_rate' => 5,
    ]);
    $held = Cache::lock('pct-rent:lock:lease:'.$lease->id, 10);
    expect($held->get())->toBeTrue();

    // Would hang/throw if a monthly lock wrongly contended on the held key; instead it bills at once.
    $this->svc->lock(declareMonth($lease, '2026-01', 100000), $this->operator);
    expect(annualOverageBilled($lease))->toBe(2500.0);

    $held->release();
});

it('uses annual base rent (×12) as the natural breakpoint for annual leases', function () {
    $lease = annualPctLease($this, [
        'percentage_rent_calculation_type' => 'natural_breakpoint',
        'base_rent_monthly' => 10000, // annual base 120,000 → natural breakpoint
        'percentage_rent_rate' => 10,
        'percentage_rent_threshold' => null,
    ]);

    // Month 1: 800k → 800k × 10% = 80,000 < 120,000 annual base → nothing.
    $this->svc->lock(declareMonth($lease, '2026-01', 800000), $this->operator);
    expect(annualOverageBilled($lease))->toBe(0.0);

    // Month 2: cumulative 1,600,000 × 10% = 160,000 − 120,000 = 40,000.
    $this->svc->lock(declareMonth($lease, '2026-02', 800000), $this->operator);
    expect(annualOverageBilled($lease))->toBe(40000.0);
});
