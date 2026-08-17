<?php

/*
|--------------------------------------------------------------------------
| A refusal must name the number that is true (2026-08-17)
|--------------------------------------------------------------------------
| `assertInvoicesNotOverAllocated()` is the race backstop behind the payment form's per-row cap. It
| refused correctly and then quoted the invoice TOTAL as the maximum — so on an invoice already
| part-settled by another channel, the operator was told:
|
|     "cannot exceed EGP 240,300.00"     while EGP 60,200.00 was all that remained
|
| Which is worse than unhelpful: 240,300 was the very amount they had just been refused for. The
| form-level rule had always quoted the fittable figure, so the two layers disagreed about the same
| invoice, and only the one an operator hits under contention was wrong.
|
| The cap is the invoice total minus what the OTHER channels have already settled — the same four
| channels `recomputeTotals()` counts.
*/

use App\Models\CreditNote;
use App\Models\Payment;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->unit = makeUnit($this->asset);
    $this->lease = makeLease($this->unit, $this->tenant, ['status' => 'active']);
});

/** An invoice of `$total` with `$credited` already settled by a credit note. */
function partSettledInvoice($ctx, float $total, float $credited)
{
    $invoice = makeInvoice($ctx->lease, [
        'asset_id' => $ctx->asset->id,
        'status' => 'partially_paid',
        'total' => $total,
        'credit_applied_amount' => $credited,
        'paid_amount' => $credited,
        'balance' => $total - $credited,
    ]);

    CreditNote::create([
        'tenant_id' => $ctx->tenant->id,
        'invoice_id' => $invoice->id,
        'asset_id' => $ctx->asset->id,
        'status' => 'applied',
        'issue_date' => now(),
        'subtotal' => $credited,
        'total' => $credited,
        'applied_amount' => $credited,
        'balance' => 0,
        'reason' => 'adjustment',
    ]);

    return $invoice;
}

/** Attach `$amount` of a captured payment to the invoice and run the backstop. */
function allocateAndAssert($ctx, $invoice, float $amount): void
{
    $payment = Payment::create([
        'tenant_id' => $ctx->tenant->id,
        'amount' => $amount,
        'method' => 'cash',
        'status' => 'captured',
        'payment_date' => now(),
        'currency' => 'EGP',
    ]);

    $payment->invoices()->attach($invoice->id, ['allocated_amount' => $amount]);
    $payment->assertInvoicesNotOverAllocated([$invoice->id]);
}

it('names what is left, not the invoice total, when another channel already settled part of it', function () {
    $invoice = partSettledInvoice($this, total: 240300, credited: 80100);

    try {
        allocateAndAssert($this, $invoice, 240300);
        $this->fail('Over-allocation was accepted.');
    } catch (DomainException $e) {
        // The number an operator is handed must be one they can act on.
        expect($e->getMessage())->toContain('160,200.00')
            ->and($e->getMessage())->not->toContain('240,300.00');
    }
});

it('still accepts an allocation that exactly fills what remains', function () {
    $invoice = partSettledInvoice($this, total: 240300, credited: 80100);

    // The boundary matters as much as the refusal: a guard that rounded the wrong way here would
    // refuse the legitimate final payment on every part-credited invoice.
    allocateAndAssert($this, $invoice, 160200);

    expect(true)->toBeTrue();
});

it('accounts for an earlier PAYMENT too, not only credits', function () {
    $invoice = partSettledInvoice($this, total: 240300, credited: 80100);

    allocateAndAssert($this, $invoice, 100000);
    $invoice->refresh();

    try {
        allocateAndAssert($this, $invoice, 100000);
        $this->fail('Over-allocation was accepted.');
    } catch (DomainException $e) {
        // 240,300 − 80,100 credit − 100,000 already captured = 60,200.
        expect($e->getMessage())->toContain('60,200.00');
    }
});

it('says zero rather than a negative when the invoice is already fully settled', function () {
    $invoice = partSettledInvoice($this, total: 100000, credited: 100000);

    try {
        allocateAndAssert($this, $invoice, 5000);
        $this->fail('Over-allocation was accepted.');
    } catch (DomainException $e) {
        // "cannot exceed EGP -5,000.00" is not a sentence anyone can act on. Anchored on "EGP -"
        // rather than a bare hyphen, which every invoice number contains.
        expect($e->getMessage())->toContain('EGP 0.00')
            ->and($e->getMessage())->not->toContain('EGP -');
    }
});
