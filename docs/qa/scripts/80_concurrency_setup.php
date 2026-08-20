<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\PostDatedCheque;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\LeaseCreationService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

$asset = Asset::where('code', 'AW')->firstOrFail();
$tenant = Tenant::whereDoesntHave('unitOwnerships')->firstOrFail();
$free = Unit::where('asset_id', $asset->id)->where('status', 'vacant')->orderBy('id')->get();

// 1. a vacant unit two racers will both try to lease
$raceUnit = $free[0];
// 2. an active lease both will try to bill for September
$l = Lease::create(['tenant_id' => $tenant->id, 'unit_id' => $free[1]->id, 'reference' => 'RACE-BILL',
    'status' => 'active', 'currency' => 'EGP', 'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31',
    'term_months' => 36, 'base_rent_monthly' => 40000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
    'billing_frequency' => 'monthly', 'payment_terms_days' => 7, 'escalation_type' => 'none']);
LeaseCreationService::seedStandardCharges($l, 40000, 0, $l->commencement_date);
// 3. an invoice with exactly 30,000 open that two racers will each try to pay 30,000 against
$l2 = Lease::create(['tenant_id' => $tenant->id, 'unit_id' => $free[2]->id, 'reference' => 'RACE-PAY',
    'status' => 'active', 'currency' => 'EGP', 'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31',
    'term_months' => 36, 'base_rent_monthly' => 30000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
    'billing_frequency' => 'monthly', 'payment_terms_days' => 7, 'escalation_type' => 'none']);
LeaseCreationService::seedStandardCharges($l2, 30000, 0, $l2->commencement_date);
$inv = app(MonthlyBillingService::class)->generateForLease($l2->fresh('charges'), CarbonImmutable::parse('2026-08-01'))['invoice'];
// 4. a held cheque two racers will both try to clear
$pdc = PostDatedCheque::create(['tenant_id' => $tenant->id, 'lease_id' => $l2->id, 'asset_id' => $asset->id,
    'reference' => 'PDC-RACE-'.uniqid(), 'cheque_number' => 'RACE'.random_int(100000, 999999), 'bank_name' => 'CIB',
    'amount' => 15000, 'cheque_date' => '2026-08-15', 'received_date' => '2026-07-01', 'status' => 'held']);

file_put_contents(__DIR__.'/race_ids.json', json_encode([
    'unit' => $raceUnit->id, 'tenant' => $tenant->id, 'lease' => $l->id, 'invoice' => $inv->id, 'pdc' => $pdc->id,
]));
echo 'race fixtures: '.file_get_contents(__DIR__.'/race_ids.json')."\n";
