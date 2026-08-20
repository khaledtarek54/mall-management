<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeasePercentageRentTier;
use App\Models\Tenant;
use App\Models\TenantSalesDeclaration;
use App\Models\Unit;
use App\Services\ChargeScheduleService;
use App\Services\LeaseCreationService;
use App\Services\LeaseReliefService;
use App\Services\LeaseSpaceChangeService;
use App\Services\PercentageRentCalculationService;
use Carbon\CarbonImmutable;

$asset = Asset::where('code', 'AW')->firstOrFail();
$tenant = Tenant::whereDoesntHave('unitOwnerships')->firstOrFail();
$freeUnits = Unit::where('asset_id', $asset->id)->where('status', 'vacant')->orderBy('id')->get();
$n = 0;
$sched = app(ChargeScheduleService::class);
$pct = app(PercentageRentCalculationService::class);
$mk = function (array $a) use (&$n, $freeUnits, $asset, $tenant): Lease {
    $u = $freeUnits[$n++];
    $l = Lease::create(array_merge([
        'asset_id' => $asset->id, 'tenant_id' => $tenant->id, 'unit_id' => $u->id,
        'reference' => 'QA-'.strtoupper(bin2hex(random_bytes(3))), 'status' => 'active', 'currency' => 'EGP',
        'billing_frequency' => 'monthly', 'payment_terms_days' => 7, 'escalation_type' => 'none',
    ], $a));
    LeaseCreationService::seedStandardCharges($l, (float) $l->base_rent_monthly, (float) $l->service_charge_monthly, $l->commencement_date);

    return $l->fresh('charges');
};
$decl = fn (Lease $l, string $m, float $sales) => TenantSalesDeclaration::create([
    'lease_id' => $l->id,
    'period_start' => $m.'-01', 'period_end' => CarbonImmutable::parse($m.'-01')->endOfMonth()->toDateString(),
    'declared_sales' => $sales, 'gross_sales' => $sales, 'status' => 'submitted',
    'declared_at' => CarbonImmutable::parse($m.'-01')->endOfMonth()->toDateString(),
]);
$rentOn = fn (Lease $l, string $d) => (float) optional(ChargeScheduleService::pickInForce(
    $l->fresh('charges')->charges->where('type', 'base_rent'), CarbonImmutable::parse($d)))->amount;

qa_section('PCT RENT 1 — artificial breakpoint, monthly');
$l = $mk(['commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31', 'term_months' => 36,
    'base_rent_monthly' => 50000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
    'has_percentage_rent' => true, 'percentage_rent_threshold' => 1000000, 'percentage_rent_rate' => 8,
    'percentage_rent_calculation_type' => 'artificial', 'percentage_rent_frequency' => 'monthly']);
qa_eq('sales BELOW the breakpoint owe nothing', 0.0, $pct->calculate($decl($l, '2026-03', 800000)));
qa_eq('sales EXACTLY at the breakpoint owe nothing', 0.0, $pct->calculate($decl($l, '2026-04', 1000000)));
qa_eq('sales above → 8% of the excess', round((1500000 - 1000000) * 0.08, 2), $pct->calculate($decl($l, '2026-05', 1500000)));
qa_eq('zero sales owe nothing (never negative)', 0.0, $pct->calculate($decl($l, '2026-06', 0)));

qa_section('PCT RENT 2 — natural breakpoint = sales x rate − base rent');
$l2 = $mk(['commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31', 'term_months' => 36,
    'base_rent_monthly' => 50000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
    'has_percentage_rent' => true, 'percentage_rent_rate' => 10,
    'percentage_rent_calculation_type' => 'natural_breakpoint', 'percentage_rent_frequency' => 'monthly']);
// natural breakpoint = 50,000 / 10% = 500,000 of sales
qa_eq('sales at the natural breakpoint owe nothing', 0.0, $pct->calculate($decl($l2, '2026-03', 500000)));
qa_eq('sales below it owe nothing', 0.0, $pct->calculate($decl($l2, '2026-04', 400000)));
qa_eq('sales above it owe 10% less the base rent', round(800000 * 0.10 - 50000, 2), $pct->calculate($decl($l2, '2026-05', 800000)));

qa_section('PCT RENT 3 — tiered ladder charges each band only within it');
$l3 = $mk(['commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31', 'term_months' => 36,
    'base_rent_monthly' => 50000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
    'has_percentage_rent' => true, 'percentage_rent_rate' => 5,
    'percentage_rent_calculation_type' => 'tiered', 'percentage_rent_frequency' => 'monthly']);
LeasePercentageRentTier::create(['lease_id' => $l3->id, 'from_amount' => 0, 'to_amount' => 1000000, 'rate' => 0]);
LeasePercentageRentTier::create(['lease_id' => $l3->id, 'from_amount' => 1000000, 'to_amount' => 2000000, 'rate' => 5]);
LeasePercentageRentTier::create(['lease_id' => $l3->id, 'from_amount' => 2000000, 'to_amount' => null, 'rate' => 8]);
qa_eq('inside band 1 → nothing', 0.0, $pct->calculate($decl($l3, '2026-03', 900000)));
qa_eq('into band 2 → 5% of the band-2 slice only', round(500000 * 0.05, 2), $pct->calculate($decl($l3, '2026-04', 1500000)));
qa_eq('into band 3 → 5% of band 2 + 8% of band 3',
    round(1000000 * 0.05 + 500000 * 0.08, 2), $pct->calculate($decl($l3, '2026-05', 2500000)));

qa_section('PCT RENT 4 — a SHORT first year gets a SHORT annual breakpoint');
$l4 = $mk(['commencement_date' => '2026-10-01', 'expiry_date' => '2029-09-30', 'term_months' => 36,
    'base_rent_monthly' => 100000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
    'has_percentage_rent' => true, 'percentage_rent_threshold' => 12000000, 'percentage_rent_rate' => 6,
    'percentage_rent_calculation_type' => 'artificial', 'percentage_rent_frequency' => 'annual']);
// 3 of 12 months → factor 0.25 → effective threshold 3,000,000
$o = $pct->calculate($decl($l4, '2026-10', 4000000));
printf("  Oct-2026 sales 4,000,000 on a 12,000,000 annual breakpoint → overage %s\n", number_format($o, 2));
qa_eq('the breakpoint is pro-rated to the 3-month year', round((4000000 - 3000000) * 0.06, 2), $o, 0.02);
qa_ok('…so a short year is NOT silently under-billed', $o > 0);

qa_section('RELIEF — a bounded window ends by itself and resumes at the POST-STEP amount');
$l5 = $mk(['commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31', 'term_months' => 36,
    'base_rent_monthly' => 100000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
    'escalation_type' => 'fixed_percent', 'escalation_rate' => 10]);
$sched->projectTermEscalations($l5->fresh());
qa_eq('the ladder steps on 1 Jan 2027', 110000.00, $rentOn($l5, '2027-01-01'));
// relief 50% off, Oct 2026 → Mar 2027 — spanning the January step
app(LeaseReliefService::class)->grant($l5->fresh(), [
    'type' => 'base_rent', 'from' => '2026-10-01', 'to' => '2027-03-31', 'percent_off' => 50, 'reason' => 'QA COVID-style relief']);
$l5 = $l5->fresh('charges');
qa_eq('Sept 2026 (before the window) is full rent', 100000.00, $rentOn($l5, '2026-09-01'));
qa_eq('Nov 2026 is half the PRE-step rent', 50000.00, $rentOn($l5, '2026-11-01'));
qa_eq('Feb 2027 is half the POST-step rent (the step is not lost)', 55000.00, $rentOn($l5, '2027-02-01'));
qa_eq('Apr 2027 resumes at the FULL post-step rent by itself', 110000.00, $rentOn($l5, '2027-04-01'));
qa_eq('the 2028 step still lands', 121000.00, $rentOn($l5, '2028-06-01'));
qa_eq('an abatement event is recorded', 1, LeaseEvent::where('lease_id', $l5->id)->where('type', LeaseEvent::TYPE_ABATEMENT)->count());
qa_refuses('relief over 100% is refused',
    fn () => app(LeaseReliefService::class)->grant($l5->fresh(), ['type' => 'base_rent', 'from' => '2027-06-01', 'to' => '2027-07-31', 'percent_off' => 150, 'reason' => 'QA']),
    null, InvalidArgumentException::class);
qa_refuses('relief with neither a percentage nor an amount is refused',
    fn () => app(LeaseReliefService::class)->grant($l5->fresh(), ['type' => 'base_rent', 'from' => '2027-06-01', 'to' => '2027-07-31', 'reason' => 'QA']),
    null, InvalidArgumentException::class);

qa_section('PREMISES — expanding and contracting keeps the occupancy record');
$l6 = $mk(['commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31', 'term_months' => 36,
    'base_rent_monthly' => 80000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false]);
$extra = $freeUnits[$n++];
app(LeaseSpaceChangeService::class)->expand($l6->fresh(), [
    'unit_ids' => [$extra->id], 'effective_from' => '2026-07-01', 'new_total_rent' => 110000, 'reason' => 'QA expansion']);
$l6 = $l6->fresh(['charges', 'units']);
qa_eq('the extra unit is on the lease', 2, $l6->units()->count());
qa_eq('the extra unit reads occupied', 'occupied', $extra->fresh()->status);
qa_eq('rent before the expansion is unchanged', 80000.00, $rentOn($l6, '2026-06-01'));
qa_eq('rent from the expansion date is the new total', 110000.00, $rentOn($l6, '2026-07-01'));
qa_eq('an expansion event is recorded', 1, LeaseEvent::where('lease_id', $l6->id)->where('type', LeaseEvent::TYPE_EXPANSION)->count());

app(LeaseSpaceChangeService::class)->contract($l6->fresh(), [
    'unit_ids' => [$extra->id], 'effective_from' => '2027-01-01', 'new_total_rent' => 80000, 'reason' => 'QA give-back']);
$l6 = $l6->fresh(['charges', 'units']);
$pivot = DB::table('lease_unit')->where('lease_id', $l6->id)->where('unit_id', $extra->id)->first();
qa_ok('the give-back CLOSES the pivot row rather than deleting it', $pivot !== null,
    $pivot ? "effective_from={$pivot->effective_from} effective_to={$pivot->effective_to}" : 'ROW DELETED');
if ($pivot) {
    qa_eq('…closed the day before the give-back', '2026-12-31', substr((string) $pivot->effective_to, 0, 10));
}
qa_eq('the tenant still held the space in Sept 2026 (CAM must still see it)', 2,
    $l6->unitsOn(CarbonImmutable::parse('2026-09-01'))->count());
qa_eq('…and no longer holds it in Feb 2027', 1,
    $l6->unitsOn(CarbonImmutable::parse('2027-02-01'))->count());
// The give-back is dated 2027-01-01, which is in the FUTURE — so the unit is correctly still
// occupied today. Date-aware release is proven in 14_premises_dates.php on past-dated give-backs.
qa_eq('a FUTURE give-back leaves the unit occupied today', 'occupied', $extra->fresh()->status);
qa_eq('rent drops back from the give-back date', 80000.00, $rentOn($l6, '2027-01-01'));

qa_section('PREMISES refusals');
$other = Asset::where('code', 'PA')->first();
$foreign = $other ? Unit::where('asset_id', $other->id)->where('status', 'vacant')->first() : null;
if ($foreign) {
    qa_refuses('a unit from another property cannot be added',
        fn () => app(LeaseSpaceChangeService::class)->expand($l6->fresh(),
            ['unit_ids' => [$foreign->id], 'effective_from' => '2027-06-01', 'new_total_rent' => 90000, 'reason' => 'QA']),
        null, InvalidArgumentException::class);
}
$taken = Unit::where('asset_id', $asset->id)->where('status', 'occupied')->where('id', '!=', $l6->unit_id)->first();
qa_refuses('a unit already let to someone else cannot be added',
    fn () => app(LeaseSpaceChangeService::class)->expand($l6->fresh(),
        ['unit_ids' => [$taken->id], 'effective_from' => '2027-06-01', 'new_total_rent' => 90000, 'reason' => 'QA']),
    null, InvalidArgumentException::class);

qa_summary();
