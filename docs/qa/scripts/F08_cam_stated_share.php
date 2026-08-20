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
    $l = Lease::create(['tenant_id' => $t->id, 'unit_id' => $u->id,
        'reference' => 'QCM-'.$code, 'status' => 'active', 'currency' => 'EGP',
        'commencement_date' => '2025-01-01', 'expiry_date' => '2035-12-31', 'term_months' => 132,
        'base_rent_monthly' => 10000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
        'billing_frequency' => 'monthly', 'payment_terms_days' => 7, 'escalation_type' => 'none']);
    LeaseCreationService::seedStandardCharges($l, 10000, 0, $l->commencement_date);

    return $l->fresh();
};
$a = $mk($tenants[0], 'Q-01', 250);
$b = $mk($tenants[1], 'Q-02', 250);
$c = $mk($tenants[2], 'Q-03', 250);
$d = $mk($tenants[3], 'Q-04', 250);

qa_section('CONTROL — four equal shops, no stated share: the pool ties out exactly');
$p1 = CamExpensePool::create(['asset_id' => $asset->id, 'name' => 'QCM 2030', 'period_year' => 2030,
    'total_actual_expense' => 1000000, 'total_estimated_collected' => 0, 'status' => 'draft',
    'estimate_basis' => 'stated', 'recovery_vat_rate' => 14, 'admin_fee_pct' => 0]);
$cam->generateAllocations($p1->fresh());
$al1 = CamAllocation::where('cam_expense_pool_id', $p1->id)->get();
printf("  shares: %s  → Σ %s%%\n", $al1->pluck('pro_rata_share_pct')->join(' + '), $al1->sum('pro_rata_share_pct'));
qa_eq('Σ shares = 100%', 100.0, round((float) $al1->sum('pro_rata_share_pct'), 4), 0.01);
qa_eq('Σ allocated = the pool total', 1000000.00, round((float) $al1->sum('allocated_amount'), 2), 0.05);

qa_section('THE CASE — ONE lease has a contractually STATED share of 40%');
LeaseCamTerm::create(['lease_id' => $a->id, 'effective_year' => 2031, 'stated_share_pct' => 40,
    'cap_type' => 'absolute', 'cap_scope' => LeaseCamTerm::SCOPE_TOTAL, 'cap_carry_forward' => false]);
$p2 = CamExpensePool::create(['asset_id' => $asset->id, 'name' => 'QCM 2031', 'period_year' => 2031,
    'total_actual_expense' => 1000000, 'total_estimated_collected' => 0, 'status' => 'draft',
    'estimate_basis' => 'stated', 'recovery_vat_rate' => 14, 'admin_fee_pct' => 0]);
$cam->generateAllocations($p2->fresh());
$al2 = CamAllocation::where('cam_expense_pool_id', $p2->id)->with('lease')->get();
foreach ($al2 as $x) {
    printf("    %-8s area=%s  share=%8s%%  allocated=%14s\n", $x->lease->reference,
        $x->lease->unit->area_sqm, $x->pro_rata_share_pct, number_format((float) $x->allocated_amount, 2));
}
$sumShare = round((float) $al2->sum('pro_rata_share_pct'), 4);
$sumAlloc = round((float) $al2->sum('allocated_amount'), 2);
printf("\n  Σ shares    = %s%%   (must be 100)\n", $sumShare);
printf("  Σ allocated = %s   (pool actual = 1,000,000.00)\n", number_format($sumAlloc, 2));
printf("  OVER-RECOVERED BY %s (%.1f%%)\n", number_format($sumAlloc - 1000000, 2), ($sumAlloc / 1000000 - 1) * 100);

qa_ok('the stated 40% is honoured', abs((float) $al2->firstWhere('lease_id', $a->id)->pro_rata_share_pct - 40) < 0.01);
qa_ok('the OTHER three still take their full 25% area share (denominator unchanged)',
    abs((float) $al2->firstWhere('lease_id', $b->id)->pro_rata_share_pct - 25) < 0.01,
    'B share = '.$al2->firstWhere('lease_id', $b->id)->pro_rata_share_pct.'%');
qa_ok('so Σ shares is NOT 100%', abs($sumShare - 100) > 0.01, $sumShare.'%');
qa_ok('and the pool over-recovers', $sumAlloc > 1000000.05,
    number_format($sumAlloc, 2).' recovered against 1,000,000.00 of actual cost');

qa_section('the reverse case — a stated share BELOW the area share under-recovers');
LeaseCamTerm::create(['lease_id' => $c->id, 'effective_year' => 2032, 'stated_share_pct' => 5,
    'cap_type' => 'absolute', 'cap_scope' => LeaseCamTerm::SCOPE_TOTAL, 'cap_carry_forward' => false]);
LeaseCamTerm::create(['lease_id' => $a->id, 'effective_year' => 2032, 'stated_share_pct' => 25,
    'cap_type' => 'absolute', 'cap_scope' => LeaseCamTerm::SCOPE_TOTAL, 'cap_carry_forward' => false]);
$p3 = CamExpensePool::create(['asset_id' => $asset->id, 'name' => 'QCM 2032', 'period_year' => 2032,
    'total_actual_expense' => 1000000, 'total_estimated_collected' => 0, 'status' => 'draft',
    'estimate_basis' => 'stated', 'recovery_vat_rate' => 14, 'admin_fee_pct' => 0]);
$cam->generateAllocations($p3->fresh());
$al3 = CamAllocation::where('cam_expense_pool_id', $p3->id)->get();
$sum3 = round((float) $al3->sum('allocated_amount'), 2);
printf("  Σ shares = %s%%  Σ allocated = %s\n", round((float) $al3->sum('pro_rata_share_pct'), 4), number_format($sum3, 2));
qa_ok('a below-area stated share leaves the pool UNDER-recovered', $sum3 < 999999.95,
    'shortfall '.number_format(1000000 - $sum3, 2).' the landlord silently absorbs');

qa_section('does anything DETECT it?');
foreach ([$p1, $p2, $p3] as $pp) {
    $pp->refresh();
    printf("  pool %s: actual=%s  Σ allocated=%s  landlord_unrecovered=%s\n", $pp->name,
        number_format((float) $pp->total_actual_expense, 2),
        number_format((float) $pp->allocations()->sum('allocated_amount'), 2),
        number_format((float) $pp->landlord_unrecovered_amount, 2));
}
$rec = app(BooksReconciliationService::class);
$deep = $rec->run(null, true);
$camCheck = collect($deep['checks'] ?? [])->firstWhere('key', 'cam_allocations');
printf("\n  cam_allocations check → ok=%s, %d discrepancies\n",
    var_export($camCheck['ok'] ?? null, true), count($camCheck['discrepancies'] ?? []));
foreach (($camCheck['discrepancies'] ?? []) as $x) {
    printf("    %s — %s\n", $x['ref'], $x['detail']);
}
$flagged = collect($camCheck['discrepancies'] ?? [])->contains(fn ($x) => str_contains($x['ref'], '#'.$p2->id.' '));
qa_ok('the OVER-recovering pool is reported by billing:reconcile', $flagged,
    $flagged ? 'the operator is told' : 'NOTHING reports the over-recovery');
$flagged3 = collect($camCheck['discrepancies'] ?? [])->contains(fn ($x) => str_contains($x['ref'], '#'.$p3->id.' '));
qa_ok('the UNDER-recovering pool is reported too', $flagged3);

qa_summary();
