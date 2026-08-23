<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\Charge;
use App\Models\CreditNote;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\UnitOwnership;
use App\Services\BillUnitOwnershipsService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\TransferUnitOwnershipService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

$bill = app(BillUnitOwnershipsService::class);
$xfer = app(TransferUnitOwnershipService::class);
$asset = Asset::where('code', 'AW')->firstOrFail();
$unit = Unit::where('asset_id', $asset->id)->where('status', 'vacant')->firstOrFail();
$owners = Tenant::whereIn('id', UnitOwnership::pluck('tenant_id'))->take(2)->get();

qa_section('F-02 FIXED — bill on the 1st, sell on the 11th');
$seller = UnitOwnership::create([
    'asset_id' => $asset->id, 'unit_id' => $unit->id, 'tenant_id' => $owners[0]->id, 'tenure_type' => 'freehold',
    'status' => 'handed_over', 'assessment_basis' => 'stated', 'ownership_share_pct' => 100,
    'started_at' => '2026-01-01', 'handover_date' => '2026-01-01', 'payment_terms_days' => 15, 'currency' => 'EGP']);
Charge::create(['unit_ownership_id' => $seller->id, 'name' => 'صيانة', 'type' => 'service_charge', 'amount' => 3000,
    'currency' => 'EGP', 'frequency' => 'monthly', 'is_active' => true, 'start_date' => '2026-01-01', 'vat_applicable' => false, 'vat_rate' => 0]);

$oct = CarbonImmutable::parse('2026-10-01');
$octInv = $bill->billOne($seller->fresh(), $oct, $oct->endOfMonth());
qa_eq('October billed in full to the seller on the 1st', 3000.00, (float) $octInv->total);

$res = $xfer->transfer($seller->fresh(), $owners[1], CarbonImmutable::parse('2026-10-11'), true, 'QA resale');
$buyer = $res['buyer'];

qa_section('the seller is credited the days they did not own');
$notes = CreditNote::where('invoice_id', $octInv->id)->get();
qa_eq('a credit note was raised against the October assessment', 1, $notes->count());
$owed = round(3000 * 10 / 31, 2);          // 1–10 Oct inclusive
$expectedCredit = round(3000 - $owed, 2);   // 11–31 Oct
printf("  billed 3,000.00 · owned 1–10 Oct (%s) · credited %s\n",
    number_format($owed, 2), number_format((float) $notes->sum('total'), 2));
qa_eq('the credit is the 21 unearned days', $expectedCredit, round((float) $notes->sum('total'), 2), 0.02);
$octInv->refresh();
qa_eq('…and it settles against the invoice', $owed, (float) $octInv->balance, 0.02);
qa_ok('so the seller now stands billed only for what they owned',
    abs((float) $octInv->balance - $owed) < 0.02, number_format((float) $octInv->balance, 2));

qa_section('the buyer inherits a schedule and IS billed');
qa_eq('the recurring row was carried forward', 1, $buyer->charges()->where('is_active', true)->count());
$carried = $buyer->charges()->first();
qa_eq('…dated from the transfer', '2026-10-11', $carried->start_date?->format('Y-m-d'));
qa_eq('…at the same amount', 3000.00, (float) $carried->amount);
qa_eq('the seller schedule is closed the day before', '2026-10-10',
    $res['seller']->charges()->first()?->end_date?->format('Y-m-d'));
qa_eq('…and is no longer active', 0, $res['seller']->charges()->where('is_active', true)->count());

// Raised BY THE TRANSFER since 2026-08-23 — the third clause of F-02's fix. Carrying the schedule
// only lets the buyer be billed from NOVEMBER: the monthly run bills the current period and never
// goes back for 11–31 October, so those days were refunded to the seller and charged to nobody.
// This script used to raise them with a manual `billOne()`, which measured a recovery the product
// never performed on its own.
$buyerOct = $buyer->getAttribute('transfer_buyer_invoice');
qa_ok('the buyer is billed for their part of October, by the transfer itself',
    $buyerOct !== null, $buyerOct?->number);
qa_eq('…21/31 of the month', round(3000 * 21 / 31, 2), (float) $buyerOct->subtotal, 0.02);
qa_ok('…and the ordinary run does not raise it a second time',
    $bill->billOne(UnitOwnership::find($buyer->id), $oct, $oct->endOfMonth()) === null);

qa_section('the unit is billed exactly one month of assessment');
$sellerNet = round((float) $octInv->total - (float) $notes->sum('total'), 2);
$total = round($sellerNet + (float) $buyerOct->subtotal, 2);
printf("  seller net %s + buyer %s = %s (charge is 3,000.00)\n",
    number_format($sellerNet, 2), number_format((float) $buyerOct->subtotal, 2), number_format($total, 2));
qa_eq('seller + buyer = one month', 3000.00, $total, 0.02);

qa_section('November onward the buyer bills normally');
$nov = CarbonImmutable::parse('2026-11-01');
$novInv = $bill->billOne(UnitOwnership::find($buyer->id), $nov, $nov->endOfMonth());
qa_ok('November is billed', $novInv !== null);
qa_eq('…in full', 3000.00, (float) $novInv->subtotal);
$stats = $bill->runForPeriod($nov, $asset->id);
printf("  November run: %s\n", json_encode($stats));
qa_eq('no ownership is left unconfigured by the transfer', 0, $stats['unconfigured']);

qa_section('accounting still ties out');
Artisan::call('accounting:sync-ledger', ['--all' => true]);
qa_assert_tb('after a mid-month resale');
$rec = app(BooksReconciliationService::class);
$tie = $rec->glTieOut();
qa_eq('AR ties', 0.0, $tie['ar']['delta']);
qa_eq('no GL drift', 0, count($rec->glDriftDiscrepancies()));

qa_summary();
