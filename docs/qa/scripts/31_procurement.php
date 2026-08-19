<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\InventoryItem;
use App\Models\PurchaseRequest;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\Warehouse;
use App\Services\Accounting\AccountResolver;
use App\Services\PurchaseRequestService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\VendorBillService;
use Illuminate\Support\Facades\Artisan;

$asset = Asset::where('code', 'AW')->firstOrFail();
$vendor = Vendor::first();
$wh = Warehouse::where('asset_id', $asset->id)->first();
$item = InventoryItem::first();
$svc = app(PurchaseRequestService::class);
$requester = User::where('email', 'operations@mall.test')->first() ?? User::where('email', 'manager@mall.test')->first();
$approver = User::where('email', 'admin@mall.test')->first();
$acct = fn (string $role) => app(AccountResolver::class)->id($role);

qa_section('PROC 1 — a request needs lines');
qa_refuses('an empty request is refused',
    fn () => $svc->request(['asset_id' => $asset->id, 'justification' => 'QA', 'lines' => []], $requester));

$pr = $svc->request(['asset_id' => $asset->id, 'justification' => 'QA spare parts', 'warehouse_id' => $wh?->id,
    'lines' => [['inventory_item_id' => $item?->id, 'quantity' => 10, 'unit_cost' => 500]]], $requester);
qa_ok('a request with lines is created', $pr->exists, $pr->reference);
qa_eq('total is derived from the lines', 5000.00, (float) $pr->total_value);
qa_eq('status requested', PurchaseRequest::STATUS_REQUESTED, $pr->status);
qa_ok('the approval tier is FROZEN on the record', filled($pr->required_permission), $pr->required_permission);

qa_section('PROC 2 — self-approval is refused (second pair of eyes)');
$selfPr = $svc->request(['asset_id' => $asset->id, 'justification' => 'QA self', 'warehouse_id' => $wh?->id,
    'lines' => [['inventory_item_id' => $item?->id, 'quantity' => 1, 'unit_cost' => 100]]], $approver);
qa_refuses('a user WITH decide rights still cannot approve their own request',
    fn () => $svc->approve($selfPr->fresh(), 'QA', $approver), 'second pair of eyes');
qa_refuses('a requester without decide rights is refused too',
    fn () => $svc->approve($pr->fresh(), 'QA', $requester));
$svc->approve($pr->fresh(), 'QA approved', $approver);
qa_eq('another approver can', PurchaseRequest::STATUS_APPROVED, $pr->fresh()->status);

qa_section('PROC 3 — a blank unit cost is refused, never silently zero');
qa_refuses('a catalog line with no unit cost is refused',
    fn () => $svc->request(['asset_id' => $asset->id, 'justification' => 'QA blank cost', 'warehouse_id' => $wh?->id,
        'lines' => [['inventory_item_id' => $item?->id, 'quantity' => 4, 'unit_cost' => '']]], $requester),
    null, InvalidArgumentException::class);

qa_section('PROC 4 — receiving stocks the goods and raises GRNI');
$grniBefore = qa_role_balance('inventory_grni');
$invBefore = qa_role_balance('inventory');
$svc->order($pr->fresh(), $vendor->id, 'PO-QA-001', $approver);
qa_eq('ordered', PurchaseRequest::STATUS_ORDERED, $pr->fresh()->status);
$svc->receive($pr->fresh(), $approver);
qa_eq('received', PurchaseRequest::STATUS_RECEIVED, $pr->fresh()->status);
$mv = StockMovement::where('source_id', $pr->id)->latest('id')->first();
qa_ok('a stock movement was recorded', $mv !== null, $mv?->type);
$me = qa_sync($mv->fresh());
qa_dump_entry($me, 'goods receipt');
Artisan::call('accounting:sync-ledger', ['--all' => true]);
$grniAfter = qa_role_balance('inventory_grni');
$invAfter = qa_role_balance('inventory');
printf("  inventory %s → %s · GRNI %s → %s\n", number_format($invBefore, 2), number_format($invAfter, 2),
    number_format($grniBefore, 2), number_format($grniAfter, 2));
qa_eq('inventory is debited the goods value', 5000.00, round($invAfter - $invBefore, 2));
qa_eq('GRNI is credited the same (a liability, so it falls)', -5000.00, round($grniAfter - $grniBefore, 2));

qa_section('PROC 5 — the vendor bill CLEARS GRNI instead of double-charging the expense');
$bill = VendorBill::create(['vendor_id' => $vendor->id, 'asset_id' => $asset->id, 'purchase_request_id' => $pr->id,
    'number' => 'QA-VB-GRNI-'.strtoupper(bin2hex(random_bytes(2))), 'bill_date' => '2026-08-15', 'due_date' => '2026-09-15',
    'category' => 'maintenance', 'subtotal' => 5000, 'vat_amount' => 700, 'total' => 5700,
    'status' => 'draft', 'currency' => 'EGP', 'description' => 'QA goods bill']);
app(VendorBillService::class)->approve($bill->fresh());
$be = qa_sync($bill->fresh());
qa_dump_entry($be, 'vendor bill for received goods');
$grniLine = $be?->lines->firstWhere('ledger_account_id', $acct('inventory_grni'));
qa_ok('the bill DEBITS GRNI (clearing it) rather than charging the expense again',
    $grniLine !== null && (float) $grniLine->debit > 0,
    $grniLine ? 'Dr '.number_format((float) $grniLine->debit, 2) : 'NO GRNI LINE');
$expLine = $be?->lines->firstWhere('ledger_account_id', $acct('maintenance_expense'));
qa_ok('…and books no maintenance expense for the goods', $expLine === null,
    $expLine ? 'expense Dr '.number_format((float) $expLine->debit, 2) : 'none — correct');
Artisan::call('accounting:sync-ledger', ['--all' => true]);
printf("  GRNI after the bill: %s (was %s)\n", number_format(qa_role_balance('inventory_grni'), 2), number_format($grniAfter, 2));
qa_eq('GRNI nets back to where it started', $grniBefore, qa_role_balance('inventory_grni'), 0.02);

qa_section('PROC 6 — receiving twice never stocks the same line twice');
$before = StockMovement::where('source_id', $pr->id)->count();
try {
    $svc->receive($pr->fresh(), $approver);
} catch (Throwable $e) {
    echo '  (re-receive refused: '.mb_substr($e->getMessage(), 0, 80).")\n";
}
qa_eq('no second stock movement', $before, StockMovement::where('source_id', $pr->id)->count());

qa_section('PROC 7 — tie-out');
qa_assert_tb('after procurement');
$rec = app(BooksReconciliationService::class);
$tie = $rec->glTieOut();
qa_eq('AP ties', 0.0, $tie['ap']['delta']);
qa_eq('AR ties', 0.0, $tie['ar']['delta']);
qa_eq('no GL drift', 0, count($rec->glDriftDiscrepancies()));

qa_summary();
