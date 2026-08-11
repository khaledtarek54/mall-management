<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Accounting\FiscalCalendar;
use App\Services\ApplyTenantCreditService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Partial credit apply — the invoice apply-credit action now takes an amount (defaulting to the cap,
 * min(credit, invoice balance)) and passes it as $requested, so an operator can apply part of a
 * tenant's on-account credit and keep the rest. Pins the service honouring $requested + the cap.
 */
beforeEach(function () {
    // Auto-apply OFF: these exercise the MANUAL apply path, where an operator chooses the
    // invoice and sometimes the amount. With the automatic trigger on, the credit would be
    // consumed by the invoice's own creation before the test could apply it deliberately.
    app(\App\Settings\BillingSettings::class)->auto_apply_tenant_credit = false;

    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
});

function caPartialInvoice($lease, float $rent): Invoice
{
    $invoice = makeInvoice($lease, [
        'status' => 'issued', 'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
        'subtotal' => $rent, 'vat_amount' => 0, 'total' => $rent, 'balance' => $rent, 'paid_amount' => 0,
    ]);
    $invoice->items()->create([
        'description' => 'Rent', 'type' => 'base_rent', 'amount' => $rent, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => $rent,
    ]);

    return $invoice;
}

function caPartialOverpay(Invoice $invoice, float $surplus): Payment
{
    $payment = Payment::create([
        'tenant_id' => $invoice->tenant_id, 'amount' => round((float) $invoice->total + $surplus, 2),
        'currency' => 'EGP', 'method' => 'cash', 'status' => 'captured', 'payment_date' => now()->toDateString(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => (float) $invoice->total]);
    $invoice->recomputeTotals();

    return $payment;
}

it('applies only the requested amount, leaving the rest as on-account credit', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);
    caPartialOverpay(caPartialInvoice($lease, 5000), 8000); // 8,000 credit on account
    $invB = caPartialInvoice($lease, 5000);

    $applied = app(ApplyTenantCreditService::class)->applyToInvoice($invB->fresh(), 2000);

    expect($applied)->toBe(2000.0)
        ->and((float) $invB->fresh()->balance)->toBe(3000.0)  // 5,000 − 2,000
        ->and($tenant->creditBalance())->toBe(6000.0);        // 8,000 − 2,000 kept
});

it('caps the requested amount at the invoice balance (never over-applies)', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);
    caPartialOverpay(caPartialInvoice($lease, 5000), 10000); // 10,000 credit
    $invB = caPartialInvoice($lease, 3000);

    $applied = app(ApplyTenantCreditService::class)->applyToInvoice($invB->fresh(), 9999);

    expect($applied)->toBe(3000.0)                            // capped at the invoice balance
        ->and((float) $invB->fresh()->balance)->toBe(0.0)
        ->and($tenant->creditBalance())->toBe(7000.0);        // 10,000 − 3,000
});
