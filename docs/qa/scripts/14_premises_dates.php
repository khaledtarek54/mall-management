<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\LeaseCreationService;
use App\Services\LeaseSpaceChangeService;
use Illuminate\Support\Carbon;

$asset = Asset::where('code', 'AW')->firstOrFail();
$tenant = Tenant::whereDoesntHave('unitOwnerships')->firstOrFail();
$free = Unit::where('asset_id', $asset->id)->where('status', 'vacant')->orderBy('id')->get();
$mk = function (int $i) use ($free, $asset, $tenant): Lease {
    $l = Lease::create(['asset_id' => $asset->id, 'tenant_id' => $tenant->id, 'unit_id' => $free[$i]->id,
        'reference' => 'QA-'.strtoupper(bin2hex(random_bytes(3))), 'status' => 'active', 'currency' => 'EGP',
        'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31', 'term_months' => 36,
        'base_rent_monthly' => 80000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
        'billing_frequency' => 'monthly', 'payment_terms_days' => 7, 'escalation_type' => 'none']);
    LeaseCreationService::seedStandardCharges($l, 80000, 0, $l->commencement_date);

    return $l->fresh();
};
$svc = app(LeaseSpaceChangeService::class);

qa_section('PREMISES — occupancy is DATE-AWARE (today is '.now()->toDateString().')');
$l = $mk(0);
$extra = $free[1];
$svc->expand($l->fresh(), ['unit_ids' => [$extra->id], 'effective_from' => '2027-06-01', 'new_total_rent' => 110000, 'reason' => 'QA future expansion']);
qa_eq('a FUTURE expansion marks the unit RESERVED, not occupied', 'reserved', $extra->fresh()->status);

$l2 = $mk(2);
$extra2 = $free[3];
$svc->expand($l2->fresh(), ['unit_ids' => [$extra2->id], 'effective_from' => '2026-02-01', 'new_total_rent' => 110000, 'reason' => 'QA past expansion']);
qa_eq('a PAST expansion marks the unit occupied', 'occupied', $extra2->fresh()->status);

$svc->contract($l2->fresh(), ['unit_ids' => [$extra2->id], 'effective_from' => '2026-06-01', 'new_total_rent' => 80000, 'reason' => 'QA past give-back']);
qa_eq('a PAST give-back frees the unit immediately', 'vacant', $extra2->fresh()->status);
qa_ok('…and the unit can be re-let', ! $extra2->fresh()->isActivelyLeased());

$l3 = $mk(4);
$extra3 = $free[5];
$svc->expand($l3->fresh(), ['unit_ids' => [$extra3->id], 'effective_from' => '2026-02-01', 'new_total_rent' => 110000, 'reason' => 'QA']);
$svc->contract($l3->fresh(), ['unit_ids' => [$extra3->id], 'effective_from' => '2027-01-01', 'new_total_rent' => 80000, 'reason' => 'QA future give-back']);
qa_eq('a FUTURE give-back leaves the unit occupied TODAY (correct)', 'occupied', $extra3->fresh()->status);
qa_ok('…but the unit is already spoken-for-until-then, so it cannot be double-let now',
    $extra3->fresh()->isActivelyLeased());

qa_section('…does the status ever catch up when the give-back date arrives?');
// simulate: the pivot has closed, nothing has touched the unit since
Carbon::setTestNow('2027-03-01');
$fresh = Unit::find($extra3->id);
printf("  stored status on 2027-03-01 (nothing re-projected): %s\n", $fresh->status);
$heldNow = Lease::constrainToCurrentlyHeld($fresh->allLeases())->pluck('leases.status');
printf("  currently-held leases on that date: %s\n", $heldNow->isEmpty() ? '(none)' : $heldNow->join(','));
qa_ok('the PROJECTION says vacant once the date passes', $heldNow->isEmpty());
qa_ok('the STORED column is stale until something re-projects it', $fresh->status === 'occupied',
    'stored='.$fresh->status.' — is there a nightly re-projection?');
$fresh->recomputeStatus();
qa_eq('an explicit recompute fixes it', 'vacant', $fresh->fresh()->status);
Carbon::setTestNow();

qa_summary();
