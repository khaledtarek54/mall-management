<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Notifications\InvoiceIssuedNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant(['email' => 'cafe-' . uniqid() . '@haya.test']);
    $this->unit = makeUnit($this->asset);
    $this->lease = makeLease($this->unit, $this->tenant, [
        'status' => 'active',
        'commencement_date' => now()->subMonths(3),
        'expiry_date' => now()->addYear(),
        'base_rent_monthly' => 20000,
        'service_charge_monthly' => 3000,
        'payment_terms_days' => 7,
    ]);
    \App\Models\Charge::create([
        'lease_id' => $this->lease->id,
        'name' => 'Base Rent',
        'type' => 'base_rent',
        'amount' => 20000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => false,
        'vat_rate' => 0,
        'start_date' => $this->lease->commencement_date,
        'is_active' => true,
    ]);
});

it('MonthlyBillingService fires the InvoiceIssuedNotification on the tenant when an invoice is created', function () {
    Notification::fake();

    app(MonthlyBillingService::class)->generateForLease(
        $this->lease,
        CarbonImmutable::now()->startOfMonth(),
        false,
    );

    Notification::assertSentTo(
        $this->tenant,
        InvoiceIssuedNotification::class,
        fn (InvoiceIssuedNotification $n) => $n->invoice->lease_id === $this->lease->id
    );
});

it('a captured Payment with allocations fires the PaymentReceivedNotification on the tenant', function () {
    Notification::fake();

    $invoice = makeInvoice($this->lease, [
        'status' => 'issued',
        'total' => 23000,
        'balance' => 23000,
        'tenant_id' => $this->tenant->id,
    ]);

    $payment = Payment::create([
        'tenant_id' => $this->tenant->id,
        'amount' => 23000,
        'currency' => 'EGP',
        'method' => 'bank_transfer',
        'status' => 'initiated',
        'payment_date' => now(),
    ]);
    $payment->invoices()->sync([$invoice->id => ['allocated_amount' => 23000]]);

    // Transition into captured — fires the notification.
    $payment->update(['status' => 'captured']);

    Notification::assertSentTo(
        $this->tenant,
        PaymentReceivedNotification::class,
        fn (PaymentReceivedNotification $n) => $n->payment->id === $payment->id
    );
});

it('a Payment created already-captured but with no allocations yet does NOT fire (operator will allocate next)', function () {
    Notification::fake();

    Payment::create([
        'tenant_id' => $this->tenant->id,
        'amount' => 5000,
        'currency' => 'EGP',
        'method' => 'cash',
        'status' => 'captured',
        'payment_date' => now(),
    ]);

    Notification::assertNothingSent();
});

it('a status flip back to failed does NOT fire the notification a second time', function () {
    Notification::fake();

    $invoice = makeInvoice($this->lease, [
        'status' => 'issued', 'total' => 1000, 'balance' => 1000,
        'tenant_id' => $this->tenant->id,
    ]);
    $payment = Payment::create([
        'tenant_id' => $this->tenant->id,
        'amount' => 1000,
        'currency' => 'EGP',
        'method' => 'bank_transfer',
        'status' => 'initiated',
        'payment_date' => now(),
    ]);
    $payment->invoices()->sync([$invoice->id => ['allocated_amount' => 1000]]);

    $payment->update(['status' => 'captured']);
    Notification::assertSentToTimes($this->tenant, PaymentReceivedNotification::class, 1);

    $payment->update(['status' => 'failed']);
    Notification::assertSentToTimes($this->tenant, PaymentReceivedNotification::class, 1);
});
