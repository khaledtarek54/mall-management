<?php

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\LeasePercentageRentTier;
use App\Models\TenantSalesDeclaration;
use App\Services\PercentageRentCalculationService;
use Carbon\CarbonImmutable;

/**
 * Percentage rent: tiered breakpoints (PR-02), deductions (PR-03) and estimated sales (PR-04).
 *
 * All three were verified absent against the CODE before being built — not against a module doc,
 * which is how a false gap got into this benchmark once already.
 *
 * The arithmetic that must not be got wrong is the ladder: each band charges only the sales WITHIN
 * it. Charging the top rate on the whole figure is the classic way to overcharge every large
 * tenant, and it is the reason `LeasePercentageRentTier::overageFor()` exists as one method rather
 * than being inlined.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function tieredLease(array $attrs = []): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, array_merge([
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2028-12-31',
        'base_rent_monthly' => 100000,
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'tiered',
        'percentage_rent_rate' => 0,
    ], $attrs));

    // Yardi's own worked example: 0–500K at 0%, 500K–900K at 5%, above 900K at 6%.
    foreach ([[0, 500000, 0], [500000, 900000, 5], [900000, null, 6]] as [$from, $to, $rate]) {
        LeasePercentageRentTier::create([
            'lease_id' => $lease->id, 'from_amount' => $from, 'to_amount' => $to, 'rate' => $rate,
        ]);
    }

    return $lease->fresh();
}

function declare_(Lease $lease, float $sales, string $month = '2026-06'): TenantSalesDeclaration
{
    return TenantSalesDeclaration::create([
        'lease_id' => $lease->id,
        'period_start' => "{$month}-01",
        'period_end' => CarbonImmutable::parse("{$month}-01")->endOfMonth()->toDateString(),
        'declared_sales' => $sales,
        'declared_at' => now(),
        'status' => 'submitted',
    ]);
}

/* ---- PR-02: the ladder ----------------------------------------------------- */

it('charges each band only on the sales within it', function () {
    $lease = tieredLease();

    // 1,000,000 → 400,000 in the 5% band + 100,000 in the 6% band = 20,000 + 6,000.
    // Charging 6% on the whole 1,000,000 (60,000) is the classic overcharge this guards against.
    expect(app(PercentageRentCalculationService::class)->calculate(declare_($lease, 1000000)))
        ->toBe(26000.0);
});

it('charges nothing below the first paying band', function () {
    $lease = tieredLease();

    expect(app(PercentageRentCalculationService::class)->calculate(declare_($lease, 400000)))->toBe(0.0)
        // Exactly at the edge is still nothing — the band charges sales ABOVE its floor.
        ->and(app(PercentageRentCalculationService::class)->calculate(declare_($lease, 500000, '2026-07')))->toBe(0.0);
});

it('charges only the part-filled band when sales stop inside it', function () {
    $lease = tieredLease();

    // 700,000 → 200,000 into the 5% band, nothing in the 6% band.
    expect(app(PercentageRentCalculationService::class)->calculate(declare_($lease, 700000)))->toBe(10000.0);
});

it('runs the top band unbounded, so a big month is not silently uncharged', function () {
    $lease = tieredLease();

    // 5,000,000 → 400,000 @5% + 4,100,000 @6%.
    expect(app(PercentageRentCalculationService::class)->calculate(declare_($lease, 5000000)))
        ->toBe(20000.0 + 246000.0);
});

it('applies the ladder to the cumulative annual basis too, through the same choke point', function () {
    // The ladder was inserted at overage(), which the annual marginal arithmetic is expressed in
    // terms of — so tiers and cumulative compose without either knowing about the other.
    $lease = tieredLease(['percentage_rent_frequency' => 'annual']);

    $jan = declare_($lease, 600000, '2026-01');
    $jan->update(['status' => 'locked', 'declared_sales' => 600000]);

    // YTD 600,000 → 100,000 @5% = 5,000 already attributed to January.
    // February takes YTD to 1,000,000 → 26,000 total, so February's marginal is 21,000.
    expect(app(PercentageRentCalculationService::class)->calculate(declare_($lease, 400000, '2026-02')))
        ->toBe(21000.0);
});

it('leaves single-rate leases exactly as they were', function () {
    // The artificial/natural bases must be untouched — tiers are a third option, not a rewrite.
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active', 'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31',
        'base_rent_monthly' => 100000, 'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 1000000, 'percentage_rent_rate' => 8,
    ]);

    expect(app(PercentageRentCalculationService::class)->calculate(declare_($lease, 3000000)))
        ->toBe(160000.0);
});

/* ---- PR-03: deductions ----------------------------------------------------- */

it('credits the deductible charges the tenant was actually billed for the period', function () {
    $lease = tieredLease(['percentage_rent_deductible_types' => ['service_charge']]);

    // The clause: percentage rent payable to the extent it exceeds the service charge paid.
    $invoice = Invoice::create([
        'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'status' => 'paid',
        'issue_date' => '2026-06-01', 'due_date' => '2026-06-08',
        'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
        'subtotal' => 6000, 'vat_amount' => 0, 'total' => 6000, 'paid_amount' => 6000, 'balance' => 0,
    ]);
    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'description' => 'Service charge', 'type' => 'service_charge',
        'amount' => 6000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 6000,
    ]);

    // Gross 26,000 − 6,000 billed service charge = 20,000.
    expect(app(PercentageRentCalculationService::class)->calculate(declare_($lease, 1000000)))
        ->toBe(20000.0);
});

it('floors a deduction at zero rather than refunding the tenant', function () {
    $lease = tieredLease(['percentage_rent_deductible_types' => ['service_charge']]);

    $invoice = Invoice::create([
        'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'status' => 'paid',
        'issue_date' => '2026-06-01', 'due_date' => '2026-06-08',
        'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
        'subtotal' => 90000, 'vat_amount' => 0, 'total' => 90000, 'paid_amount' => 90000, 'balance' => 0,
    ]);
    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'description' => 'Service charge', 'type' => 'service_charge',
        'amount' => 90000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 90000,
    ]);

    // "Payable to the extent it exceeds X" owes nothing when it does not exceed X — it does not
    // become a refund of the tenant's own service charge.
    expect(app(PercentageRentCalculationService::class)->calculate(declare_($lease, 1000000)))
        ->toBe(0.0);
});

it('ignores a cancelled invoice when computing the deduction', function () {
    $lease = tieredLease(['percentage_rent_deductible_types' => ['service_charge']]);

    $invoice = Invoice::create([
        'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'status' => 'cancelled',
        'issue_date' => '2026-06-01', 'due_date' => '2026-06-08',
        'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
        'subtotal' => 6000, 'vat_amount' => 0, 'total' => 6000, 'paid_amount' => 0, 'balance' => 0,
    ]);
    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'description' => 'Service charge', 'type' => 'service_charge',
        'amount' => 6000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 6000,
    ]);

    // A reversed charge was never paid, so crediting it would hand the tenant a deduction for
    // money they never spent.
    expect(app(PercentageRentCalculationService::class)->calculate(declare_($lease, 1000000)))
        ->toBe(26000.0);
});

it('deducts nothing when the lease names no deductible types', function () {
    $lease = tieredLease(); // no percentage_rent_deductible_types

    expect(app(PercentageRentCalculationService::class)->calculate(declare_($lease, 1000000)))
        ->toBe(26000.0);
});

/* ---- PR-04: estimated sales ------------------------------------------------ */

function pctLeaseWithHistory(array $history = [800000, 900000, 1000000]): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active', 'commencement_date' => '2025-01-01', 'expiry_date' => '2028-12-31',
        'base_rent_monthly' => 100000, 'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 500000, 'percentage_rent_rate' => 5,
    ]);

    foreach ($history as $i => $sales) {
        $month = CarbonImmutable::parse('2026-03-01')->addMonths($i);
        TenantSalesDeclaration::create([
            'lease_id' => $lease->id,
            'period_start' => $month->toDateString(),
            'period_end' => $month->endOfMonth()->toDateString(),
            'declared_sales' => $sales,
            'declared_at' => $month->endOfMonth(),
            'status' => 'locked',
        ]);
    }

    return $lease->fresh();
}

it('raises an estimate from the tenant\'s own trailing average when they never declare', function () {
    CarbonImmutable::setTestNow('2026-07-08');
    $lease = pctLeaseWithHistory([800000, 900000, 1000000]); // Mar, Apr, May

    $this->artisan('sales:estimate-missing', ['--period' => '2026-06-01'])->assertSuccessful();

    $estimate = TenantSalesDeclaration::where('lease_id', $lease->id)
        ->whereDate('period_start', '2026-06-01')->sole();

    // The tenant's own average, not a landlord guess — defensible and self-correcting.
    expect((float) $estimate->declared_sales)->toBe(900000.0)
        ->and($estimate->is_estimate)->toBeTrue()
        // NOT locked: an estimate is a prompt for a decision, not a fact. The operator reviews and
        // locks, the same gate every other percentage-rent charge passes.
        ->and($estimate->status)->toBe('submitted');
});

it('never overwrites a declaration the tenant actually filed', function () {
    CarbonImmutable::setTestNow('2026-07-08');
    $lease = pctLeaseWithHistory();
    declare_($lease, 1234567, '2026-06');

    $this->artisan('sales:estimate-missing', ['--period' => '2026-06-01'])->assertSuccessful();

    $rows = TenantSalesDeclaration::where('lease_id', $lease->id)->whereDate('period_start', '2026-06-01')->get();
    expect($rows)->toHaveCount(1)
        ->and((float) $rows->first()->declared_sales)->toBe(1234567.0)
        ->and($rows->first()->is_estimate)->toBeFalse();
});

it('refuses to invent a number for a tenant with no history', function () {
    CarbonImmutable::setTestNow('2026-07-08');
    $lease = pctLeaseWithHistory([]);   // brand-new tenant, nothing to average

    $this->artisan('sales:estimate-missing', ['--period' => '2026-06-01'])->assertSuccessful();

    // Inventing one would be inventing data — the same rule that stops the escalation sweep
    // guessing a CPI figure. The reminder scan keeps chasing them instead.
    expect(TenantSalesDeclaration::where('lease_id', $lease->id)->whereDate('period_start', '2026-06-01')->count())
        ->toBe(0);
});

it('is idempotent and writes nothing on a dry run', function () {
    CarbonImmutable::setTestNow('2026-07-08');
    $lease = pctLeaseWithHistory();

    $this->artisan('sales:estimate-missing', ['--period' => '2026-06-01', '--dry-run' => true])->assertSuccessful();
    expect(TenantSalesDeclaration::where('lease_id', $lease->id)->whereDate('period_start', '2026-06-01')->count())->toBe(0);

    $this->artisan('sales:estimate-missing', ['--period' => '2026-06-01'])->assertSuccessful();
    $this->artisan('sales:estimate-missing', ['--period' => '2026-06-01'])->assertSuccessful();

    expect(TenantSalesDeclaration::where('lease_id', $lease->id)->whereDate('period_start', '2026-06-01')->count())->toBe(1);
});

/* ---- the ladder cannot be built wrong -------------------------------------- */

it('refuses an overlapping band, because an overlap charges the same sales twice', function () {
    $lease = tieredLease(); // 0–500K@0 · 500K–900K@5 · 900K+@6

    // The typo: 400,000 instead of 500,000 as the floor. Left unguarded this bills 31,000 on
    // sales of 1,000,000 where the correct ladder bills 26,000 — silently, for the whole lease.
    expect(fn () => LeasePercentageRentTier::create([
        'lease_id' => $lease->id, 'from_amount' => 400000, 'to_amount' => 700000, 'rate' => 5,
    ]))->toThrow(DomainException::class);

    expect(LeasePercentageRentTier::where('lease_id', $lease->id)->count())->toBe(3)
        ->and(app(PercentageRentCalculationService::class)->calculate(declare_($lease, 1000000)))
        ->toBe(26000.0);
});

it('allows bands that meet exactly, which is what a contiguous ladder is', function () {
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active', 'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31',
        'base_rent_monthly' => 100000, 'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'tiered', 'percentage_rent_rate' => 0,
    ]);

    // Half-open bands: one ending where the next begins is adjacent, not overlapping.
    LeasePercentageRentTier::create(['lease_id' => $lease->id, 'from_amount' => 0, 'to_amount' => 500000, 'rate' => 0]);
    LeasePercentageRentTier::create(['lease_id' => $lease->id, 'from_amount' => 500000, 'to_amount' => 900000, 'rate' => 5]);
    LeasePercentageRentTier::create(['lease_id' => $lease->id, 'from_amount' => 900000, 'to_amount' => null, 'rate' => 6]);

    expect(LeasePercentageRentTier::where('lease_id', $lease->id)->count())->toBe(3);
});

it('allows a GAP, because a gap is the same thing as a 0% band', function () {
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active', 'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31',
        'base_rent_monthly' => 100000, 'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'tiered', 'percentage_rent_rate' => 0,
    ]);

    // "No percentage rent on sales between 500K and 900K" is a real deal shape — and it is also
    // the natural intermediate state while a ladder is being typed in. Refusing it would block a
    // legitimate structure to guard against a typo the overlap check already catches.
    LeasePercentageRentTier::create(['lease_id' => $lease->id, 'from_amount' => 0, 'to_amount' => 500000, 'rate' => 0]);
    LeasePercentageRentTier::create(['lease_id' => $lease->id, 'from_amount' => 900000, 'to_amount' => null, 'rate' => 6]);

    // 1,000,000 → only the 100,000 above 900K is charged.
    expect(app(PercentageRentCalculationService::class)->calculate(declare_($lease, 1000000)))->toBe(6000.0);
});

it('refuses a band that ends before it starts', function () {
    $lease = tieredLease();

    expect(fn () => LeasePercentageRentTier::create([
        'lease_id' => $lease->id, 'from_amount' => 2000000, 'to_amount' => 1000000, 'rate' => 5,
    ]))->toThrow(DomainException::class);
});

it('lets an existing band be edited without clashing with itself', function () {
    $lease = tieredLease();
    $top = LeasePercentageRentTier::where('lease_id', $lease->id)->orderByDesc('from_amount')->first();

    $top->update(['rate' => 7]);

    expect((float) $top->fresh()->rate)->toBe(7.0);
});

// ── Validation sweep (leasing, 2026-08-11) ──────────────────────────────────────────────────────
// The ladder's OVERLAP and INVERSION rules were already at the model. The bounds were not: the
// relation manager carried minValue(0) on from/to and minValue(0)->maxValue(100) on the rate, and
// nothing behind it. A negative rate is the one that matters — it makes a percentage-rent "charge"
// that is really a credit, billed through the same path as a real overage.

/**
 * A lease whose ladder ENDS at 2,000,000, so a test band appended above it is adjacent rather
 * than overlapping. Without this the bounds tests below throw for the wrong reason — the
 * unbounded top band swallows everything above 900,000 and every append is an overlap. (It
 * caught exactly that on the first run: two refusals passing on the overlap guard while the
 * bound they claimed to test did not exist.)
 */
function closedLadderLease(): Lease
{
    $lease = tieredLease();
    LeasePercentageRentTier::where('lease_id', $lease->id)
        ->whereNull('to_amount')
        ->update(['to_amount' => 2000000]);

    return $lease;
}

it('refuses a negative breakpoint on a tier', function () {
    expect(fn () => LeasePercentageRentTier::create([
        'lease_id' => closedLadderLease()->id, 'from_amount' => -100, 'to_amount' => -50, 'rate' => 5,
    ]))->toThrow(DomainException::class);
});

it('refuses a negative percentage rate — a charge that is secretly a credit', function () {
    expect(fn () => LeasePercentageRentTier::create([
        'lease_id' => closedLadderLease()->id, 'from_amount' => 2000000, 'to_amount' => 3000000, 'rate' => -5,
    ]))->toThrow(DomainException::class);
});

it('refuses a rate above 100% — more percentage rent than the tenant sold', function () {
    expect(fn () => LeasePercentageRentTier::create([
        'lease_id' => closedLadderLease()->id, 'from_amount' => 2000000, 'to_amount' => 3000000, 'rate' => 150,
    ]))->toThrow(DomainException::class);
});

it('still accepts a legitimate band appended to the ladder', function () {
    // The control the three refusals need: the same append, differing only in the bound under
    // test, must succeed — otherwise they pass on the overlap guard and prove nothing.
    $tier = LeasePercentageRentTier::create([
        'lease_id' => closedLadderLease()->id, 'from_amount' => 2000000, 'to_amount' => 3000000, 'rate' => 7,
    ]);

    expect($tier->exists)->toBeTrue();
});
