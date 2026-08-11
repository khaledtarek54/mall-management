<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\TenantCreditApplication;
use App\Settings\BillingSettings;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * On-account credit is applied to a new invoice automatically — Voyager's behaviour.
 *
 * Yardi applies open credit to the next charge without being asked; Atriom waited for an operator,
 * which meant a tenant sitting on a credit still received a full bill and somebody had to remember
 * to offset it. The mechanism was already correct — `ApplyTenantCreditService` posts its own dated
 * `Dr Unearned / Cr AR` document, row-locks the tenant and caps at the lesser of the credit and the
 * balance — so this changes only the TRIGGER, never the accounting.
 *
 * Hooked on the model rather than in the billing service: an invoice is raised from six paths
 * (monthly run, CAM recovery, percentage-rent overage, violation fine, NSF fee, manual) and a hook
 * per path is the arrangement where one gets forgotten.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->lease = makeLease(makeUnit(makeAsset()), null, ['status' => 'active']);
    $this->tenant = $this->lease->tenant;
});

/**
 * Credit on account, the way it actually arises: an OVERPAYMENT.
 *
 * A wholly unallocated receipt is not per-property credit — `Tenant::creditBalance($assetIds)`
 * scopes through `invoices.lease.unit`, so a payment linked to nothing belongs to no property and
 * cannot be drawn against a property's invoice. That is deliberate (a null scope silently widens to
 * every property, the documented leak), and it is why the fixture pays an earlier bill and leaves a
 * surplus rather than inventing a floating balance.
 */
function creditOnAccount(float $surplus): Payment
{
    $earlier = makeInvoice(test()->lease, ['status' => 'issued', 'total' => 1000, 'balance' => 1000]);

    $payment = Payment::create([
        'tenant_id' => test()->tenant->id,
        'payment_date' => now(),
        'amount' => 1000 + $surplus,
        'method' => 'bank_transfer',
        'status' => 'captured',
        'currency' => 'EGP',
    ]);
    $payment->invoices()->attach($earlier->id, ['allocated_amount' => 1000]);
    $earlier->recomputeTotals();

    return $payment;
}

it('offsets a new invoice against the credit the tenant already holds', function () {
    creditOnAccount(5000);

    $invoice = makeInvoice($this->lease, ['status' => 'issued', 'total' => 12000, 'balance' => 12000]);

    expect((float) $invoice->fresh()->balance)->toBe(7000.0)
        ->and(TenantCreditApplication::where('invoice_id', $invoice->id)->sum('amount'))->toEqual(5000);
});

it('never applies more credit than the invoice owes', function () {
    creditOnAccount(20000);

    $invoice = makeInvoice($this->lease, ['status' => 'issued', 'total' => 8000, 'balance' => 8000]);

    // The invoice is settled, and the remaining 12,000 stays on account rather than going negative.
    expect((float) $invoice->fresh()->balance)->toBe(0.0)
        ->and(TenantCreditApplication::where('invoice_id', $invoice->id)->sum('amount'))->toEqual(8000);
});

it('leaves the invoice alone when the tenant holds no credit', function () {
    $invoice = makeInvoice($this->lease, ['status' => 'issued', 'total' => 9000, 'balance' => 9000]);

    expect((float) $invoice->fresh()->balance)->toBe(9000.0)
        ->and(TenantCreditApplication::where('invoice_id', $invoice->id)->count())->toBe(0);
});

it('does not touch a draft invoice', function () {
    // A draft is not a bill yet. Spending a tenant's credit against something that may never be
    // issued would strand the money on a document that gets deleted.
    creditOnAccount(5000);

    $invoice = makeInvoice($this->lease, ['status' => 'draft', 'total' => 12000, 'balance' => 12000]);

    expect((float) $invoice->fresh()->balance)->toBe(12000.0);
});

it('can be switched off for an operator who refunds rather than offsets', function () {
    // The reason it is a setting: a credit raised in dispute, or one the tenant expects refunded in
    // cash, silently disappearing into next month's rent is a support call. Voyager makes it
    // configurable for the same reason.
    app(BillingSettings::class)->auto_apply_tenant_credit = false;

    creditOnAccount(5000);

    $invoice = makeInvoice($this->lease, ['status' => 'issued', 'total' => 12000, 'balance' => 12000]);

    expect((float) $invoice->fresh()->balance)->toBe(12000.0)
        ->and(TenantCreditApplication::where('invoice_id', $invoice->id)->count())->toBe(0);
});

it('does not recurse when applying re-saves the invoice', function () {
    // Applying a credit SAVES the invoice (its balance drops), which fires the same hook again. The
    // guard is what stops that becoming an infinite loop — and, more quietly, what stops a second
    // application drawing the credit twice.
    creditOnAccount(5000);

    $invoice = makeInvoice($this->lease, ['status' => 'issued', 'total' => 12000, 'balance' => 12000]);

    expect(TenantCreditApplication::where('invoice_id', $invoice->id)->count())->toBe(1)
        ->and((float) $invoice->fresh()->balance)->toBe(7000.0);
});
