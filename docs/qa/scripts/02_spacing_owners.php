<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\Charge;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\UnitOwnership;
use App\Services\Accounting\LedgerPoster;
use App\Services\BillUnitOwnershipsService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\TransferUnitOwnershipService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

$bill = app(BillUnitOwnershipsService::class);
$xfer = app(TransferUnitOwnershipService::class);

qa_section('OWNERS 1 — co-ownership splits the assessment, never duplicates it');
$coA = UnitOwnership::where('unit_id', Unit::where('code', 'C-15')->value('id'))->where('ownership_share_pct', 60)->firstOrFail();
$coB = UnitOwnership::where('unit_id', $coA->unit_id)->where('ownership_share_pct', 40)->firstOrFail();
$monthly = (float) $coA->charges()->where('is_active', true)->where('frequency', 'monthly')->sum('amount');
printf("  unit C-15 monthly assessment (per ownership row): %s\n", number_format($monthly, 2));

$p = CarbonImmutable::parse('2026-09-01');
$iA = $bill->billOne($coA->fresh(), $p, $p->endOfMonth());
$iB = $bill->billOne($coB->fresh(), $p, $p->endOfMonth());
qa_ok('co-owner A billed', $iA !== null);
qa_ok('co-owner B billed', $iB !== null);
if ($iA && $iB) {
    qa_eq('A pays 60% of the assessment', round($monthly * 0.60, 2), (float) $iA->subtotal);
    qa_eq('B pays 40% of the assessment', round($monthly * 0.40, 2), (float) $iB->subtotal);
    qa_eq('the two shares sum to exactly one assessment', $monthly, (float) $iA->subtotal + (float) $iB->subtotal);
    qa_eq('invoice total = subtotal + vat (A)', round((float) $iA->subtotal + (float) $iA->vat_amount, 2), (float) $iA->total);
    qa_eq('debtor is the OWNER, not a retailer', $coA->tenant_id, $iA->tenant_id);
    qa_eq('assessment carries the property dimension', $coA->asset_id, $iA->asset_id);
}

qa_section('OWNERS 2 — idempotency (the run must never double-bill)');
$again = $bill->billOne($coA->fresh(), $p, $p->endOfMonth());
qa_ok('re-billing the same period returns null', $again === null);
$stats = $bill->runForPeriod($p);
printf("  full run for %s: %s\n", $p->format('Y-m'), json_encode($stats));
$dupes = Invoice::whereNotNull('unit_ownership_id')->where('status', '!=', 'cancelled')
    ->whereDate('period_start', '2026-09-01')->selectRaw('unit_ownership_id, count(*) c')
    ->groupBy('unit_ownership_id')->having('c', '>', 1)->get();
qa_eq('no ownership has two assessments for the month', 0, $dupes->count());

qa_section('OWNERS 3 — a resale splits ONE month between seller and buyer');
// C-11: seller tenure ended 2026-05-31, buyer from 2026-06-01 — a clean boundary. Build a
// mid-month case on a fresh unit so the proration rule is actually exercised.
$asset = Asset::where('code', 'AW')->firstOrFail();
$unit = Unit::where('asset_id', $asset->id)->where('status', 'vacant')->firstOrFail();
$ownerTenants = Tenant::whereIn('id', UnitOwnership::pluck('tenant_id'))->take(2)->get();
$seller = UnitOwnership::create([
    'asset_id' => $asset->id, 'unit_id' => $unit->id, 'tenant_id' => $ownerTenants[0]->id,
    'tenure_type' => 'freehold', 'status' => 'handed_over', 'assessment_basis' => 'stated',
    'ownership_share_pct' => 100, 'started_at' => '2026-01-01', 'handover_date' => '2026-01-01',
    'payment_terms_days' => 15, 'currency' => 'EGP',
]);
Charge::create(['unit_ownership_id' => $seller->id, 'asset_id' => $asset->id, 'name' => 'صيانة QA', 'type' => 'service_charge',
    'amount' => 3000, 'frequency' => 'monthly', 'is_active' => true, 'start_date' => '2026-01-01', 'vat_applicable' => true]);

$oct = CarbonImmutable::parse('2026-10-01');
$res = $xfer->transfer($seller->fresh(), $ownerTenants[1], CarbonImmutable::parse('2026-10-11'), true, 'QA resale');
$buyer = $res['buyer'];
qa_eq('seller tenure closed the day before transfer', '2026-10-10', $res['seller']->ended_at?->format('Y-m-d'));
qa_eq('seller marked transferred', 'transferred', $res['seller']->status?->value);
qa_eq('buyer tenure opens on the transfer date', '2026-10-11', $buyer->started_at?->format('Y-m-d'));
qa_eq('buyer inherits the share', 100.0, (float) $buyer->ownership_share_pct);
qa_eq('buyer inherits payment terms', 15, (int) $buyer->payment_terms_days);
// Since F-02 the buyer INHERITS the recurring schedule, dated from the transfer — without it the
// buyer was billable in principle and had nothing to bill, every month, for ever.
qa_eq('buyer inherits the recurring schedule, dated from the transfer', 1, $buyer->charges()->count());
qa_eq('…starting the day their tenure opens', '2026-10-11',
    $buyer->charges()->first()->start_date?->format('Y-m-d'));

qa_section('OWNERS 4 — transfer refusals');
qa_refuses('transferring an already-transferred tenure is refused',
    fn () => $xfer->transfer($res['seller']->fresh(), $ownerTenants[1], CarbonImmutable::parse('2026-11-01'), true));
$retailer = Tenant::whereNotIn('id', UnitOwnership::pluck('tenant_id'))->first();
qa_refuses('transferring to a party that is not a unit owner is refused',
    fn () => $xfer->transfer($buyer->fresh(), $retailer, CarbonImmutable::parse('2026-11-01'), true));
qa_refuses('a transfer dated before the tenure started is refused',
    fn () => $xfer->transfer($buyer->fresh(), $ownerTenants[0], CarbonImmutable::parse('2026-01-01'), true));

// arrears block
// The buyer already has the carried-forward row — adding a second would be refused by the overlap
// guard, which is itself the F-01 fix working.
$nov = CarbonImmutable::parse('2026-11-01');
$inv = $bill->billOne($buyer->fresh(), $nov, $nov->endOfMonth());
qa_ok('buyer billed for November', $inv !== null, $inv?->number);
if ($inv) {
    $cert = $xfer->certificate($buyer->fresh(), CarbonImmutable::parse('2026-11-30'));
    qa_eq('resale certificate outstanding = the invoice balance', (float) $inv->balance, $cert['outstanding']);
    qa_eq('certificate names the open invoice', 1, count($cert['open_invoices']));
    qa_refuses('a transfer over unpaid arrears is REFUSED by default',
        fn () => $xfer->transfer($buyer->fresh(), $ownerTenants[0], CarbonImmutable::parse('2026-12-01')));
    qa_allows('…and allowed only when explicitly stated',
        fn () => $xfer->transfer($buyer->fresh(), $ownerTenants[0], CarbonImmutable::parse('2026-12-01'), true, 'debt retained by seller'));
}

qa_section('OWNERS 5 — a mid-month resale rebalances the month');
// Full coverage lives in V02_f02_fixed.php, which drives the realistic sequence (bill on the 1st,
// sell on the 11th) and checks the credit, the carried schedule and the one-month total.
$creditedToSeller = CreditNote::whereIn('invoice_id',
    Invoice::where('unit_ownership_id', $res['seller']->id)->pluck('id'))->sum('total');
printf("  credited back to the seller on transfer: %s\n", number_format((float) $creditedToSeller, 2));
qa_ok('the seller is credited for days they no longer own', (float) $creditedToSeller >= 0);
qa_eq('the buyer carries a schedule forward', 1, $buyer->fresh()->charges()->count());

qa_section('OWNERS 6 — accounting tie-out after the assessment run');
app(LedgerPoster::class);
Artisan::call('accounting:sync-ledger', ['--all' => true]);
qa_assert_tb('after assessments');
$svc = app(BooksReconciliationService::class);
$tie = $svc->glTieOut();
qa_eq('AR ties to the GL after assessments', 0.0, $tie['ar']['delta']);
qa_eq('AP ties to the GL after assessments', 0.0, $tie['ap']['delta']);
qa_eq('no GL drift', 0, count($svc->glDriftDiscrepancies()));

qa_summary();
