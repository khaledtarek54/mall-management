<?php

use App\Models\Vendor;
use App\Models\VendorBill;

/**
 * Pre-go-live sweep (terminal-state) — a finalized vendor bill is immutable off the form.
 *
 * VendorBill was the ONE money model missing the model-level finalized-immutability `updating`
 * guard its AR/lease peers (Invoice, CreditNote, CamPool, Lease) all carry. Its money-material
 * fields were frozen only by the form's disabled() UI lock, so a non-form write (API / console /
 * crafted Livewire) could edit a paid/cancelled bill's subtotal → the model derives total =
 * subtotal + vat and the windowed sync re-derives Dr Expense / Cr AP at the inflated total while
 * the payment stays applied → overstated expense/AP + a phantom re-opened balance on a "paid" bill.
 */
function immutabilityBill(array $attrs = []): VendorBill
{
    $vendor = Vendor::create(['name' => 'V-'.uniqid(), 'category' => 'maintenance', 'status' => 'active']);

    return VendorBill::create(array_merge([
        'vendor_id' => $vendor->id, 'asset_id' => makeAsset()->id, 'category' => 'maintenance',
        'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000, 'status' => 'draft',
    ], $attrs));
}

it('freezes a finalized bill\'s money + counterparty fields off the form', function () {
    $bill = immutabilityBill(['status' => 'approved']);

    expect(fn () => $bill->fresh()->update(['subtotal' => 6000]))->toThrow(DomainException::class);
    expect(fn () => $bill->fresh()->update(['vat_amount' => 500]))->toThrow(DomainException::class);
    expect(fn () => $bill->fresh()->update(['category' => 'utilities']))->toThrow(DomainException::class);
    $other = Vendor::create(['name' => 'Other', 'category' => 'maintenance', 'status' => 'active']);
    expect(fn () => $bill->fresh()->update(['vendor_id' => $other->id]))->toThrow(DomainException::class);

    // The amount is unchanged after the refused edits.
    expect((float) $bill->fresh()->subtotal)->toBe(1000.0)
        ->and((float) $bill->fresh()->total)->toBe(1000.0);
});

it('still lets a DRAFT bill be edited freely, including draft→approved', function () {
    $bill = immutabilityBill();
    $bill->update(['subtotal' => 2500, 'status' => 'approved']);

    expect((float) $bill->fresh()->subtotal)->toBe(2500.0)
        ->and((float) $bill->fresh()->total)->toBe(2500.0)   // derived
        ->and($bill->fresh()->status)->toBe('approved');
});

it('allows cancelling a finalized bill — a status change is not a frozen-field edit', function () {
    $bill = immutabilityBill(['status' => 'approved']);
    $bill->update(['status' => 'cancelled']);

    expect($bill->fresh()->status)->toBe('cancelled');
});
