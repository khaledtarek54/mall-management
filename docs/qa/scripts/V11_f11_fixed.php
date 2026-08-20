<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\DepositTransaction;
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
Artisan::call('accounting:sync-ledger', ['--all' => true]);

qa_section('F-11 FIXED — a deposit in flight is no longer a discrepancy');
qa_eq('clean to start with', 0, count($rec->depositTieOutDiscrepancies()));
$l = Lease::create(['tenant_id' => $tenant->id, 'unit_id' => $unit->id, 'reference' => 'QA-UD-'.uniqid(),
    'status' => 'active', 'currency' => 'EGP', 'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31',
    'term_months' => 36, 'base_rent_monthly' => 50000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
    'security_deposit' => 150000, 'billing_frequency' => 'monthly', 'payment_terms_days' => 7, 'escalation_type' => 'none']);
LeaseCreationService::seedStandardCharges($l, 50000, 0, $l->commencement_date);
$inv = app(BillSecurityDepositService::class)->bill($l->fresh());
Artisan::call('accounting:sync-ledger', ['--all' => true]);
printf("  billed %s and left UNPAID\n", number_format((float) $inv->total, 2));
printf("  held=%s · billed&unpaid=%s · expected GL=%s · actual GL=%s\n",
    number_format(DepositHoldings::held(), 2), number_format(DepositHoldings::billedAndOutstanding(), 2),
    number_format(DepositHoldings::expectedGlBalance(), 2), number_format((float) DepositHoldings::glBalance(), 2));
qa_eq('the in-flight deposit is counted separately', 150000.00, DepositHoldings::billedAndOutstanding());
qa_eq('…and the expectation now matches the ledger', (float) DepositHoldings::glBalance(), DepositHoldings::expectedGlBalance(), 0.02);
qa_eq('no discrepancy while the deposit is in flight', 0, count($rec->depositTieOutDiscrepancies()));

qa_section('…and it stays clean once paid');
$p = Payment::create(['tenant_id' => $l->tenant_id, 'amount' => 150000, 'payment_date' => now()->toDateString(),
    'method' => 'bank_transfer', 'status' => 'captured']);
DB::transaction(function () use ($p, $inv) {
    $p->invoices()->sync([$inv->id => ['allocated_amount' => 150000]]);
    $p->assertInvoicesNotOverAllocated([$inv->id]);
});
$inv->fresh()->recomputeTotals();
Artisan::call('accounting:sync-ledger', ['--all' => true]);
qa_eq('now it is held, not in flight', 0.00, DepositHoldings::billedAndOutstanding());
qa_eq('still no discrepancy', 0, count($rec->depositTieOutDiscrepancies()));

qa_section('MUTATION — the check must still catch a REAL gap');
$rogue = DepositTransaction::create(['lease_id' => $l->id, 'tenant_id' => $l->tenant_id, 'asset_id' => $asset->id,
    'type' => 'receipt', 'amount' => 90000, 'transaction_date' => now()->toDateString(), 'status' => 'recorded']);
// deliberately do NOT sync the ledger: the register now holds money the books have never seen
$d = $rec->depositTieOutDiscrepancies();
printf("  register %s vs GL %s\n", number_format(DepositHoldings::expectedGlBalance(), 2), number_format((float) DepositHoldings::glBalance(), 2));
foreach ($d as $x) {
    printf("    %s — %s\n", $x['ref'], $x['detail']);
}
qa_ok('a deposit that moved on one road only IS still reported', count($d) > 0);
Artisan::call('accounting:sync-ledger', ['--all' => true]);
qa_eq('…and clears once the books catch up', 0, count($rec->depositTieOutDiscrepancies()));

qa_summary();
