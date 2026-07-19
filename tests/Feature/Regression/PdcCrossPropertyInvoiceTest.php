<?php

use App\Models\Invoice;
use App\Models\PostDatedCheque;

/**
 * Regression (close-out sweep, HIGH isolation): a post-dated cheque's linked invoice MUST belong to
 * the same property the cheque is pinned to. The form's invoice picker was scoped by tenant only,
 * not property — so a cheque for Mall A could be linked to Mall B's invoice, and clearing it would
 * settle that invoice (moving Mall B's AR + GL). The model now refuses a cross-property link.
 */
function pdcInvoiceFor($asset)
{
    $lease = makeLease(makeUnit($asset, ['area_sqm' => 50]), makeTenant());

    return Invoice::create([
        'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'status' => 'issued',
        'issue_date' => now(), 'due_date' => now()->addDays(7),
        'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(),
        'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000, 'paid_amount' => 0, 'balance' => 1000, 'currency' => 'EGP',
    ]);
}

it('refuses to pin a cheque to an invoice from another property', function () {
    $mallA = makeAsset(['code' => 'PDC-A']);
    $mallB = makeAsset(['code' => 'PDC-B']);
    $invoiceB = pdcInvoiceFor($mallB);

    expect(fn () => PostDatedCheque::create([
        'reference' => PostDatedCheque::generateReference(),
        'asset_id' => $mallA->id,               // cheque pinned to Mall A
        'tenant_id' => $invoiceB->tenant_id,
        'invoice_id' => $invoiceB->id,          // but linked to Mall B's invoice
        'cheque_number' => 'CHQ-X-1', 'amount' => 1000, 'currency' => 'EGP',
        'cheque_date' => now()->addMonth()->toDateString(), 'received_date' => now()->toDateString(),
        'status' => PostDatedCheque::STATUS_HELD,
    ]))->toThrow(DomainException::class);
});

it('accepts a cheque linked to an invoice from its own property', function () {
    $mallA = makeAsset(['code' => 'PDC-A2']);
    $invoiceA = pdcInvoiceFor($mallA);

    $cheque = PostDatedCheque::create([
        'reference' => PostDatedCheque::generateReference(),
        'asset_id' => $mallA->id,
        'tenant_id' => $invoiceA->tenant_id,
        'invoice_id' => $invoiceA->id,
        'cheque_number' => 'CHQ-A-1', 'amount' => 1000, 'currency' => 'EGP',
        'cheque_date' => now()->addMonth()->toDateString(), 'received_date' => now()->toDateString(),
        'status' => PostDatedCheque::STATUS_HELD,
    ]);

    expect($cheque->exists)->toBeTrue()
        ->and((int) $cheque->invoice_id)->toBe((int) $invoiceA->id);
});

it('allows a cheque with no linked invoice (on-account)', function () {
    $mallA = makeAsset(['code' => 'PDC-A3']);
    $tenant = makeTenant();

    $cheque = PostDatedCheque::create([
        'reference' => PostDatedCheque::generateReference(),
        'asset_id' => $mallA->id,
        'tenant_id' => $tenant->id,
        'invoice_id' => null,
        'cheque_number' => 'CHQ-A-2', 'amount' => 500, 'currency' => 'EGP',
        'cheque_date' => now()->addMonth()->toDateString(), 'received_date' => now()->toDateString(),
        'status' => PostDatedCheque::STATUS_HELD,
    ]);

    expect($cheque->exists)->toBeTrue();
});
