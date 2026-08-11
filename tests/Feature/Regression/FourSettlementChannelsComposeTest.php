<?php

use App\Models\DepositApplication;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\TenantCreditApplication;
use App\Settings\BillingSettings;

/**
 * All FOUR settlement channels on one invoice, composing correctly.
 *
 * `Invoice::recomputeTotals()` counts four: captured payments, an applied credit note
 * (`credit_applied_amount`), applied tenant credit (`TenantCreditApplication`) and a netted security
 * deposit (`DepositApplication`). Each had its own test. **None exercised more than one at a time** —
 * so nothing proved they compose, and composing is precisely where the documented bug lived: a
 * payment over-settling an invoice another channel had already paid, burying the excess as negative
 * AR. CLAUDE.md records that as having happened once already.
 *
 * The three downstream calculations must agree with the same four:
 *   - `capturedCashPaid()` — the void guard, which must count ONLY cash;
 *   - `assertInvoicesNotOverAllocated()` — refuses a payment beyond what is left;
 *   - `refitAllocationsToBalance()` — clamps an allocation when the balance moves under it.
 *
 * A fifth channel would need all four of those sites plus this test. That is the point of putting
 * them in one place.
 */
beforeEach(function () {
    // The manual paths are what this exercises; the automatic trigger would consume the tenant
    // credit before the test could place it deliberately.
    app(BillingSettings::class)->auto_apply_tenant_credit = false;

    $this->lease = makeLease(makeUnit(makeAsset()), null, ['status' => 'active']);
    $this->tenant = $this->lease->tenant;
    $this->assetId = $this->lease->unit->asset_id;
});

/** A 10,000 invoice settled 2,500 by each of the four channels. */
function settledFourWays(): Invoice
{
    $invoice = makeInvoice(test()->lease, ['status' => 'issued', 'total' => 10000, 'balance' => 10000]);

    // 1. Cash.
    $payment = Payment::create([
        'tenant_id' => test()->tenant->id,
        'payment_date' => now(),
        'amount' => 2500,
        'method' => 'cash',
        'status' => 'captured',
        'currency' => 'EGP',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 2500]);

    // 2. An applied credit note — settles through its own column, not the payments pivot.
    $invoice->credit_applied_amount = 2500;
    $invoice->save();

    // 3. On-account tenant credit.
    TenantCreditApplication::create([
        'tenant_id' => test()->tenant->id,
        'invoice_id' => $invoice->id,
        'asset_id' => test()->assetId,
        'amount' => 2500,
        'entry_date' => now()->toDateString(),
    ]);

    // 4. A netted security deposit at move-out.
    DepositApplication::create([
        'lease_id' => test()->lease->id,
        'tenant_id' => test()->tenant->id,
        'invoice_id' => $invoice->id,
        'asset_id' => test()->assetId,
        'amount' => 2500,
        'entry_date' => now()->toDateString(),
    ]);

    $invoice->recomputeTotals();

    return $invoice->fresh();
}

it('settles the invoice exactly once across all four channels', function () {
    $invoice = settledFourWays();

    // 4 × 2,500 = 10,000. Any channel counted twice shows as a negative balance; any channel
    // missed shows as a positive one.
    expect((float) $invoice->paid_amount)->toBe(10000.0)
        ->and((float) $invoice->balance)->toBe(0.0);
});

it('counts only the cash as cash', function () {
    // The void guard rests on this. If it counted the non-cash channels, voiding an invoice settled
    // by a credit note would look like refunding money that was never received.
    $invoice = settledFourWays();

    expect((float) $invoice->capturedCashPaid())->toBe(2500.0);
});

it('refuses a further payment once the four have settled it', function () {
    // The documented failure: a payment over-settling an invoice another channel already paid,
    // burying the excess as negative AR.
    $invoice = settledFourWays();

    $second = Payment::create([
        'tenant_id' => $this->tenant->id,
        'payment_date' => now(),
        'amount' => 1000,
        'method' => 'cash',
        'status' => 'captured',
        'currency' => 'EGP',
    ]);
    $second->invoices()->attach($invoice->id, ['allocated_amount' => 1000]);

    expect(fn () => $second->assertInvoicesNotOverAllocated([$invoice->id]))
        ->toThrow(Exception::class);
});

it('clamps an allocation when the other channels have taken the balance', function () {
    // `refitAllocationsToBalance` is the other guard. Omitting one from EITHER lets a payment
    // over-settle — CLAUDE.md is explicit that both must count all four.
    $invoice = makeInvoice($this->lease, ['status' => 'issued', 'total' => 10000, 'balance' => 10000]);

    $payment = Payment::create([
        'tenant_id' => $this->tenant->id,
        'payment_date' => now(),
        'amount' => 10000,
        'method' => 'cash',
        'status' => 'captured',
        'currency' => 'EGP',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 10000]);
    $invoice->recomputeTotals();

    // Now a deposit is netted against the same invoice — the payment no longer fits.
    DepositApplication::create([
        'lease_id' => $this->lease->id,
        'tenant_id' => $this->tenant->id,
        'invoice_id' => $invoice->id,
        'asset_id' => $this->assetId,
        'amount' => 4000,
        'entry_date' => now()->toDateString(),
    ]);

    $payment->refitAllocationsToBalance();

    $allocated = (float) $payment->fresh()->invoices()->where('invoices.id', $invoice->id)
        ->first()->pivot->allocated_amount;

    // Clamped to what is left after the deposit, never leaving the invoice over-settled.
    expect($allocated)->toBe(6000.0)
        ->and((float) $invoice->fresh()->balance)->toBe(0.0);
});

it('re-opens the AR when a non-cash channel is reversed', function () {
    // Each of the three non-cash channels soft-deletes on reversal, and the next recompute must
    // return the AR. A channel that did not would strand a settled invoice as permanently paid.
    $invoice = settledFourWays();

    TenantCreditApplication::where('invoice_id', $invoice->id)->delete();
    $invoice->recomputeTotals();

    expect((float) $invoice->fresh()->balance)->toBe(2500.0);
});
