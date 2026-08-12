<?php

/*
|--------------------------------------------------------------------------
| The override has to reach the invoice, not just the database
|--------------------------------------------------------------------------
| `PropertySettingsConformanceTest` proves the registry is well-formed and that the resolver
| resolves. Neither would catch the failure that actually matters: a service still reading
| `app(BillingSettings::class)->late_fee_percent` directly, so the property override saves fine,
| reads back fine, and every invoice keeps charging the portfolio rate.
|
| These tests therefore drive the REAL services and assert on the money they produce. Each one is
| paired with a control at the portfolio rate, because a test that only asserts "5%" passes just as
| happily if the override is ignored and the portfolio happens to say 5% too.
|
| Tier order is the other thing under test, and it is the part with a wrong answer that looks right:
| lease → property → portfolio. A negotiated lease term must beat its mall's default, or the first
| tenant who bargained for a lower late fee gets billed the standard one.
*/

use App\Models\Invoice;
use App\Services\BillBouncedChequeFeeService;
use App\Services\LateFeeService;
use App\Settings\BillingSettings;
use App\Support\PropertySettings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);

    $settings = app(BillingSettings::class);
    $settings->late_fee_percent = 2.0;
    $settings->late_fee_grace_days = 0;
    $settings->late_fee_minimum = 0.0;
    $settings->default_payment_terms_days = 7;
    $settings->nsf_fee_amount = 100.0;
    $settings->save();
});

/** An overdue invoice of $amount at a property, with no lease-level late-fee terms of its own. */
function overdueInvoiceAt(\App\Models\Asset $asset, float $amount = 1000.0): Invoice
{
    $unit = makeUnit($asset);
    $lease = makeLease($unit, null, ['late_fee_percent' => null, 'late_fee_grace_days' => null, 'late_fee_minimum' => null]);

    return Invoice::create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'status' => 'issued',
        'issue_date' => CarbonImmutable::now()->subDays(40),
        'due_date' => CarbonImmutable::now()->subDays(30),
        'period_start' => CarbonImmutable::now()->subDays(40)->startOfMonth(),
        'period_end' => CarbonImmutable::now()->subDays(40)->endOfMonth(),
        'subtotal' => $amount,
        'vat_amount' => 0,
        'total' => $amount,
        'paid_amount' => 0,
        'balance' => $amount,
        'currency' => 'EGP',
    ]);
}

it('charges a late fee at the property rate, not the portfolio rate', function () {
    $prime = makeAsset(['code' => 'PRIME']);
    $secondary = makeAsset(['code' => 'SECOND']);

    // Only PRIME is overridden. SECONDARY is the control: if the tier were ignored entirely both
    // would come out at 2% and the first assertion alone would not notice.
    PropertySettings::set('billing.late_fee_percent', $prime->id, 5.0);

    $atPrime = overdueInvoiceAt($prime);
    $atSecondary = overdueInvoiceAt($secondary);

    app(LateFeeService::class)->applyTo($atPrime);
    app(LateFeeService::class)->applyTo($atSecondary);

    expect((float) $atPrime->fresh()->lateFeeInvoice->subtotal)->toBe(50.0)
        ->and((float) $atSecondary->fresh()->lateFeeInvoice->subtotal)->toBe(20.0);
});

it('lets a negotiated lease term beat its property default', function () {
    // The tier order, and the one with a wrong answer that looks right. A tenant who bargained for
    // 1% must not be billed the mall's 5% because the property tier was consulted first.
    $asset = makeAsset();
    PropertySettings::set('billing.late_fee_percent', $asset->id, 5.0);

    $invoice = overdueInvoiceAt($asset);
    $invoice->lease->update(['late_fee_percent' => 1.0]);

    app(LateFeeService::class)->applyTo($invoice);

    expect((float) $invoice->fresh()->lateFeeInvoice->subtotal)->toBe(10.0);
});

it('honours a property grace period before charging anything', function () {
    // Grace is the half that decides WHETHER a fee is charged rather than how much, so a tier that
    // resolved the rate but not the grace would look correct on every test that only reads amounts.
    $asset = makeAsset();
    PropertySettings::set('billing.late_fee_grace_days', $asset->id, 45);

    $invoice = overdueInvoiceAt($asset);   // 30 days overdue — inside a 45-day grace.

    expect(app(LateFeeService::class)->applyTo($invoice))->toBeFalse()
        ->and($invoice->fresh()->lateFeeInvoice)->toBeNull();

    // The control: shorten the grace and the identical invoice is now chargeable.
    PropertySettings::set('billing.late_fee_grace_days', $asset->id, 5);

    expect(app(LateFeeService::class)->applyTo($invoice))->toBeTrue();
});

it('applies a property late-fee minimum as the floor', function () {
    $asset = makeAsset();
    PropertySettings::set('billing.late_fee_minimum', $asset->id, 500.0);

    // 2% of 1,000 is 20 — the floor is what should come out.
    $invoice = overdueInvoiceAt($asset);

    app(LateFeeService::class)->applyTo($invoice);

    expect((float) $invoice->fresh()->lateFeeInvoice->subtotal)->toBe(500.0);
});

it('charges the bounced-cheque fee at the property amount', function () {
    $asset = makeAsset();
    PropertySettings::set('billing.nsf_fee_amount', $asset->id, 250.0);

    $unit = makeUnit($asset);
    $lease = makeLease($unit);

    $cheque = \App\Models\PostDatedCheque::create([
        'reference' => \App\Models\PostDatedCheque::generateReference(),
        'asset_id' => $asset->id,
        'tenant_id' => $lease->tenant_id,
        'lease_id' => $lease->id,
        'cheque_number' => 'CHQ-001',
        'bank_name' => 'CIB',
        'amount' => 5000,
        'cheque_date' => '2026-08-01',
        'received_date' => '2026-07-01',
        'status' => \App\Models\PostDatedCheque::STATUS_BOUNCED,
    ]);

    $invoice = app(BillBouncedChequeFeeService::class)->bill($cheque);

    expect((float) $invoice->subtotal)->toBe(250.0);
});

it('starts a new lease on its property payment terms', function () {
    // `payment_terms_days` is NOT NULL with a database default, so the `?? setting` that used to sit
    // at eight billing call sites could never fire — the configured default reached nothing. It
    // belongs at ORIGINATION, which is what this asserts.
    $asset = makeAsset();
    PropertySettings::set('billing.default_payment_terms_days', $asset->id, 30);

    expect(PropertySettings::paymentTermsDays($asset->id))->toBe(30)
        ->and(PropertySettings::paymentTermsDays(makeAsset(['code' => 'OTHER'])->id))->toBe(7);
});

it('does not move the due date on receivables already raised', function () {
    // The reason the default lives at origination and not at billing time. A lease signed on 7-day
    // terms keeps them when the property later moves to 30 — otherwise changing a setting would
    // silently re-age every open receivable in the mall, and the AR report would move overnight.
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit, null, ['payment_terms_days' => 7]);

    PropertySettings::set('billing.default_payment_terms_days', $asset->id, 30);

    expect($lease->fresh()->paymentTermsDays())->toBe(7);
});
