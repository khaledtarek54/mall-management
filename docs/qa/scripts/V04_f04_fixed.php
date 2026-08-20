<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\ConvertLeaseToHoldoverService;
use App\Services\LeaseCreationService;
use App\Services\RentEscalationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

$asset = Asset::where('code', 'AW')->firstOrFail();
$tenant = Tenant::whereDoesntHave('unitOwnerships')->firstOrFail();
$free = Unit::where('asset_id', $asset->id)->where('status', 'vacant')->orderBy('id')->get();
$n = 0;
$mk = function (array $a) use (&$n, $free, $tenant): Lease {
    $l = Lease::create(array_merge(['tenant_id' => $tenant->id, 'unit_id' => $free[$n++]->id,
        'reference' => 'QA-EX-'.strtoupper(bin2hex(random_bytes(3))), 'status' => 'active', 'currency' => 'EGP',
        'base_rent_monthly' => 100000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
        'billing_frequency' => 'monthly', 'payment_terms_days' => 7], $a));
    LeaseCreationService::seedStandardCharges($l, (float) $l->base_rent_monthly, 0, $l->commencement_date);

    return $l->fresh();
};

qa_section('F-04 FIXED — the sweep exists and is scheduled');
qa_ok('leases:expire is a registered command', collect(Artisan::all())->keys()->contains('leases:expire'));
qa_ok('…and it is scheduled', str_contains(file_get_contents(base_path('routes/console.php')), "Schedule::command('leases:expire')"));

qa_section('an ended lease is expired and its unit freed');
$ended = $mk(['commencement_date' => '2023-01-01', 'expiry_date' => '2026-01-31', 'term_months' => 37,
    'escalation_type' => 'fixed_percent', 'escalation_rate' => 10]);
$ended->forceFill(['next_escalation_date' => '2026-08-01'])->save();
$unit = Unit::find($ended->unit_id);
qa_eq('before the sweep the unit is occupied', 'occupied', $unit->fresh()->status);

$live = $mk(['commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31', 'term_months' => 36]);
$ho = $mk(['commencement_date' => '2024-01-01', 'expiry_date' => '2026-07-31', 'term_months' => 31]);
app(ConvertLeaseToHoldoverService::class)->convert($ho->fresh(), ['effective_from' => '2026-08-01', 'rate_pct' => 125, 'reason' => 'QA']);

Artisan::call('leases:expire');
echo '  '.trim(str_replace("\n", "\n  ", Artisan::output()))."\n";

qa_eq('the ended lease is now expired', 'expired', $ended->fresh()->status);
qa_eq('…and its unit is vacant', 'vacant', $unit->fresh()->status);
qa_ok('…so it can be re-let', ! $unit->fresh()->isActivelyLeased());
qa_eq('a live lease is untouched', 'active', $live->fresh()->status);
qa_eq('a converted HOLDOVER is untouched (its expiry is past by design)', 'active', $ho->fresh()->status);
qa_eq('…and its unit stays occupied', 'occupied', Unit::find($ho->unit_id)->fresh()->status);

qa_section('the escalation sweep no longer steps a dead lease');
$dead = $mk(['commencement_date' => '2023-01-01', 'expiry_date' => '2026-01-31', 'term_months' => 37,
    'escalation_type' => 'fixed_percent', 'escalation_rate' => 10]);
$dead->forceFill(['next_escalation_date' => '2026-08-01'])->save();
$before = (float) $dead->base_rent_monthly;
$stats = app(RentEscalationService::class)->runForToday(CarbonImmutable::parse('2026-08-19'));
printf("  sweep: %s\n", json_encode($stats));
qa_eq('the ended lease keeps its rent', $before, (float) $dead->fresh()->base_rent_monthly);
qa_eq('…and no schedule row was written past its expiry', 1,
    Charge::where('lease_id', $dead->id)->where('type', 'base_rent')->count());

qa_section('…but a holdover still escalates');
$ho2 = $mk(['commencement_date' => '2023-01-01', 'expiry_date' => '2026-02-28', 'term_months' => 38,
    'escalation_type' => 'fixed_percent', 'escalation_rate' => 10]);
app(ConvertLeaseToHoldoverService::class)->convert($ho2->fresh(), ['effective_from' => '2026-03-01', 'rate_pct' => 100, 'reason' => 'QA']);
$ho2 = $ho2->fresh();
$ho2->forceFill(['next_escalation_date' => '2026-08-01'])->save();
$hoBefore = (float) $ho2->base_rent_monthly;
app(RentEscalationService::class)->runForToday(CarbonImmutable::parse('2026-08-19'));
qa_ok('a holdover lease is still escalated', (float) $ho2->fresh()->base_rent_monthly > $hoBefore,
    number_format($hoBefore, 2).' → '.number_format((float) $ho2->fresh()->base_rent_monthly, 2));

qa_section('F-05 — a stale unit status is re-projected by the same sweep');
$l3 = $mk(['commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31', 'term_months' => 36]);
$u3 = Unit::find($l3->unit_id);
$u3->forceFill(['status' => 'vacant'])->saveQuietly();   // simulate a stored value gone stale
qa_eq('the stored status is wrong', 'vacant', $u3->fresh()->status);
Artisan::call('leases:expire');
qa_eq('the sweep corrects it', 'occupied', $u3->fresh()->status);
$m = Unit::where('asset_id', $asset->id)->where('status', '!=', 'maintenance')->first();
$m->forceFill(['status' => 'maintenance'])->saveQuietly();
Artisan::call('leases:expire');
qa_eq('a maintenance override is never overwritten', 'maintenance', $m->fresh()->status);

qa_section('the sweep is idempotent');
Artisan::call('leases:expire');
$out = Artisan::output();
qa_ok('a second run changes nothing', str_contains($out, 'Re-projected 0 unit'), trim(str_replace("\n", ' · ', $out)));

qa_summary();
