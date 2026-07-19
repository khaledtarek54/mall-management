<?php

use App\Models\Payment;
use App\Services\VoidPaymentService;

/**
 * Tenant credit balance — SURFACE only (2026-07-20). An overpayment / on-account remainder of a
 * received payment already books to Unearned Revenue; Tenant::creditBalance() surfaces it (portal
 * "Credit on account" stat). This is display-only — no AR/GL mutation.
 *
 * NB: a manual "apply this credit to an invoice" action was built then REVERTED before shipping — the
 * pre-push adversarial review found that extending the source payment's allocation re-derives its
 * (immutable, possibly closed-period) GL entry, which silently diverges AR from the GL. Applying a
 * credit correctly needs its own Dr Unearned / Cr AR entry dated at APPLICATION time (a new GL
 * source) — deferred; see docs/gap-analysis/PROPERTY-FACILITY-CLOSURE.md.
 */
function overpaidTenant(float $amount = 15000, float $allocate = 10000, float $invoiceTotal = 10000): array
{
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);
    $invoice = makeInvoice($lease, ['status' => 'issued', 'total' => $invoiceTotal, 'balance' => $invoiceTotal, 'paid_amount' => 0]);

    $payment = Payment::create([
        'tenant_id' => $tenant->id, 'amount' => $amount, 'currency' => 'EGP',
        'method' => 'cash', 'status' => 'captured', 'payment_date' => now(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => $allocate]);
    $invoice->recomputeTotals();

    return [$tenant, $lease, $payment];
}

it('surfaces an overpayment remainder as the tenant credit balance', function () {
    [$tenant] = overpaidTenant(amount: 15000, allocate: 10000); // 5,000 unallocated

    expect($tenant->creditBalance())->toBe(5000.0);
});

it('reports no credit when a payment is fully allocated', function () {
    [$tenant] = overpaidTenant(amount: 10000, allocate: 10000);

    expect($tenant->creditBalance())->toBe(0.0);
});

it('excludes reversed payments from the credit balance', function () {
    [$tenant, , $payment] = overpaidTenant(amount: 15000, allocate: 10000);
    expect($tenant->creditBalance())->toBe(5000.0);

    app(VoidPaymentService::class)->void($payment, 'refunded to tenant');

    expect($tenant->creditBalance())->toBe(0.0); // a refunded payment is no longer "received"
});

it('scopes the credit to the property where the payment was received', function () {
    [$tenant, $lease] = overpaidTenant(amount: 15000, allocate: 10000);
    $assetId = $lease->unit->asset_id;
    $otherAsset = makeAsset();

    expect($tenant->creditBalance([$assetId]))->toBe(5000.0)        // visible in its own property
        ->and($tenant->creditBalance([$otherAsset->id]))->toBe(0.0); // not in another property
});

it('sums credit across multiple overpaid payments', function () {
    [$tenant, $lease] = overpaidTenant(amount: 15000, allocate: 10000); // 5,000
    $invoiceB = makeInvoice($lease, ['status' => 'issued', 'total' => 8000, 'balance' => 8000]);
    $p2 = Payment::create([
        'tenant_id' => $tenant->id, 'amount' => 11000, 'currency' => 'EGP',
        'method' => 'cash', 'status' => 'captured', 'payment_date' => now(),
    ]);
    $p2->invoices()->attach($invoiceB->id, ['allocated_amount' => 8000]); // 3,000 remainder
    $invoiceB->recomputeTotals();

    expect($tenant->creditBalance())->toBe(8000.0); // 5,000 + 3,000
});
