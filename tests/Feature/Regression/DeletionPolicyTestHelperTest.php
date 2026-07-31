<?php

use App\Models\Invoice;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\VendorBillService;

/**
 * Pins the contract of `trashBypassingDeletionPolicy()` (tests/Pest.php).
 *
 * 24 tests across 18 files arrange their scenario through that helper. If it ever stopped
 * bypassing the refusal, they would error loudly — but the two ways it could go SILENTLY
 * wrong are the reason this file exists:
 *
 *   1. it stops re-arming the refusal, so a later assertion in the same test is made against
 *      a model whose guard we quietly removed;
 *   2. someone "simplifies" it back to `withoutEvents()`, which also mutes `deleted` — the
 *      soft-delete cascade never runs, and every cascade-sweep test goes green over nothing.
 */
it('re-arms the deletion refusal after the bypass', function () {
    $invoice = Invoice::factory()->create(['status' => 'issued']);

    // Before: refused.
    expect(fn () => $invoice->delete())->toThrow(DomainException::class);

    trashBypassingDeletionPolicy($invoice);
    expect(Invoice::withTrashed()->find($invoice->getKey())->trashed())->toBeTrue();

    // After: still refused — the helper put the guard back.
    $another = Invoice::factory()->create(['status' => 'issued']);
    expect(fn () => $another->delete())->toThrow(DomainException::class);
});

it('leaves the soft-delete cascade running — it mutes the refusal, not every event', function () {
    $bill = VendorBill::create([
        'vendor_id' => Vendor::factory()->create()->id,
        'asset_id' => makeAsset()->id,
        'category' => 'utilities',
        'status' => 'approved',
        'bill_date' => now()->toDateString(),
        'subtotal' => 2000, 'vat_amount' => 280, 'total' => 2280, 'balance' => 2280,
    ]);
    app(VendorBillService::class)->recordPayment($bill, 1000, 'bank_transfer');
    $payment = $bill->payments()->first();

    trashBypassingDeletionPolicy($bill);

    // The `deleted` hook stamps the payment with the bill's OWN deleted_at. `withoutEvents()`
    // would leave the payment live and every cascade-sweep assertion vacuous.
    $trashedPayment = $bill->payments()->withTrashed()->find($payment->getKey());

    expect($trashedPayment->trashed())->toBeTrue()
        ->and($trashedPayment->deleted_at->eq($bill->fresh()->deleted_at))->toBeTrue();
});
