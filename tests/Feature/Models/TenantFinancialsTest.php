<?php

use App\Models\CreditNote;

beforeEach(function () {
    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset);
    $this->tenant = makeTenant();
    $this->lease = makeLease($this->unit, $this->tenant);
});

it('isDelinquent() flags an issued-but-past-due invoice (not just status=overdue)', function () {
    // Tenant pays late but Payment hook never flipped status — invoice is still
    // 'issued' even though due_date is 30 days ago.
    makeInvoice($this->lease, [
        'balance' => 1000,
        'status' => 'issued',
        'due_date' => now()->subDays(30),
    ]);

    expect($this->tenant->fresh()->isDelinquent())->toBeTrue();
});

it('isDelinquent() ignores invoices that are paid or cancelled', function () {
    makeInvoice($this->lease, [
        'balance' => 0,
        'status' => 'paid',
        'paid_amount' => 1000,
        'due_date' => now()->subDays(30),
    ]);
    makeInvoice($this->lease, [
        'balance' => 0,
        'status' => 'cancelled',
        'due_date' => now()->subDays(30),
    ]);

    expect($this->tenant->fresh()->isDelinquent())->toBeFalse();
});

it('outstandingBalance() nets out unapplied credit-note balances', function () {
    makeInvoice($this->lease, ['balance' => 1000, 'status' => 'issued']);
    makeInvoice($this->lease, ['balance' => 500, 'status' => 'partially_paid']);

    CreditNote::create([
        'number' => 'CN-'.uniqid(),
        'tenant_id' => $this->tenant->id,
        'lease_id' => $this->lease->id,
        'reason' => 'adjustment',
        'status' => 'issued',
        'issue_date' => now(),
        'subtotal' => 300, 'vat_amount' => 0, 'total' => 300,
        'applied_amount' => 0, 'balance' => 300,
        'currency' => 'EGP',
    ]);

    expect($this->tenant->fresh()->outstandingBalance())->toBe(1200.0);
});

it('outstandingBalance() ignores fully-applied credit notes', function () {
    makeInvoice($this->lease, ['balance' => 1000, 'status' => 'issued']);

    CreditNote::create([
        'number' => 'CN-'.uniqid(),
        'tenant_id' => $this->tenant->id,
        'lease_id' => $this->lease->id,
        'reason' => 'adjustment',
        'status' => 'applied',
        'issue_date' => now(),
        'subtotal' => 300, 'vat_amount' => 0, 'total' => 300,
        'applied_amount' => 300, 'balance' => 0,
        'currency' => 'EGP',
    ]);

    expect($this->tenant->fresh()->outstandingBalance())->toBe(1000.0);
});
