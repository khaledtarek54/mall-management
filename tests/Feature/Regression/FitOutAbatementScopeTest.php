<?php

use App\Models\Charge;
use App\Models\Lease;
use App\Services\MonthlyBillingService;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What a rent-free fit-out period actually abates (story LS-05, phase plan §1 Q2).
 *
 * Atriom suppressed the **entire invoice** during fit-out — rent, service charge, CAM and the
 * marketing levy together — and could not express anything else. The market standard is **net
 * abatement**: base rent free, the tenant still pays the reimbursements, because the landlord is
 * still cleaning, securing and cooling the unit while it is fitted out. On a 36k/month service
 * charge over three months that is ~108k per new tenant the operator is likely entitled to bill.
 *
 * The migration strategy is the load-bearing part and is pinned here: **existing leases keep
 * `gross`** (the column default) because retroactively re-billing a live tenancy is not a
 * migration, while **new leases default to `rent_only`** (the model default).
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function abatementLease(array $attrs = []): Lease
{
    // NB: deliberately NOT called fitOutLease() — FitOutGracePeriodTest already declares that, and
    // Pest helpers are global functions, so a duplicate is a FATAL redeclaration the moment both
    // files load in one process. It surfaces as a run that emits no output at all, not as a failed
    // assertion, and --parallel can hide it entirely.
    $lease = makeLease(makeUnit(makeAsset()), null, array_merge([
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2028-12-31',
        'base_rent_monthly' => 100000,
        'service_charge_monthly' => 36000,
        'fit_out_months' => 3,
        'has_marketing_levy' => false,
    ], $attrs));

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_SEED, 'amount' => 100000, 'currency' => 'EGP',
        'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT,
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Service Charge', 'type' => 'service_charge',
        'origin' => Charge::ORIGIN_SEED, 'amount' => 36000, 'currency' => 'EGP',
        'frequency' => 'monthly', 'vat_applicable' => true, 'vat_rate' => Vat::standardRate(),
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);

    return $lease->fresh();
}

/* ---- the migration contract ------------------------------------------------ */

it('defaults a NEW lease to the standard: base rent free, service charge payable', function () {
    expect(abatementLease()->fit_out_scope)->toBe(Lease::FIT_OUT_RENT_ONLY);
});

it('leaves every EXISTING lease on the grace it was actually billed under', function () {
    // The COLUMN default is gross, which is what the migration writes to every pre-existing row.
    // Simulated with a raw insert-then-read, because the model default would mask it.
    $lease = abatementLease();
    DB::table('leases')->where('id', $lease->id)->update(['fit_out_scope' => 'gross']);

    expect($lease->fresh()->fit_out_scope)->toBe(Lease::FIT_OUT_GROSS);
});

/* ---- what actually bills --------------------------------------------------- */

it('bills the service charge during fit-out and abates only the rent', function () {
    CarbonImmutable::setTestNow('2026-02-15');
    $lease = abatementLease();

    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-02-01'))['invoice'];

    expect($invoice)->not->toBeNull()
        ->and($invoice->items()->where('type', 'base_rent')->count())->toBe(0)
        ->and((float) $invoice->items()->where('type', 'service_charge')->sole()->amount)->toBe(36000.0)
        // …and the VAT on it, which is revenue the mall was also not collecting.
        ->and((float) $invoice->vat_amount)->toBe(round(36000 * Vat::standardRate() / 100, 2));
});

it('still suppresses the whole invoice for a gross lease', function () {
    CarbonImmutable::setTestNow('2026-02-15');
    $lease = abatementLease();
    DB::table('leases')->where('id', $lease->id)->update(['fit_out_scope' => 'gross']);

    $result = app(MonthlyBillingService::class)
        ->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-02-01'));

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('fit_out');
});

it('bills everything once the fit-out window has passed', function () {
    CarbonImmutable::setTestNow('2026-04-15');
    $lease = abatementLease();

    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-04-01'))['invoice'];

    expect((float) $invoice->items()->where('type', 'base_rent')->sole()->amount)->toBe(100000.0)
        ->and((float) $invoice->items()->where('type', 'service_charge')->sole()->amount)->toBe(36000.0);
});

it('reports fit_out when every charge the lease has is abated', function () {
    CarbonImmutable::setTestNow('2026-02-15');
    $lease = abatementLease();
    // A rent-only lease: nothing survives the abatement, so nothing bills.
    Charge::where('lease_id', $lease->id)->where('type', 'service_charge')->delete();

    $result = app(MonthlyBillingService::class)
        ->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-02-01'));

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('fit_out');
});

/* ---- the derived rules that follow ----------------------------------------- */

it('treats a net-abated lease as billable from commencement, not from the end of grace', function () {
    $lease = abatementLease();
    $gross = abatementLease();
    DB::table('leases')->where('id', $gross->id)->update(['fit_out_scope' => 'gross']);

    // firstBillableMonth drives the quarterly cycle anchor AND the "unbilled leases" card, so
    // deriving it from the scope in one place keeps all three consistent.
    expect($lease->firstBillableMonth()->toDateString())->toBe('2026-01-01')
        ->and($gross->fresh()->firstBillableMonth()->toDateString())->toBe('2026-04-01');
});

it('separates "inside the grace window" from "nothing bills"', function () {
    $lease = abatementLease();
    $feb = CarbonImmutable::parse('2026-02-28');

    expect($lease->inFitOutWindow($feb))->toBeTrue()      // rent IS free in February
        ->and($lease->periodInFitOut($feb))->toBeFalse()  // …but the invoice still goes out
        ->and($lease->abatedChargeTypesFor($feb))->toBe(['base_rent']);
});

it('abates nothing on a lease with no fit-out period at all', function () {
    $lease = abatementLease(['fit_out_months' => 0]);

    expect($lease->abatedChargeTypesFor(CarbonImmutable::parse('2026-02-28')))->toBe([])
        ->and($lease->inFitOutWindow(CarbonImmutable::parse('2026-02-28')))->toBeFalse();
});

it('shows the abated month in the billing preview as billing, not as skipped', function () {
    CarbonImmutable::setTestNow('2026-02-15');
    $lease = abatementLease();

    $row = app(MonthlyBillingService::class)
        ->previewForPeriod(CarbonImmutable::parse('2026-02-01'), $lease->unit->asset_id)['rows'][0];

    expect($row['billable'])->toBeTrue()
        ->and($row['total'])->toBeGreaterThan(0.0)
        ->and($row['line_count'])->toBe(1);
});
