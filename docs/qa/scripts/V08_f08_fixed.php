<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Lease;
use App\Models\LeaseCamTerm;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\CamReconciliationService;
use App\Services\LeaseCreationService;
use App\Services\Reconciliation\BooksReconciliationService;

$asset = Asset::create(['name' => 'QA CAM Mall', 'code' => 'QCM', 'type' => 'mall', 'city' => 'Cairo', 'country' => 'Egypt',
    'currency' => 'EGP', 'is_active' => true]);
$cam = app(CamReconciliationService::class);
$tenants = Tenant::whereDoesntHave('unitOwnerships')->take(4)->get();
$mk = function (Tenant $t, string $code, float $area) use ($asset): Lease {
    $u = Unit::create(['asset_id' => $asset->id, 'code' => $code, 'category' => 'retail', 'area_sqm' => $area, 'status' => 'vacant']);
    $l = Lease::create(['tenant_id' => $t->id, 'unit_id' => $u->id, 'reference' => 'QCM-'.$code, 'status' => 'active',
        'currency' => 'EGP', 'commencement_date' => '2025-01-01', 'expiry_date' => '2035-12-31', 'term_months' => 132,
        'base_rent_monthly' => 10000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
        'billing_frequency' => 'monthly', 'billing_day' => 1, 'payment_terms_days' => 7, 'escalation_type' => 'none']);
    LeaseCreationService::seedStandardCharges($l, 10000, 0, $l->commencement_date);

    return $l->fresh();
};
$term = fn (Lease $l, int $year, float $pct) => LeaseCamTerm::create(['lease_id' => $l->id,
    'effective_year' => $year, 'stated_share_pct' => $pct, 'cap_type' => 'absolute',
    'cap_scope' => LeaseCamTerm::SCOPE_TOTAL, 'cap_carry_forward' => false]);
$pool = fn (int $year, float $actual) => CamExpensePool::create(['asset_id' => $asset->id,
    'name' => "QCM $year", 'period_year' => $year, 'total_actual_expense' => $actual,
    'total_estimated_collected' => 0, 'status' => 'draft', 'estimate_basis' => 'stated',
    'recovery_vat_rate' => 14, 'admin_fee_pct' => 0]);
$a = $mk($tenants[0], 'Q-01', 250);
$b = $mk($tenants[1], 'Q-02', 250);
$c = $mk($tenants[2], 'Q-03', 250);
$d = $mk($tenants[3], 'Q-04', 250);

qa_section('CONTROL — no stated share: unchanged behaviour');
$p1 = $pool(2030, 1000000);
$cam->generateAllocations($p1->fresh());
$al1 = CamAllocation::where('cam_expense_pool_id', $p1->id)->get();
qa_eq('Σ shares = 100%', 100.0, round((float) $al1->sum('pro_rata_share_pct'), 4), 0.01);
qa_eq('Σ allocated = the pool', 1000000.00, round((float) $al1->sum('allocated_amount'), 2), 0.05);

qa_section('F-08 — a stated share BELOW the area share still leaves neighbours alone');
$term($a, 2031, 10);
$p2 = $pool(2031, 1000000);
$cam->generateAllocations($p2->fresh());
$al2 = CamAllocation::where('cam_expense_pool_id', $p2->id)->with('lease')->get();
foreach ($al2 as $x) {
    printf("    %-8s area=%s  share=%8s%%  allocated=%13s\n", $x->lease->reference,
        $x->lease->unit->area_sqm, $x->pro_rata_share_pct, number_format((float) $x->allocated_amount, 2));
}
qa_eq('the stated 10% is honoured', 10.0, (float) $al2->firstWhere('lease_id', $a->id)->pro_rata_share_pct, 0.01);
qa_eq('the neighbours keep their own 25% (their leases say pro-rata)', 25.0,
    (float) $al2->firstWhere('lease_id', $b->id)->pro_rata_share_pct, 0.01);
qa_eq('…so 85% is recovered and the LANDLORD bears the rest', 850000.00, round((float) $al2->sum('allocated_amount'), 2), 0.05);
$p2->refresh();
qa_eq('the shortfall is recorded as landlord-borne, never moved to a neighbour', 150000.00,
    round((float) $p2->landlord_unrecovered_amount, 2), 0.05);
qa_ok('nothing is OVER-recovered', round((float) $al2->sum('allocated_amount'), 2) <= 1000000.05);

qa_section('two stated shares, still within the pool');
$term($a, 2032, 30);
$term($b, 2032, 10);
$p3 = $pool(2032, 1000000);
$cam->generateAllocations($p3->fresh());
$al3 = CamAllocation::where('cam_expense_pool_id', $p3->id)->get();
printf("  shares: %s → Σ %s%%\n", $al3->pluck('pro_rata_share_pct')->join(' + '), round((float) $al3->sum('pro_rata_share_pct'), 4));
qa_ok('Σ shares never exceeds 100%', round((float) $al3->sum('pro_rata_share_pct'), 4) <= 100.02,
    round((float) $al3->sum('pro_rata_share_pct'), 4).'%');
qa_eq('the unstated keep their area share', 25.0,
    (float) $al3->firstWhere('lease_id', $c->id)->pro_rata_share_pct, 0.01);

qa_section('stated shares that over-commit the pool are REFUSED');
$term($a, 2033, 70);
$term($b, 2033, 50);
$p4 = $pool(2033, 1000000);
qa_refuses('120% of stated shares is refused, not billed', fn () => $cam->generateAllocations($p4->fresh()), 'more than the pool');

qa_section('every participant stated at exactly their area share → the pool ties out');
$term($a, 2034, 25);
$term($b, 2034, 25);
$term($c, 2034, 25);
$term($d, 2034, 25);
$p5 = $pool(2034, 1000000);
$cam->generateAllocations($p5->fresh());
$al5 = CamAllocation::where('cam_expense_pool_id', $p5->id)->get();
qa_eq('Σ shares = 100%', 100.0, round((float) $al5->sum('pro_rata_share_pct'), 4), 0.02);
qa_eq('Σ allocated = the pool', 1000000.00, round((float) $al5->sum('allocated_amount'), 2), 0.05);

qa_section('the reconciliation now has an INDEPENDENT over-recovery check');
$rec = app(BooksReconciliationService::class);
$camCheck = fn () => collect($rec->run(null, false)['checks'] ?? [])->firstWhere('key', 'cam_allocations');
qa_ok('clean on the fixed data', $camCheck()['passed'] === true);
// force an over-recovery the generator can no longer produce, and prove the check sees it
$alloc = CamAllocation::where('cam_expense_pool_id', $p2->id)->first();
$orig = (float) $alloc->allocated_amount;
$alloc->forceFill(['allocated_amount' => $orig + 250000])->saveQuietly();
$p2->forceFill(['landlord_unrecovered_amount' => -250000])->saveQuietly();  // as the generator would have stored it
$after = $camCheck();
printf("  with a forced 250,000 over-recovery: passed=%s, %d discrepancy(ies)\n",
    var_export($after['passed'], true), count($after['discrepancies']));
foreach ($after['discrepancies'] as $x) {
    printf("    %s — %s\n", $x['ref'], mb_substr($x['detail'], 0, 120));
}
qa_ok('the over-recovery is reported even though the stored residual "balances" it',
    $after['passed'] === false);
$alloc->forceFill(['allocated_amount' => $orig])->saveQuietly();
$p2->forceFill(['landlord_unrecovered_amount' => 0])->saveQuietly();

qa_summary();
