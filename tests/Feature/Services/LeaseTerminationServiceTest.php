<?php

use App\Models\Charge;
use App\Services\LeaseTerminationService;

beforeEach(function () {
    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset, ['status' => 'occupied']);
    $this->lease = makeLease($this->unit, attrs: ['status' => 'active']);
});

it('refuses to terminate a lease that is not active', function () {
    $this->lease->update(['status' => 'terminated']);

    app(LeaseTerminationService::class)->terminate($this->lease, []);
})->throws(InvalidArgumentException::class);

it('marks lease terminated, frees the unit, deactivates charges', function () {
    Charge::create([
        'lease_id' => $this->lease->id, 'name' => 'Rent', 'type' => 'base_rent',
        'amount' => 5000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);

    app(LeaseTerminationService::class)->terminate($this->lease, [
        'termination_date' => '2026-05-15',
        'reason' => 'tenant request',
    ]);

    expect($this->lease->fresh()->status)->toBe('terminated');
    expect($this->unit->fresh()->status)->toBe('vacant');
    expect($this->lease->charges()->where('is_active', true)->count())->toBe(0);
});

it('cancels only unpaid open invoices when asked, never orphaning partial payments', function () {
    // Three invoices: unpaid (cancellable), partially-paid (must NOT cancel),
    // fully-paid (already closed, untouched).
    //
    // All three sit in the helper's default February period, and the lease is terminated on 31
    // January — so every one of them covers time the tenant never occupied. That isolates the rule
    // under test to the PAYMENT: cancellation is also refused for an earned period, and leaving the
    // date implicit would let that second rule decide the outcome here
    // (`TerminationKeepsEarnedRevenueTest` owns it).
    $unpaid = makeInvoice($this->lease, [
        'status' => 'issued', 'paid_amount' => 0, 'balance' => 1000,
    ]);
    $partial = makeInvoice($this->lease, [
        'status' => 'partially_paid', 'paid_amount' => 500, 'balance' => 500,
    ]);
    $paid = makeInvoice($this->lease, [
        'status' => 'paid', 'paid_amount' => 1000, 'balance' => 0,
    ]);

    app(LeaseTerminationService::class)->terminate($this->lease, [
        'termination_date' => '2026-01-31',
        'cancel_open_invoices' => true,
    ]);

    expect($unpaid->fresh()->status)->toBe('cancelled');
    expect($unpaid->fresh()->balance)->toBe('0.00');

    // Partially-paid invoice MUST survive — cancelling it would orphan
    // the tenant's 500 EGP paid_amount with no audit trail.
    expect($partial->fresh()->status)->toBe('partially_paid');
    expect((float) $partial->fresh()->balance)->toBe(500.0);

    expect($paid->fresh()->status)->toBe('paid');
});
