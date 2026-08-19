<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\LeaseCreationService;
use App\Services\MonthlyBillingService;
use App\Services\RentEscalationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;

qa_section('FINDING — nothing ever moves a lease from active to expired');
$cmds = collect(Artisan::all())->keys();
qa_ok('there IS an expiry sweep for vendor contracts', $cmds->contains('vendors:expire-contracts'));
qa_ok('…and NO equivalent for leases', $cmds->filter(fn ($c) => str_starts_with($c, 'leases:'))->doesntContain('leases:expire'),
    'leases:* = '.$cmds->filter(fn ($c) => str_starts_with($c, 'leases:'))->join(', '));

$asset = Asset::where('code', 'AW')->firstOrFail();
$tenant = Tenant::whereDoesntHave('unitOwnerships')->firstOrFail();
$unit = Unit::where('asset_id', $asset->id)->where('status', 'vacant')->firstOrFail();
$l = Lease::create(['asset_id' => $asset->id, 'tenant_id' => $tenant->id, 'unit_id' => $unit->id,
    'reference' => 'QA-EXP-'.strtoupper(bin2hex(random_bytes(2))), 'status' => 'active', 'currency' => 'EGP',
    'commencement_date' => '2023-01-01', 'expiry_date' => '2026-01-31', 'term_months' => 37,
    'base_rent_monthly' => 100000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
    'billing_frequency' => 'monthly', 'billing_day' => 1, 'payment_terms_days' => 7,
    'escalation_type' => 'fixed_percent', 'escalation_rate' => 10]);
LeaseCreationService::seedStandardCharges($l, 100000, 0, $l->commencement_date);
$l->forceFill(['next_escalation_date' => '2026-08-01'])->save();
$l = $l->fresh('charges');
printf("  lease expired %s · today is %s · status is still '%s'\n",
    $l->expiry_date->format('Y-m-d'), now()->toDateString(), $l->status);

qa_eq('the term ended 7 months ago and the lease is still ACTIVE', 'active', $l->fresh()->status);
qa_eq('the unit still reads occupied', 'occupied', $unit->fresh()->status);
qa_ok('…so it is counted in the property occupancy rate',
    $asset->fresh()->units()->where('status', 'occupied')->pluck('id')->contains($unit->id));

qa_section('consequence 1 — the escalation sweep still steps an expired lease');
$before = (float) $l->base_rent_monthly;
$stats = app(RentEscalationService::class)->runForToday(CarbonImmutable::parse('2026-08-19'));
$after = (float) $l->fresh()->base_rent_monthly;
printf("  sweep: %s · rent %s → %s\n", json_encode($stats), number_format($before, 2), number_format($after, 2));
qa_ok('rent was escalated on a lease whose term ended in January', $after > $before,
    'a schedule row was written for a period the tenancy no longer covers');
$rows = Charge::where('lease_id', $l->id)->where('type', 'base_rent')->orderBy('start_date')->get();
qa_ok('…and the new rent row starts AFTER the lease expired',
    $rows->last()->start_date->gt($l->expiry_date),
    'row starts '.$rows->last()->start_date->format('Y-m-d').' vs expiry '.$l->expiry_date->format('Y-m-d'));

qa_section('consequence 2 — billing correctly refuses, so no wrong invoice is raised');
$p = app(MonthlyBillingService::class)->planInvoiceForLease($l->fresh('charges'),
    CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
qa_ok('billing still refuses an ended lease', ! $p['billable'], $p['reason']);

qa_section('consequence 3 — the unit cannot be re-let while the dead lease stands');
qa_ok('the unit reads as actively leased', $unit->fresh()->isActivelyLeased());
qa_refuses('a new lease on it is refused',
    fn () => app(LeaseCreationService::class)->create([
        'tenant_mode' => 'existing', 'tenant_id' => $tenant->id,
        'lease' => ['unit_id' => $unit->id, 'commencement_date' => '2026-09-01', 'term_months' => 12,
            'base_rent_monthly' => 90000, 'service_charge_monthly' => 0]]),
    null, ValidationException::class);

qa_summary();
