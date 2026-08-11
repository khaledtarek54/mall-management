<?php

use App\Models\Payment;
use App\Services\ApplyTenantCreditService;

/**
 * Pre-go-live sweep (HIGH) — the Paymob gateway capture clamp must subtract applied on-account
 * tenant credit, exactly as Invoice::recomputeTotals() and Payment::assertInvoicesNotOverAllocated()
 * both do.
 *
 * THE BUG. `refitAllocationsToBalance()` (the GATEWAY path — it clamps instead of throwing because
 * the card money is already collected) computed fittable = total − credit_applied_amount −
 * otherCaptured, OMITTING the TenantCreditApplication channel. So a tenant credit applied to the
 * invoice BETWEEN Paymob session-init (which allocates the full balance) and the callback was not
 * subtracted: the gateway allocation stayed at the pre-credit balance, the card cleared for the full
 * amount AND the credit also settled AR, and the invoice over-settled into negative AR. The form
 * path was safe (its throw-guard counts all three channels); only the gateway clamp was wrong.
 */
/**
 * Auto-apply OFF: these exercise the MANUAL apply path and the credit BALANCE itself. With the
 * automatic trigger on, an invoice raised in a fixture consumes the credit before the assertion can
 * read it — which is correct behaviour, and would make these tests measure the trigger rather than
 * the thing they are about.
 */
beforeEach(fn () => app(\App\Settings\BillingSettings::class)->auto_apply_tenant_credit = false);

it('clamps the gateway allocation by applied tenant credit — no over-settlement into negative AR', function () {
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset));
    $tenant = $lease->tenant;
    $this->actingAs(\App\Models\User::factory()->create());

    // The tenant holds 4,000 of on-account credit: a prior invoice (6,000) over-paid by 10,000.
    $prior = makeInvoice($lease, ['status' => 'issued', 'total' => 6000, 'balance' => 6000, 'paid_amount' => 0]);
    $overpayment = Payment::create([
        'tenant_id' => $tenant->id, 'amount' => 10000, 'currency' => 'EGP',
        'method' => 'cash', 'status' => 'captured', 'payment_date' => now()->toDateString(),
    ]);
    $overpayment->invoices()->sync([$prior->id => ['allocated_amount' => 6000]]);
    $overpayment->recomputeAllocatedInvoices();
    expect((float) $tenant->creditBalance([$asset->id]))->toBe(4000.0);

    // The invoice the tenant is about to pay by card.
    $invoice = makeInvoice($lease, ['status' => 'issued', 'total' => 10000, 'balance' => 10000, 'paid_amount' => 0]);

    // The Paymob session allocated the FULL balance (10,000) while still uncaptured…
    $gateway = Payment::create([
        'tenant_id' => $tenant->id, 'amount' => 10000, 'currency' => 'EGP',
        'method' => 'card', 'status' => 'initiated', 'payment_date' => now()->toDateString(),
    ]);
    $gateway->invoices()->sync([$invoice->id => ['allocated_amount' => 10000]]);

    // …then, before the callback, the operator applies the 4,000 on-account credit → balance 6,000.
    app(ApplyTenantCreditService::class)->applyToInvoice($invoice->fresh(), 4000);
    expect((float) $invoice->fresh()->balance)->toBe(6000.0);

    // The callback clamps before capturing.
    $gateway->refitAllocationsToBalance();

    // FIXED: the allocation is clamped to the credit-reduced balance; the 4,000 surplus stays
    // unallocated (a recoverable overpayment). BEFORE the fix it stayed at 10,000.
    $allocated = (float) $gateway->fresh()->invoices()->first()->pivot->allocated_amount;
    expect($allocated)->toBe(6000.0);

    // And the invoice never over-settles: capture, recompute, paid_amount == total (not 14,000).
    $gateway->update(['status' => 'captured']);
    $invoice->refresh();
    expect((float) $invoice->paid_amount)->toBe(10000.0)   // 6,000 card + 4,000 tenant credit
        ->and((float) $invoice->balance)->toBe(0.0);
});
