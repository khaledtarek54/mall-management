<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\BillSecurityDepositService;
use App\Services\LeaseCreationService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Support\DepositHoldings;
use Illuminate\Support\Facades\Artisan;

$asset = Asset::where('code', 'AW')->firstOrFail();
$tenant = Tenant::whereDoesntHave('unitOwnerships')->firstOrFail();
$unit = Unit::where('asset_id', $asset->id)->where('status', 'vacant')->firstOrFail();
$rec = app(BooksReconciliationService::class);

qa_section('BASELINE — the deposits register and the GL agree');
Artisan::call('accounting:sync-ledger', ['--all' => true]);
printf("  held=%s  gl=%s  discrepancies=%d\n", number_format(DepositHoldings::held(), 2),
    number_format((float) DepositHoldings::glBalance(), 2), count($rec->depositTieOutDiscrepancies()));
qa_eq('clean to start with', 0, count($rec->depositTieOutDiscrepancies()));

qa_section('THE CASE — bill a security deposit and leave it unpaid (the normal flow)');
$l = Lease::create(['tenant_id' => $tenant->id, 'unit_id' => $unit->id, 'reference' => 'QA-UD-'.uniqid(),
    'status' => 'active', 'currency' => 'EGP', 'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31',
    'term_months' => 36, 'base_rent_monthly' => 50000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
    'security_deposit' => 150000, 'billing_frequency' => 'monthly', 'payment_terms_days' => 7, 'escalation_type' => 'none']);
LeaseCreationService::seedStandardCharges($l, 50000, 0, $l->commencement_date);
$heldBefore = DepositHoldings::held();
$glBefore = (float) DepositHoldings::glBalance();

$inv = app(BillSecurityDepositService::class)->bill($l->fresh());
Artisan::call('accounting:sync-ledger', ['--all' => true]);
$heldAfter = DepositHoldings::held();
$glAfter = (float) DepositHoldings::glBalance();
printf("  invoice %s for %s — UNPAID\n", $inv->number, number_format((float) $inv->total, 2));
printf("  register 'held'      : %s → %s  (Δ %s)\n", number_format($heldBefore, 2), number_format($heldAfter, 2), number_format($heldAfter - $heldBefore, 2));
printf("  GL deposits_held     : %s → %s  (Δ %s)\n", number_format($glBefore, 2), number_format($glAfter, 2), number_format($glAfter - $glBefore, 2));

qa_eq('the register correctly does NOT count an unpaid deposit', 0.00, round($heldAfter - $heldBefore, 2));
qa_eq('but the GL credits the liability AT ISSUE', 150000.00, round($glAfter - $glBefore, 2));
$d = $rec->depositTieOutDiscrepancies();
printf("\n  billing:reconcile deposits_tie_out → %d discrepancy(ies)\n", count($d));
foreach ($d as $x) {
    printf("    %s — %s\n", $x['ref'], $x['detail']);
}
qa_ok('…so the weekly reconciliation now reports a discrepancy', count($d) > 0);

qa_section('and it clears the moment the tenant pays');
$p = Payment::create(['tenant_id' => $l->tenant_id, 'amount' => 150000, 'payment_date' => now()->toDateString(),
    'method' => 'bank_transfer', 'status' => 'captured']);
DB::transaction(function () use ($p, $inv) {
    $p->invoices()->sync([$inv->id => ['allocated_amount' => 150000]]);
    $p->assertInvoicesNotOverAllocated([$inv->id]);
});
$inv->fresh()->recomputeTotals();
Artisan::call('accounting:sync-ledger', ['--all' => true]);
printf("  after payment: held=%s gl=%s discrepancies=%d\n", number_format(DepositHoldings::held(), 2),
    number_format((float) DepositHoldings::glBalance(), 2), count($rec->depositTieOutDiscrepancies()));
qa_eq('the discrepancy clears on payment', 0, count($rec->depositTieOutDiscrepancies()));

qa_section('so the window is: issued → paid');
echo "  Any security-deposit invoice that is issued and not yet settled puts\n";
echo "  `billing:reconcile`'s deposits_tie_out check into failure for that window.\n";
echo "  The weekly job runs Friday 04:00; a deposit billed on a Thursday with 7-day terms\n";
echo "  will be reported as a books discrepancy that is not one.\n";

qa_summary();
