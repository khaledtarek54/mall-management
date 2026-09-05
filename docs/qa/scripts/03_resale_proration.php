<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\Charge;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\UnitOwnership;
use App\Services\BillUnitOwnershipsService;
use App\Services\TransferUnitOwnershipService;
use Carbon\CarbonImmutable;

$bill = app(BillUnitOwnershipsService::class);
$xfer = app(TransferUnitOwnershipService::class);
$asset = Asset::where('code', 'AW')->firstOrFail();
// A unit with NO existing ownership — the baseline seeds handed-over owners, and SW-220's
// overlap guard (2026-09-02) correctly refuses a second tenure past 100% for the day.
$unit = Unit::where('asset_id', $asset->id)->where('status', 'vacant')
    ->whereNotIn('id', UnitOwnership::pluck('unit_id'))->firstOrFail();
$owners = Tenant::whereIn('id', UnitOwnership::pluck('tenant_id'))->take(2)->get();

qa_section('RESALE — the REALISTIC sequence: bill on the 1st, sell on the 11th');
$seller = UnitOwnership::create([
    'asset_id' => $asset->id, 'unit_id' => $unit->id, 'tenant_id' => $owners[0]->id, 'tenure_type' => 'freehold',
    'status' => 'handed_over', 'assessment_basis' => 'stated', 'ownership_share_pct' => 100,
    'started_at' => '2026-01-01', 'handover_date' => '2026-01-01', 'payment_terms_days' => 15, 'currency' => 'EGP',
]);
Charge::create(['unit_ownership_id' => $seller->id, 'asset_id' => $asset->id, 'name' => 'صيانة QA', 'type' => 'service_charge',
    'amount' => 3000, 'frequency' => 'monthly', 'is_active' => true, 'start_date' => '2026-01-01', 'vat_applicable' => true]);

$oct = CarbonImmutable::parse('2026-10-01');
// 1. The monthly run fires on the 1st — the seller still owns the unit.
$octInv = $bill->billOne($seller->fresh(), $oct, $oct->endOfMonth());
qa_ok('October assessment raised to the seller on the 1st', $octInv !== null, $octInv?->number);
qa_eq('seller billed the FULL month (correct at that moment)', 3000.00, (float) $octInv->subtotal);

// 2. The unit is sold on the 11th.
$res = $xfer->transfer($seller->fresh(), $owners[1], CarbonImmutable::parse('2026-10-11'), true, 'QA mid-month resale');
$buyer = $res['buyer'];
qa_eq('seller tenure now ends 10 Oct', '2026-10-10', $res['seller']->ended_at?->format('Y-m-d'));

// 3. What does the seller now stand billed for October?
$octInv->refresh();
$sellerOct = (float) $octInv->subtotal;
$owed = round(3000 * 10 / 31, 2);
printf("\n  Seller stands billed for October : %s\n", number_format($sellerOct, 2));
printf("  Seller actually owned 1–10 Oct   : %s (10/31 of the month)\n", number_format($owed, 2));
printf("  Over-billed to the seller        : %s\n", number_format($sellerOct - $owed, 2));

qa_ok('a credit note was raised for the seller unearned days',
    CreditNote::where('tenant_id', $seller->tenant_id)->whereDate('created_at', '>=', now()->toDateString())->exists(),
    'looking for an automatic unearned-billing credit');

// 4. Is the buyer charged for 11–31 Oct?
$buyerOct = Invoice::where('unit_ownership_id', $buyer->id)
    ->whereDate('period_start', '<=', '2026-10-31')->whereDate('period_end', '>=', '2026-10-01')->first();
qa_ok('the buyer is billed for the days he owned in October', $buyerOct !== null,
    $buyerOct ? $buyerOct->number : 'NO October assessment exists for the buyer');

// 5. F-02 FIXED: the buyer's part of the month is raised BY THE TRANSFER, so a re-run has nothing
//    left to do. It returning null is the correct answer now, not the gap it used to report.
$rerun = $bill->billOne(UnitOwnership::find($buyer->id), $oct, $oct->endOfMonth());
qa_ok('a re-run does not double-bill the buyer — the transfer already raised it', $rerun === null);
$seller2 = $bill->billOne($res['seller']->fresh(), $oct, $oct->endOfMonth());
qa_ok('a re-run does NOT correct the seller (terminal tenure is never re-billed)', $seller2 === null);

qa_eq('the buyer is billed 21/31 of the month, from the day his tenure opens',
    round(3000 * 21 / 31, 2), (float) $buyerOct->subtotal, 0.02);

if ($buyerOct) {
    // NET, not gross: the seller keeps a 3,000 invoice AND a credit note against it. Summing the
    // two invoices alone reads 5,032.26 and looks like over-billing — the earlier version of this
    // check did exactly that and would have reported the FIX as a failure. What the unit owes for
    // October is what the books say after the credit.
    $credits = (float) CreditNote::where('tenant_id', $res['seller']->tenant_id)
        ->whereDate('created_at', '>=', now()->toDateString())->sum('subtotal');
    $tot = $sellerOct + (float) $buyerOct->subtotal - $credits;
    printf("\n  October for this unit: seller %s + buyer %s - credit %s = %s against a monthly charge of 3,000.00\n",
        number_format($sellerOct, 2), number_format((float) $buyerOct->subtotal, 2),
        number_format($credits, 2), number_format($tot, 2));
    qa_eq('the unit is billed exactly one month of assessment for October', 3000.00, $tot, 0.02);
}

qa_summary();
