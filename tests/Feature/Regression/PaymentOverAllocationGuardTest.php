<?php

use App\Models\Invoice;
use App\Models\Payment;

/**
 * The lock-safe over-allocation guard (Payment::assertInvoicesNotOverAllocated)
 * is the backstop the per-request form cap can't provide: it stops two captured
 * payments that each fit the balance alone but together exceed the invoice total.
 * (SQLite can't exercise lockForUpdate, so we assert the invariant logic.)
 */
function overAllocCaptured(Invoice $invoice, float $amount): Payment
{
    $p = Payment::create([
        'tenant_id' => $invoice->tenant_id, 'amount' => $amount, 'currency' => 'EGP',
        'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => now(),
    ]);
    $p->invoices()->attach($invoice->id, ['allocated_amount' => $amount]);
    $p->recomputeAllocatedInvoices();

    return $p;
}

it('rejects a captured allocation that pushes an invoice past its total', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset()))); // total 11,400
    overAllocCaptured($invoice, 11400); // fully paid

    $extra = Payment::create([
        'tenant_id' => $invoice->tenant_id, 'amount' => 5000, 'currency' => 'EGP',
        'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => now(),
    ]);
    $extra->invoices()->attach($invoice->id, ['allocated_amount' => 5000]);

    expect(fn () => $extra->assertInvoicesNotOverAllocated([$invoice->id]))
        ->toThrow(DomainException::class);
});

it('allows allocations that stay within the invoice total', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset()))); // total 11,400
    $p = overAllocCaptured($invoice, 5000); // partial

    expect(fn () => $p->assertInvoicesNotOverAllocated([$invoice->id]))
        ->not->toThrow(DomainException::class);
});
