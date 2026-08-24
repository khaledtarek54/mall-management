<?php

/*
|--------------------------------------------------------------------------
| Billing twice what was ordered looked like an ordinary bill (2026-08-19)
|--------------------------------------------------------------------------
| Found by driving procurement on real data: a purchase worth 5,000, received into stock, then a
| supplier bill for 10,000 linked to it. Accepted without a murmur.
|
| The GL was never wrong — `VendorBillJournalizer` clears GRNI up to the RECEIVED value and expenses
| the remainder, which is correct for a bill that also covers labour or delivery. That is exactly
| why this must not be a refusal: the legitimate case and the duplicate-billing case post
| identically, and the only difference is whether a human meant it.
|
| So the fix is a number the operator can act on, at the moment of entry — the three-way match every
| ERP puts in front of an AP clerk — rather than a wall that would block the case the journalizer
| was built for.
*/

use App\Models\PurchaseRequest;
use App\Models\Vendor;
use App\Models\VendorBill;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->vendor = Vendor::create([
        'name' => 'Delta FM', 'legal_name' => 'Delta FM LLC', 'status' => 'active', 
    ]);
});

function purchaseWorth($ctx, float $value): PurchaseRequest
{
    return PurchaseRequest::create([
        'asset_id' => $ctx->asset->id,
        'vendor_id' => $ctx->vendor->id,
        'status' => PurchaseRequest::STATUS_RECEIVED,
        'justification' => 'Spare filters',
        'total_value' => $value,
        'requested_by_user_id' => makeUser('super_admin', [$ctx->asset->id])->id,
        'received_at' => now(),
    ]);
}

function purchaseBillFor($ctx, PurchaseRequest $pr, float $net, string $status = 'approved'): VendorBill
{
    return VendorBill::create([
        'asset_id' => $ctx->asset->id,
        'vendor_id' => $ctx->vendor->id,
        'purchase_request_id' => $pr->id,
        'status' => $status,
        'category' => 'maintenance',
        'bill_date' => now(),
        'due_date' => now()->addDays(30),
        'subtotal' => $net,
        'vat_amount' => 0,
        'total' => $net,
    ]);
}

it('reports the variance when one bill runs past the purchase', function () {
    $pr = purchaseWorth($this, 5000);
    purchaseBillFor($this, $pr, 10000);

    // The exact case from the probe: 10,000 billed against 5,000 ordered.
    expect($pr->fresh()->billingVariance())->toBe(5000.0);
});

it('reports nothing when the billing is within the purchase', function () {
    $pr = purchaseWorth($this, 5000);
    purchaseBillFor($this, $pr, 4000);

    expect($pr->fresh()->billingVariance())->toBeLessThanOrEqual(0.0);
});

it('adds up SEVERAL bills — a split delivery is where this hides', function () {
    $pr = purchaseWorth($this, 5000);
    purchaseBillFor($this, $pr, 3000);
    purchaseBillFor($this, $pr, 3000);

    // Neither bill exceeds the purchase on its own; together they do. A per-bill check would
    // have passed both, which is how a duplicate entry survives.
    expect($pr->fresh()->billingVariance())->toBe(1000.0);
});

it('ignores draft and cancelled bills, which claim nothing', function () {
    $pr = purchaseWorth($this, 5000);
    purchaseBillFor($this, $pr, 9000, status: 'draft');
    purchaseBillFor($this, $pr, 9000, status: 'cancelled');

    // Same predicate the journalizer uses to decide which bills consume received value —
    // `postable()` — so the screen and the ledger cannot disagree about what has been billed.
    expect($pr->fresh()->billingVariance())->toBe(-5000.0);
});

it('excludes the bill being edited, so re-saving it does not double-count itself', function () {
    $pr = purchaseWorth($this, 5000);
    $bill = purchaseBillFor($this, $pr, 6000);

    // The form asks "what is billed BESIDES this one, plus what this one now says". Without the
    // exclusion, opening a bill and pressing save would report the variance twice over.
    expect($pr->fresh()->billingVariance($bill->getKey(), 6000))->toBe(1000.0);
});

it('counts only what was RECEIVED into stock as clearable', function () {
    $pr = purchaseWorth($this, 5000);

    // No lines with a stock movement — nothing was credited to GRNI, so a bill against it clears
    // nothing and is pure expense. The screen must say so rather than implying goods are held.
    expect($pr->receivedValue())->toBe(0.0);
});
