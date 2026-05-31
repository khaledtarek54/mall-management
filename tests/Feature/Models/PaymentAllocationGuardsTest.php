<?php

use App\Models\Payment;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenantA = makeTenant(['name' => 'Tenant A']);
    $this->tenantB = makeTenant(['name' => 'Tenant B']);
    $this->unitA = makeUnit($this->asset);
    $this->unitB = makeUnit($this->asset);
    $this->leaseA = makeLease($this->unitA, $this->tenantA, ['status' => 'active']);
    $this->leaseB = makeLease($this->unitB, $this->tenantB, ['status' => 'active']);

    $this->invoiceA = makeInvoice($this->leaseA, ['status' => 'issued', 'total' => 1000, 'balance' => 1000]);
    $this->invoiceB = makeInvoice($this->leaseB, ['status' => 'issued', 'total' => 1500, 'balance' => 1500]);

    $this->payment = Payment::create([
        'tenant_id' => $this->tenantA->id,
        'amount' => 1000,
        'currency' => 'EGP',
        'method' => 'bank_transfer',
        'status' => 'captured',
        'payment_date' => now(),
    ]);
});

it('Payment::assertInvoicesShareTenant passes when every invoice belongs to the payment tenant', function () {
    $this->payment->assertInvoicesShareTenant([$this->invoiceA->id]);

    expect(true)->toBeTrue();
});

it('Payment::assertInvoicesShareTenant throws when any invoice belongs to a different tenant', function () {
    $this->payment->assertInvoicesShareTenant([$this->invoiceA->id, $this->invoiceB->id]);
})->throws(\DomainException::class);

it('Payment::assertInvoicesShareTenant is a no-op on empty input', function () {
    $this->payment->assertInvoicesShareTenant([]);

    expect(true)->toBeTrue();
});

it('the helper names the offending invoice in its message', function () {
    try {
        $this->payment->assertInvoicesShareTenant([$this->invoiceB->id]);
        $this->fail('expected a DomainException');
    } catch (\DomainException $e) {
        expect($e->getMessage())->toContain($this->invoiceB->number);
    }
});
