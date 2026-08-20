<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\ChargeScheduleService;
use App\Services\LeaseCreationService;
use App\Services\LeaseExtensionService;
use App\Services\LeaseRenewalService;
use App\Services\MonthlyBillingService;
use App\Services\RentEscalationService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

$asset = Asset::where('code', 'AW')->firstOrFail();
$tenant = Tenant::whereDoesntHave('unitOwnerships')->firstOrFail();
$freeUnits = Unit::where('asset_id', $asset->id)->where('status', 'vacant')->orderBy('id')->get();
$n = 0;
$sched = app(ChargeScheduleService::class);
$mk = function (array $a) use (&$n, $freeUnits, $asset, $tenant): Lease {
    $u = $freeUnits[$n++];
    $l = Lease::create(array_merge([
        'asset_id' => $asset->id, 'tenant_id' => $tenant->id, 'unit_id' => $u->id,
        'reference' => 'QA-'.strtoupper(bin2hex(random_bytes(3))), 'status' => 'active', 'currency' => 'EGP',
        'billing_frequency' => 'monthly', 'payment_terms_days' => 7,
    ], $a));
    LeaseCreationService::seedStandardCharges($l, (float) $l->base_rent_monthly, (float) $l->service_charge_monthly, $l->commencement_date);

    return $l->fresh('charges');
};
$rentOn = fn (Lease $l, string $d) => (float) optional(ChargeScheduleService::pickInForce(
    $l->fresh('charges')->charges->where('type', 'base_rent'), CarbonImmutable::parse($d)))->amount;

qa_section('LIFECYCLE 1 — escalation ladder is projected at signing');
$l = $mk(['commencement_date' => '2026-01-01', 'expiry_date' => '2029-12-31', 'term_months' => 48,
    'base_rent_monthly' => 100000, 'service_charge_monthly' => 0,
    'escalation_type' => 'fixed_percent', 'escalation_rate' => 10, 'has_marketing_levy' => false]);
$created = $sched->projectTermEscalations($l->fresh());
printf("  rows projected: %d\n", $created);
qa_eq('rent in year 1', 100000.00, $rentOn($l, '2026-06-01'));
qa_eq('rent after the 1st anniversary (+10%)', 110000.00, $rentOn($l, '2027-01-01'));
qa_eq('rent after the 2nd (+10% compounding)', 121000.00, $rentOn($l, '2028-01-01'));
qa_eq('rent after the 3rd', 133100.00, $rentOn($l, '2029-01-01'));
qa_eq('no step past expiry', 133100.00, $rentOn($l, '2029-12-31'));
$rows = $l->fresh('charges')->charges->where('type', 'base_rent')->sortBy('start_date')->values();
qa_ok('each row closes the day before the next opens',
    $rows->every(fn ($r, $i) => $i === 0 || $rows[$i - 1]->end_date?->format('Y-m-d') === CarbonImmutable::parse($r->start_date)->subDay()->format('Y-m-d')),
    $rows->map(fn ($r) => $r->start_date?->format('Y-m-d').'→'.($r->end_date?->format('Y-m-d') ?? '∞'))->join(' | '));

qa_section('LIFECYCLE 2 — billing follows the ladder, not the lease column');
$billing = app(MonthlyBillingService::class);
$p26 = $billing->planInvoiceForLease($l->fresh('charges'), CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-30'));
$p27 = $billing->planInvoiceForLease($l->fresh('charges'), CarbonImmutable::parse('2027-06-01'), CarbonImmutable::parse('2027-06-30'));
qa_eq('June 2026 bills the year-1 rent', 100000.00, $p26['subtotal']);
qa_eq('June 2027 bills the year-2 rent', 110000.00, $p27['subtotal']);

qa_section('LIFECYCLE 3 — the escalation sweep is dated to the ANNIVERSARY and is idempotent');
$l2 = $mk(['commencement_date' => '2025-09-01', 'expiry_date' => '2028-08-31', 'term_months' => 36,
    'base_rent_monthly' => 50000, 'service_charge_monthly' => 0,
    'escalation_type' => 'fixed_percent', 'escalation_rate' => 7, 'has_marketing_levy' => false]);
$l2->forceFill(['next_escalation_date' => '2026-09-01'])->save();
$esc = app(RentEscalationService::class);
$r1 = $esc->runForToday(CarbonImmutable::parse('2026-09-05'));   // the sweep runs LATE
printf("  sweep: %s\n", json_encode($r1));
$l2 = $l2->fresh('charges');
qa_eq('rent stepped to +7%', 53500.00, (float) $l2->base_rent_monthly);
qa_eq('the step is dated to the anniversary, NOT the day the sweep ran', 53500.00, $rentOn($l2, '2026-09-01'));
qa_eq('…and August still bills the old rent', 50000.00, $rentOn($l2, '2026-08-31'));
qa_eq('next_escalation_date rolled one year', '2027-09-01', $l2->next_escalation_date?->format('Y-m-d'));
$r2 = $esc->runForToday(CarbonImmutable::parse('2026-09-06'));
qa_eq('a second sweep the next day applies nothing', 0, $r2['applied']);
qa_eq('rent unchanged after the second sweep', 53500.00, (float) $l2->fresh()->base_rent_monthly);

qa_section('LIFECYCLE 4 — the collar clamps a mistyped rate');
$l3 = $mk(['commencement_date' => '2025-09-01', 'expiry_date' => '2028-08-31', 'term_months' => 36,
    'base_rent_monthly' => 50000, 'service_charge_monthly' => 0, 'escalation_type' => 'fixed_percent',
    'escalation_rate' => 70, 'escalation_ceiling_rate' => 10, 'has_marketing_levy' => false]);
$l3->forceFill(['next_escalation_date' => '2026-09-01'])->save();
qa_eq('collar clamps 70% to the 10% ceiling', 10.0, RentEscalationService::collar($l3->fresh(), 70));
$esc->runForToday(CarbonImmutable::parse('2026-09-01'));
qa_eq('a 70% typo escalates by 10%, not 70%', 55000.00, (float) $l3->fresh()->base_rent_monthly);

$l4 = $mk(['commencement_date' => '2025-09-01', 'expiry_date' => '2028-08-31', 'term_months' => 36,
    'base_rent_monthly' => 50000, 'service_charge_monthly' => 0, 'escalation_type' => 'fixed_percent',
    'escalation_rate' => 1, 'escalation_floor_rate' => 3, 'has_marketing_levy' => false]);
$l4->forceFill(['next_escalation_date' => '2026-09-01'])->save();
$esc->runForToday(CarbonImmutable::parse('2026-09-01'));
qa_eq('a floor lifts a below-floor rate', 51500.00, (float) $l4->fresh()->base_rent_monthly);

qa_section('LIFECYCLE 5 — fixed_amount escalation (no collar, by design)');
$l5 = $mk(['commencement_date' => '2025-09-01', 'expiry_date' => '2028-08-31', 'term_months' => 36,
    'base_rent_monthly' => 50000, 'service_charge_monthly' => 0, 'escalation_type' => 'fixed_amount',
    'escalation_amount' => 4000, 'escalation_ceiling_rate' => 1, 'has_marketing_levy' => false]);
$l5->forceFill(['next_escalation_date' => '2026-09-01'])->save();
$esc->runForToday(CarbonImmutable::parse('2026-09-01'));
qa_eq('an amount step adds EGP, and a percent ceiling does not clamp it', 54000.00, (float) $l5->fresh()->base_rent_monthly);

qa_section('LIFECYCLE 6 — CPI is never invented');
$l6 = $mk(['commencement_date' => '2025-09-01', 'expiry_date' => '2028-08-31', 'term_months' => 36,
    'base_rent_monthly' => 50000, 'service_charge_monthly' => 0, 'escalation_type' => 'cpi',
    'escalation_rate' => 5, 'has_marketing_levy' => false]);
$l6->forceFill(['next_escalation_date' => '2026-09-01'])->save();
$before = (float) $l6->base_rent_monthly;
$esc->runForToday(CarbonImmutable::parse('2026-09-01'));
qa_eq('a CPI lease is skipped, not guessed at', $before, (float) $l6->fresh()->base_rent_monthly);

qa_section('LIFECYCLE 7 — extension');
$ext = app(LeaseExtensionService::class);
$l7 = $mk(['commencement_date' => '2026-01-01', 'expiry_date' => '2027-12-31', 'term_months' => 24,
    'base_rent_monthly' => 60000, 'service_charge_monthly' => 0, 'escalation_type' => 'fixed_percent',
    'escalation_rate' => 5, 'has_marketing_levy' => false]);
$sched->projectTermEscalations($l7->fresh());
qa_refuses('an extension cannot pull the expiry BACKWARDS',
    fn () => $ext->extend($l7->fresh(), ['new_expiry_date' => '2027-06-30', 'reason' => 'QA']));
$ext->extend($l7->fresh(), ['new_expiry_date' => '2029-12-31', 'reason' => 'Further term agreed']);
$l7 = $l7->fresh('charges');
qa_eq('expiry moved forward', '2029-12-31', $l7->expiry_date?->format('Y-m-d'));
qa_eq('term_months re-derived from the date', 48, (int) $l7->term_months);
qa_eq('escalations re-projected into the new years', round(60000 * 1.05 * 1.05 * 1.05, 2), $rentOn($l7, '2029-06-01'));
qa_eq('an extension event is recorded', 1, LeaseEvent::where('lease_id', $l7->id)->where('type', LeaseEvent::TYPE_EXTENSION)->count());

qa_section('LIFECYCLE 8 — renewal is a NEW lease, not an edit');
$ren = app(LeaseRenewalService::class);
$l8 = $mk(['commencement_date' => '2024-01-01', 'expiry_date' => '2026-12-31', 'term_months' => 36,
    'base_rent_monthly' => 70000, 'service_charge_monthly' => 15000, 'security_deposit' => 210000,
    'escalation_type' => 'fixed_percent', 'escalation_rate' => 8, 'escalation_ceiling_rate' => 12, 'has_marketing_levy' => false]);
$new = $ren->renew($l8->fresh(), ['new_term_months' => 24, 'new_rent' => 84000, 'new_service_charge' => 18000]);
$l8 = $l8->fresh();
qa_eq('the original is marked renewed', 'renewed', $l8->status);
qa_eq('the renewal points back at it', $l8->id, $new->previous_lease_id);
qa_eq('the renewal starts the day after the old expiry', '2027-01-01', $new->commencement_date?->format('Y-m-d'));
qa_eq('term applied', '2028-12-31', $new->expiry_date?->format('Y-m-d'));
qa_eq('new rent', 84000.00, (float) $new->base_rent_monthly);
qa_eq('the escalation COLLAR is carried (the silently-dropped-terms bug)', 12.0, (float) $new->escalation_ceiling_rate);
qa_eq('the escalation rate is carried', 8.0, (float) $new->escalation_rate);
qa_ok('next_escalation_date is armed on the renewal', $new->next_escalation_date !== null, (string) $new->next_escalation_date);
qa_eq('the unit stays occupied (one active lease on it)', 'occupied', Unit::find($new->unit_id)->status);
qa_eq('…and only ONE lease is active on that unit', 1,
    Lease::where('unit_id', $new->unit_id)->where('status', 'active')->count());
qa_refuses('a renewed lease cannot be renewed again',
    fn () => $ren->renew($l8->fresh(), ['new_term_months' => 12, 'new_rent' => 90000]), null, InvalidArgumentException::class);

qa_section('LIFECYCLE 9 — double-booking is refused');
$occupied = Unit::find($new->unit_id);
qa_refuses('a second active lease on a let unit is refused',
    fn () => app(LeaseCreationService::class)->create([
        'tenant_mode' => 'existing', 'tenant_id' => $tenant->id,
        'lease' => ['unit_id' => $occupied->id, 'commencement_date' => '2027-06-01', 'term_months' => 12,
            'base_rent_monthly' => 50000, 'service_charge_monthly' => 0],
    ]), null, ValidationException::class);

qa_summary();
