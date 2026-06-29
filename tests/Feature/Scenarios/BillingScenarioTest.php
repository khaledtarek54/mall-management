<?php

/*
|--------------------------------------------------------------------------
| Monthly billing scenarios — App\Services\MonthlyBillingService
|--------------------------------------------------------------------------
| NET-NEW coverage for generateForLease(): invoice generation from charges,
| VAT math (14% on service charge, base rent VAT-exempt), first-period
| proration vs full month, idempotency, no-applicable-charges skip,
| non-active leases, and due_date derivation from billing terms.
|
| BillingMathTest.php already covers runForPeriod() idempotency at the batch
| level; these tests target the single-lease entry point + exact amounts.
*/

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/**
 * Attach a charge to a lease. Defaults to a VAT-exempt monthly charge so each
 * test opts into exactly the shape it needs.
 */
function billingCharge(Lease $lease, array $attrs = []): Charge
{
    return Charge::create(array_merge([
        'lease_id' => $lease->id,
        'name' => 'Base Rent',
        'type' => 'base_rent',
        'amount' => 10000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => false,
        'vat_rate' => 0,
        'start_date' => '2026-01-01',
        'is_active' => true,
    ], $attrs));
}

/** A lease commencing on the 1st so full-month billing is unambiguous. */
function billingLease(array $attrs = []): Lease
{
    $asset = makeAsset(['code' => 'BIL' . strtoupper(substr(uniqid(), -3))]);
    $unit = makeUnit($asset, ['status' => 'occupied']);

    return makeLease($unit, null, array_merge([
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2027-12-31',
        'payment_terms_days' => 7,
    ], $attrs));
}

beforeEach(function () {
    // Invoice creation fires the issued-notification (mail + database). Fake it
    // so no PDF/email is built and we can assert it was dispatched.
    Notification::fake();
});

/*
|--------------------------------------------------------------------------
| HAPPY PATH — generation + line items
|--------------------------------------------------------------------------
*/

it('creates an invoice with one line per applicable charge', function () {
    $lease = billingLease();
    billingCharge($lease, ['name' => 'Base Rent', 'type' => 'base_rent', 'amount' => 10000]);
    billingCharge($lease, [
        'name' => 'Service Charge', 'type' => 'service_charge', 'amount' => 2000,
        'vat_applicable' => true, 'vat_rate' => 14,
    ]);

    $result = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));

    expect($result['status'])->toBe('created');

    $invoice = $result['invoice'];
    expect($invoice)->toBeInstanceOf(Invoice::class)
        ->and($invoice->items()->count())->toBe(2)
        ->and($invoice->status)->toBe('issued')
        ->and($invoice->lease_id)->toBe($lease->id)
        ->and($invoice->tenant_id)->toBe($lease->tenant_id);

    // Each charge maps to exactly one line, labelled with the period.
    $rentLine = $invoice->items()->where('type', 'base_rent')->first();
    $svcLine = $invoice->items()->where('type', 'service_charge')->first();
    expect($rentLine->description)->toBe('Base Rent - March 2026')
        ->and($svcLine->description)->toBe('Service Charge - March 2026');
});

it('computes subtotal, VAT and total exactly — service charge taxed, base rent exempt', function () {
    $lease = billingLease();
    billingCharge($lease, ['name' => 'Base Rent', 'type' => 'base_rent', 'amount' => 10000]);
    billingCharge($lease, [
        'name' => 'Service Charge', 'type' => 'service_charge', 'amount' => 2000,
        'vat_applicable' => true, 'vat_rate' => 14,
    ]);

    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-03-01'))['invoice'];

    // base 10000 (no VAT) + service 2000 (+14% = 280 VAT).
    expect((float) $invoice->subtotal)->toBe(12000.00)
        ->and((float) $invoice->vat_amount)->toBe(280.00)
        ->and((float) $invoice->total)->toBe(12280.00)
        ->and((float) $invoice->balance)->toBe(12280.00)
        ->and((float) $invoice->paid_amount)->toBe(0.0);

    // Line-level VAT: base rent line has zero VAT, service line has 14%.
    $rentLine = $invoice->items()->where('type', 'base_rent')->first();
    $svcLine = $invoice->items()->where('type', 'service_charge')->first();
    expect((float) $rentLine->vat_amount)->toBe(0.0)
        ->and((float) $rentLine->total)->toBe(10000.00)
        ->and((float) $svcLine->vat_rate)->toBe(14.00)
        ->and((float) $svcLine->vat_amount)->toBe(280.00)
        ->and((float) $svcLine->total)->toBe(2280.00);
});

it('dispatches the invoice-issued notification to the tenant on creation', function () {
    $lease = billingLease();
    billingCharge($lease);

    $result = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));

    expect($result['status'])->toBe('created');

    Notification::assertSentTo(
        $lease->tenant,
        \App\Notifications\InvoiceIssuedNotification::class,
    );
});

/*
|--------------------------------------------------------------------------
| DUE DATE — derived from the lease's billing terms
|--------------------------------------------------------------------------
*/

it('derives the due_date as period start + payment_terms_days', function () {
    $lease = billingLease(['payment_terms_days' => 30]);
    billingCharge($lease);

    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-03-01'))['invoice'];

    // issue/period start = 2026-03-01, + 30 days = 2026-03-31.
    expect($invoice->issue_date->toDateString())->toBe('2026-03-01')
        ->and($invoice->period_start->toDateString())->toBe('2026-03-01')
        ->and($invoice->period_end->toDateString())->toBe('2026-03-31')
        ->and($invoice->due_date->toDateString())->toBe('2026-03-31');
});

it('honours a different payment_terms_days value', function () {
    $lease = billingLease(['payment_terms_days' => 14]);
    billingCharge($lease);

    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-04-01'))['invoice'];

    // 2026-04-01 + 14 days = 2026-04-15.
    expect($invoice->due_date->toDateString())->toBe('2026-04-15');
});

/*
|--------------------------------------------------------------------------
| PRORATION — first partial month vs full month
|--------------------------------------------------------------------------
*/

it('bills a full month (factor 1.0) when prorate is not requested even mid-period commencement', function () {
    // Lease commences 2026-03-15, but prorate=false → full month billed.
    $lease = billingLease(['commencement_date' => '2026-03-15']);
    billingCharge($lease, ['amount' => 10000, 'start_date' => '2026-03-15']);

    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-03-01'), prorate: false)['invoice'];

    expect((float) $invoice->subtotal)->toBe(10000.00)
        ->and($invoice->period_start->toDateString())->toBe('2026-03-01')
        ->and($invoice->items()->first()->description)->toBe('Base Rent - March 2026')
        ->and($invoice->items()->first()->description)->not->toContain('pro-rated');
});

it('pro-rates the first partial month when prorate is requested', function () {
    // Commences 2026-03-15. March has 31 days; from the 15th inclusive that is
    // 17 days → factor 17/31 = 0.5484.
    $lease = billingLease(['commencement_date' => '2026-03-15', 'payment_terms_days' => 7]);
    billingCharge($lease, ['amount' => 10000, 'start_date' => '2026-03-15']);

    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-03-01'), prorate: true)['invoice'];

    // 10000 * 17/31 = 5483.87 (amount rounded to 2dp, not the factor).
    expect((float) $invoice->subtotal)->toBe(5483.87)
        ->and((float) $invoice->total)->toBe(5483.87);

    // Period + due date shift to the commencement date, not the 1st.
    expect($invoice->period_start->toDateString())->toBe('2026-03-15')
        ->and($invoice->issue_date->toDateString())->toBe('2026-03-15')
        ->and($invoice->due_date->toDateString())->toBe('2026-03-22')
        ->and($invoice->period_end->toDateString())->toBe('2026-03-31');

    // The line label flags the proration percentage (round(0.5484*100) = 55%).
    expect($invoice->items()->first()->description)->toBe('Base Rent - March 2026 (55% pro-rated)');
});

it('pro-rates VAT on the reduced base for a taxed charge', function () {
    $lease = billingLease(['commencement_date' => '2026-03-15']);
    billingCharge($lease, [
        'name' => 'Service Charge', 'type' => 'service_charge', 'amount' => 2000,
        'vat_applicable' => true, 'vat_rate' => 14, 'start_date' => '2026-03-15',
    ]);

    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-03-01'), prorate: true)['invoice'];

    // 2000 * 17/31 = 1096.77 ; VAT = round(1096.77 * 14%) = 153.55.
    expect((float) $invoice->subtotal)->toBe(1096.77)
        ->and((float) $invoice->vat_amount)->toBe(153.55)
        ->and((float) $invoice->total)->toBe(1250.32);
});

it('does NOT pro-rate a lease that commenced before the billed period', function () {
    // Commenced 2026-01-01; billing March → commencement is not within March,
    // so even with prorate=true the factor stays 1.0.
    $lease = billingLease(['commencement_date' => '2026-01-01']);
    billingCharge($lease, ['amount' => 10000]);

    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-03-01'), prorate: true)['invoice'];

    expect((float) $invoice->subtotal)->toBe(10000.00)
        ->and($invoice->period_start->toDateString())->toBe('2026-03-01')
        ->and($invoice->items()->first()->description)->not->toContain('pro-rated');
});

it('does NOT pro-rate when commencement is exactly the period start', function () {
    // Commences on the 1st — greaterThan($periodStart) is false → full month.
    $lease = billingLease(['commencement_date' => '2026-03-01']);
    billingCharge($lease, ['amount' => 10000, 'start_date' => '2026-03-01']);

    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-03-01'), prorate: true)['invoice'];

    expect((float) $invoice->subtotal)->toBe(10000.00)
        ->and($invoice->period_start->toDateString())->toBe('2026-03-01');
});

/*
|--------------------------------------------------------------------------
| IDEMPOTENCY — re-running the same period
|--------------------------------------------------------------------------
*/

it('skips with already_billed when an invoice already covers the period', function () {
    $lease = billingLease();
    billingCharge($lease);
    $service = app(MonthlyBillingService::class);
    $period = CarbonImmutable::parse('2026-03-01');

    $first = $service->generateForLease($lease, $period);
    $second = $service->generateForLease($lease, $period);

    expect($first['status'])->toBe('created')
        ->and($second['status'])->toBe('skipped')
        ->and($second['reason'])->toBe('already_billed')
        ->and($second['invoice'])->toBeNull()
        ->and(Invoice::where('lease_id', $lease->id)->count())->toBe(1);
});

it('still bills a different period after one period is billed', function () {
    $lease = billingLease();
    billingCharge($lease);
    $service = app(MonthlyBillingService::class);

    $march = $service->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));
    $april = $service->generateForLease($lease, CarbonImmutable::parse('2026-04-01'));

    expect($march['status'])->toBe('created')
        ->and($april['status'])->toBe('created')
        ->and(Invoice::where('lease_id', $lease->id)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| NO APPLICABLE CHARGES — skip without an invoice
|--------------------------------------------------------------------------
*/

it('skips with no_applicable_charges when the lease has no charges', function () {
    $lease = billingLease();

    $result = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('no_applicable_charges')
        ->and($result['invoice'])->toBeNull()
        ->and(Invoice::where('lease_id', $lease->id)->count())->toBe(0);
});

it('skips when the only charge is inactive', function () {
    $lease = billingLease();
    billingCharge($lease, ['is_active' => false]);

    $result = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('no_applicable_charges');
});

it('skips when the charge window ends before the billed period', function () {
    $lease = billingLease();
    billingCharge($lease, ['start_date' => '2026-01-01', 'end_date' => '2026-02-28']);

    $result = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('no_applicable_charges');
});

it('skips when the charge window starts after the billed period', function () {
    $lease = billingLease();
    billingCharge($lease, ['start_date' => '2026-06-01']);

    $result = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('no_applicable_charges');
});

it('bills only the charges whose frequency applies in the period (annual off-month skipped)', function () {
    $lease = billingLease();
    // Monthly rent always applies; annual fee only in its anniversary month (Jan).
    billingCharge($lease, ['name' => 'Base Rent', 'type' => 'base_rent', 'amount' => 10000, 'frequency' => 'monthly']);
    billingCharge($lease, [
        'name' => 'Annual Fee', 'type' => 'other', 'amount' => 5000,
        'frequency' => 'annually', 'start_date' => '2026-01-01',
    ]);

    // Billing March → the January-anchored annual fee does NOT apply.
    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-03-01'))['invoice'];

    expect($invoice->items()->count())->toBe(1)
        ->and($invoice->items()->first()->type)->toBe('base_rent')
        ->and((float) $invoice->subtotal)->toBe(10000.00);
});

/*
|--------------------------------------------------------------------------
| STATE — only active leases are billed by generateForLease
|--------------------------------------------------------------------------
| generateForLease does NOT itself re-check status; runForPeriod scopes to
| active leases. These cover what the batch run excludes.
|--------------------------------------------------------------------------
*/

it('runForPeriod does not bill a draft lease', function () {
    $lease = billingLease(['status' => 'draft']);
    billingCharge($lease);

    $stats = app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2026-03-01'));

    expect($stats['created'])->toBe(0)
        ->and(Invoice::where('lease_id', $lease->id)->count())->toBe(0);
});

it('runForPeriod does not bill a terminated lease', function () {
    $lease = billingLease(['status' => 'terminated']);
    billingCharge($lease);

    $stats = app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2026-03-01'));

    expect($stats['created'])->toBe(0)
        ->and(Invoice::where('lease_id', $lease->id)->count())->toBe(0);
});

it('runForPeriod does not bill a lease whose expiry precedes the period', function () {
    $lease = billingLease(['status' => 'active', 'expiry_date' => '2026-02-28']);
    billingCharge($lease);

    $stats = app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2026-03-01'));

    expect($stats['created'])->toBe(0)
        ->and(Invoice::where('lease_id', $lease->id)->count())->toBe(0);
});

it('runForPeriod bills exactly the one active in-window lease among mixed states', function () {
    $active = billingLease(['status' => 'active']);
    billingCharge($active);
    $draft = billingLease(['status' => 'draft']);
    billingCharge($draft);
    $terminated = billingLease(['status' => 'terminated']);
    billingCharge($terminated);

    $stats = app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2026-03-01'));

    expect($stats['created'])->toBe(1)
        ->and(Invoice::where('lease_id', $active->id)->count())->toBe(1)
        ->and(Invoice::where('lease_id', $draft->id)->count())->toBe(0)
        ->and(Invoice::where('lease_id', $terminated->id)->count())->toBe(0);
});
