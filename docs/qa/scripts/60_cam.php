<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\LeaseCamTerm;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\CamReconciliationService;
use App\Services\LeaseCreationService;
use App\Services\Reconciliation\BooksReconciliationService;
use Illuminate\Support\Facades\Artisan;

$asset = Asset::where('code', 'AW')->firstOrFail();
$cam = app(CamReconciliationService::class);
$tenants = Tenant::whereDoesntHave('unitOwnerships')->take(4)->get();
$free = Unit::where('asset_id', $asset->id)->where('status', 'vacant')->orderBy('id')->get();
$i = 0;
$mk = function (Tenant $t, float $area, float $rent = 20000) use (&$i, $free): Lease {
    $u = $free[$i++];
    $u->forceFill(['area_sqm' => $area])->saveQuietly();
    $u->areas()->update(['area_sqm' => $area]);
    $l = Lease::create(['tenant_id' => $t->id, 'unit_id' => $u->id,
        'reference' => 'QA-CAM-'.strtoupper(bin2hex(random_bytes(3))), 'status' => 'active', 'currency' => 'EGP',
        'commencement_date' => '2025-01-01', 'expiry_date' => '2035-12-31', 'term_months' => 132,
        'base_rent_monthly' => $rent, 'service_charge_monthly' => 5000, 'has_marketing_levy' => false,
        'billing_frequency' => 'monthly', 'payment_terms_days' => 7, 'escalation_type' => 'none']);
    LeaseCreationService::seedStandardCharges($l, $rent, 5000, $l->commencement_date);

    return $l->fresh();
};

qa_section('CAM 1 — pro-rata apportionment by area');
$l1 = $mk($tenants[0], 100);
$l2 = $mk($tenants[1], 300);
$l3 = $mk($tenants[2], 600);
$pool = CamExpensePool::create(['asset_id' => $asset->id, 'name' => 'QA CAM 2030', 'period_year' => 2030,
    'total_actual_expense' => 1000000, 'total_estimated_collected' => 800000, 'status' => 'draft',
    'estimate_basis' => 'stated', 'recovery_vat_rate' => 14, 'admin_fee_pct' => 0]);
$made = $cam->generateAllocations($pool->fresh());
printf("  allocations generated: %d\n", $made);
$allocs = CamAllocation::where('cam_expense_pool_id', $pool->id)->with('lease.unit')->get();
$mine = $allocs->whereIn('lease_id', [$l1->id, $l2->id, $l3->id]);
$totalSqm = (float) $allocs->sum(fn ($a) => $a->lease?->unit?->area_sqm ?? 0);
foreach ($mine as $a) {
    printf("    lease %-14s area=%6s share=%7s%% allocated=%12s estimated=%12s trueup=%12s\n",
        $a->lease->reference, $a->lease->unit->area_sqm, $a->pro_rata_share_pct,
        number_format((float) $a->allocated_amount, 2), number_format((float) $a->estimated_paid, 2),
        number_format((float) $a->true_up_amount, 2));
}
qa_eq('shares sum to 100%', 100.0, round((float) $allocs->sum('pro_rata_share_pct'), 2), 0.05);
qa_eq('allocations sum to the actual expense', 1000000.00, round((float) $allocs->sum('allocated_amount'), 2), 1.0);
$a1 = $mine->firstWhere('lease_id', $l1->id);
$a3 = $mine->firstWhere('lease_id', $l3->id);
qa_ok('the 600 m² shop bears 6x the 100 m² shop',
    abs((float) $a3->allocated_amount / max((float) $a1->allocated_amount, 0.01) - 6) < 0.05,
    number_format((float) $a3->allocated_amount, 2).' vs '.number_format((float) $a1->allocated_amount, 2));
qa_eq('true-up = allocated − estimated', round((float) $a1->allocated_amount - (float) $a1->estimated_paid, 2),
    (float) $a1->true_up_amount);

qa_section('CAM 2 — a POSITIVE true-up bills a recovery invoice with VAT');
$pool->update(['status' => 'reconciled']);
$before = Invoice::count();
$billed = $cam->bill($a1->fresh());
$inv = Invoice::where('id', '>', $before ? Invoice::max('id') - 1 : 0)->latest('id')->first();
$recInv = Invoice::whereHas('items', fn ($q) => $q->where('type', 'cam_recovery'))->latest('id')->first();
printf("  recovery invoice %s total=%s\n", $recInv?->number, number_format((float) $recInv?->total, 2));
qa_ok('a recovery invoice was raised', $recInv !== null);
qa_eq('allocation is marked billed', 'billed', $billed->status);
$expNet = round((float) $a1->true_up_amount, 2);
qa_eq('the recovery is the true-up', $expNet, round((float) $recInv->subtotal, 2), 0.05);
qa_eq('…plus 14% recovery VAT', round($expNet * 0.14, 2), round((float) $recInv->vat_amount, 2), 0.05);
$invCount = Invoice::count();
$cam->bill($a1->fresh());
qa_eq('billing the same allocation twice raises NO second invoice', $invCount, Invoice::count());
qa_eq('…and the allocation stays billed', 'billed', $a1->fresh()->status);

qa_section('CAM 3 — a NEGATIVE true-up raises a credit note, never a negative invoice');
$poolLow = CamExpensePool::create(['asset_id' => $asset->id, 'name' => 'QA CAM 2031 over-collected', 'period_year' => 2031,
    'total_actual_expense' => 400000, 'total_estimated_collected' => 900000, 'status' => 'draft',
    'estimate_basis' => 'stated', 'recovery_vat_rate' => 14, 'admin_fee_pct' => 0]);
$cam->generateAllocations($poolLow->fresh());
$poolLow->update(['status' => 'reconciled']);
$negAlloc = CamAllocation::where('cam_expense_pool_id', $poolLow->id)->where('lease_id', $l2->id)->first();
printf("  over-collected true-up: %s\n", number_format((float) $negAlloc->true_up_amount, 2));
qa_ok('the true-up is negative', (float) $negAlloc->true_up_amount < 0);
$cnBefore = CreditNote::count();
$cam->bill($negAlloc->fresh());
qa_eq('a credit note was raised', $cnBefore + 1, CreditNote::count());
$cn = CreditNote::latest('id')->first();
qa_eq('the credit equals the over-collection', abs((float) $negAlloc->true_up_amount), round((float) $cn->subtotal, 2), 0.05);
qa_ok('no negative invoice was created', Invoice::where('total', '<', 0)->doesntExist());

qa_section('CAM 4 — a stated contractual share beats the derived one');
$l4 = $mk($tenants[3], 50);
// 12.5% on a shop whose area share is ~2% would push the pool to 110% — refused since F-08.
// Prove the refusal, then use a figure the pool can actually carry.
LeaseCamTerm::create(['lease_id' => $l4->id, 'effective_year' => 2032, 'stated_share_pct' => 12.5,
    'cap_type' => 'absolute', 'cap_scope' => LeaseCamTerm::SCOPE_TOTAL, 'cap_carry_forward' => false]);
$pool32 = CamExpensePool::create(['asset_id' => $asset->id, 'name' => 'QA CAM 2032', 'period_year' => 2032,
    'total_actual_expense' => 1000000, 'total_estimated_collected' => 0, 'status' => 'draft',
    'estimate_basis' => 'stated', 'recovery_vat_rate' => 14, 'admin_fee_pct' => 0]);
qa_refuses('a stated share that pushes the pool over 100% is refused',
    fn () => $cam->generateAllocations($pool32->fresh()), 'more than the pool itself');

// Re-state it at a figure below the lease's own area share — the ordinary concession — and the
// stated number is then used in place of the derived one.
LeaseCamTerm::where('lease_id', $l4->id)->where('effective_year', 2032)->update(['stated_share_pct' => 1]);
$cam->generateAllocations($pool32->fresh());
$a4 = CamAllocation::where('cam_expense_pool_id', $pool32->id)->where('lease_id', $l4->id)->first();
qa_eq('the contract share is used, not the area share', 1.0, (float) $a4->pro_rata_share_pct);
qa_eq('…and it allocates 1% of the pool', 10000.00, (float) $a4->allocated_amount, 1.0);
$sum32 = round((float) CamAllocation::where('cam_expense_pool_id', $pool32->id)->sum('allocated_amount'), 2);
qa_ok('the pool never over-recovers', $sum32 <= 1000000.05, number_format($sum32, 2));

qa_section('CAM 5 — a ceiling caps the tenant share and the landlord absorbs the rest');
LeaseCamTerm::create(['lease_id' => $l3->id, 'effective_year' => 2033, 'cap_type' => 'absolute',
    'cap_absolute_amount' => 50000, 'cap_scope' => LeaseCamTerm::SCOPE_TOTAL, 'cap_carry_forward' => false]);
$pool33 = CamExpensePool::create(['asset_id' => $asset->id, 'name' => 'QA CAM 2033', 'period_year' => 2033,
    'total_actual_expense' => 1000000, 'total_estimated_collected' => 0, 'status' => 'draft',
    'estimate_basis' => 'stated', 'recovery_vat_rate' => 14, 'admin_fee_pct' => 0]);
$cam->generateAllocations($pool33->fresh());
$a3c = CamAllocation::where('cam_expense_pool_id', $pool33->id)->where('lease_id', $l3->id)->first();
printf("  allocated=%s capped=%s absorbed=%s\n", number_format((float) $a3c->allocated_amount, 2),
    number_format((float) $a3c->capped_cost_amount, 2), number_format((float) $a3c->cap_absorbed_amount, 2));
qa_eq('the capped cost is the ceiling', 50000.00, (float) $a3c->capped_cost_amount);
qa_eq('the landlord absorbs the excess',
    round((float) $a3c->allocated_amount - 50000, 2), (float) $a3c->cap_absorbed_amount);
qa_ok('allocated_amount stays UNCAPPED so the pool still ties out',
    (float) $a3c->allocated_amount > 50000);
$sum33 = round((float) CamAllocation::where('cam_expense_pool_id', $pool33->id)->sum('allocated_amount'), 2);
// A lease on this asset carries a STATED share from 2032 onward, and a stated share deliberately
// does not re-cut its neighbours (CamDenominatorTest) — so Σ allocated legitimately differs from
// the pool when the stated figure differs from the area share. What must never happen is
// OVER-recovery, which is what the assertion below actually protects.
printf("  Σ allocated %s vs pool actual 1,000,000.00 (a stated share is in play)\n", number_format($sum33, 2));
qa_ok('the pool never recovers MORE than the cost incurred', $sum33 <= 1000000.05,
    number_format($sum33, 2));
qa_ok('…the shortfall is the landlord\'s, recorded on the pool',
    round((float) $pool33->fresh()->landlord_unrecovered_amount, 2) >= -0.05);

qa_section('CAM 6 — admin fee is a sibling line, never folded into the true-up');
$poolFee = CamExpensePool::create(['asset_id' => $asset->id, 'name' => 'QA CAM 2034 fee', 'period_year' => 2034,
    'total_actual_expense' => 1000000, 'total_estimated_collected' => 0, 'status' => 'draft',
    'estimate_basis' => 'stated', 'recovery_vat_rate' => 14, 'admin_fee_pct' => 0.10]);
$cam->generateAllocations($poolFee->fresh());
$af = CamAllocation::where('cam_expense_pool_id', $poolFee->id)->where('lease_id', $l1->id)->first();
qa_eq('admin fee = 10% of the capped cost', round((float) $af->capped_cost_amount * 0.10, 2), (float) $af->admin_fee_amount);
qa_eq('the true-up excludes the fee', round((float) $af->capped_cost_amount - (float) $af->estimated_paid, 2), (float) $af->true_up_amount);

qa_section('CAM 7 — re-running never re-touches a billed allocation');
$statusBefore = $a1->fresh()->status;
$cam->generateAllocations($pool->fresh());
qa_eq('the billed allocation is untouched', $statusBefore, $a1->fresh()->status);
qa_eq('…and keeps its frozen share', (float) $a1->pro_rata_share_pct, (float) $a1->fresh()->pro_rata_share_pct);

qa_section('CAM 8 — tie-out');
Artisan::call('accounting:sync-ledger', ['--all' => true]);
qa_assert_tb('after CAM');
$rec = app(BooksReconciliationService::class);
$tie = $rec->glTieOut();
qa_eq('AR ties after CAM recovery + credit', 0.0, $tie['ar']['delta']);
qa_eq('no GL drift', 0, count($rec->glDriftDiscrepancies()));

qa_summary();
