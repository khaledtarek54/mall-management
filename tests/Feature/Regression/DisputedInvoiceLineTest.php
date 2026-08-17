<?php

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Services\DisputeInvoiceItemService;
use App\Services\LateFeeService;
use App\Services\Reports\ReportService;
use Carbon\CarbonImmutable;

/**
 * A disputed line is not chargeable a late fee (phase 8, story MF-07).
 *
 * **The complaint this stops.** The sweep charged a percentage of the WHOLE balance, including a
 * service charge the tenant had formally disputed — which starts a second argument, about the fee,
 * on top of the first. Now only the undisputed part is chargeable.
 *
 * **It does not reduce the invoice.** The debt is still claimed, still aged, still on the balance
 * sheet; it is simply not yet chargeable. Anything else would be writing off a debt through a flag,
 * which is `WriteOffInvoiceService`'s job and a different decision with a different authority.
 *
 * **The header status is deliberately untouched.** `invoices.status` already has a `disputed` value
 * and it is the wrong tool: an invoice is rarely disputed in full, and marking the header stops
 * chasing the rent on the same document, which nobody is arguing about.
 *
 * **Only the OUTSTANDING part of a line is disputed**, which is why this composes on MF-06 rather
 * than reading line totals: a part-paid line is argued about for what is still owed on it.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function overdueTwoLineInvoice(): Invoice
{
    $lease = makeLease(makeUnit(makeAsset(), ['code' => 'DP-1']), null, ['status' => 'active']);

    $invoice = makeInvoice($lease, [
        'status' => 'overdue',
        'issue_date' => '2026-03-01',
        'due_date' => '2026-03-10',
        'subtotal' => 40000, 'vat_amount' => 0, 'total' => 40000,
    ]);

    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'description' => 'Base rent — March',
        'type' => 'base_rent', 'amount' => 30000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 30000,
    ]);
    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'description' => 'Service charge — March',
        'type' => 'service_charge', 'amount' => 10000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000,
    ]);

    $invoice->recomputeTotals();
    $invoice->save();

    return $invoice->fresh();
}

function disputeThe(string $type, Invoice $invoice, string $reason = 'Tenant contests the 2026 CAM reconciliation.'): InvoiceItem
{
    /** @var InvoiceItem $item */
    $item = $invoice->items()->where('type', $type)->firstOrFail();

    return app(DisputeInvoiceItemService::class)->dispute($item, $reason);
}

it('charges the late fee on the undisputed part only', function () {
    // 40,000 owed, 10,000 disputed → the fee is the standard rate on 30,000, not on 40,000.
    CarbonImmutable::setTestNow('2026-04-01');
    $invoice = overdueTwoLineInvoice();
    disputeThe('service_charge', $invoice);

    app(LateFeeService::class)->runForToday(CarbonImmutable::parse('2026-04-01'));

    $fee = lateFeeItems($invoice)->sole();

    expect((float) $fee->total)->toBe(600.0);   // 2% of 30,000
});

it('charges nothing at all when the whole balance is disputed', function () {
    // Falling through to the MINIMUM fee would bill the floor off a balance nobody has agreed is
    // owed — precisely the charge this story exists to prevent.
    CarbonImmutable::setTestNow('2026-04-01');
    $invoice = overdueTwoLineInvoice();
    disputeThe('base_rent', $invoice);
    disputeThe('service_charge', $invoice);

    app(LateFeeService::class)->runForToday(CarbonImmutable::parse('2026-04-01'));

    expect(lateFeeItems($invoice)->exists())->toBeFalse();
});

it('still charges the full fee when nothing is disputed', function () {
    // The control. A refusal test passes just as happily when everything is refused.
    CarbonImmutable::setTestNow('2026-04-01');
    $invoice = overdueTwoLineInvoice();

    app(LateFeeService::class)->runForToday(CarbonImmutable::parse('2026-04-01'));

    expect((float) lateFeeItems($invoice)->sole()->total)->toBe(800.0);   // 2% of 40,000
});

it('leaves the balance and the header status alone', function () {
    // The debt is still claimed. A dispute is not a write-off, and the rent on the same document is
    // undisputed and still collectable.
    CarbonImmutable::setTestNow('2026-04-01');
    $invoice = overdueTwoLineInvoice();
    disputeThe('service_charge', $invoice);

    expect((float) $invoice->fresh()->balance)->toBe(40000.0)
        ->and($invoice->fresh()->status)->toBe('overdue');
});

it('disputes only what is still owed on a part-paid line', function () {
    // Composes on MF-06 rather than reading line totals: a line the tenant part-paid is argued about
    // for the remainder, and using the gross figure would suppress a fee on money already settled.
    CarbonImmutable::setTestNow('2026-04-01');
    $invoice = overdueTwoLineInvoice();

    $payment = Payment::create([
        'tenant_id' => $invoice->tenant_id, 'amount' => 36000,
        'payment_date' => '2026-03-20', 'method' => 'bank_transfer', 'status' => 'captured',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 36000]);
    $invoice->refresh()->recomputeTotals();
    $invoice->save();

    // Rent settles first by priority, so 6,000 of the 10,000 service charge is settled too.
    disputeThe('service_charge', $invoice);

    expect(DisputeInvoiceItemService::disputedOutstanding($invoice->fresh()))->toBe(4000.0);

    app(LateFeeService::class)->runForToday(CarbonImmutable::parse('2026-04-01'));

    // Balance 4,000, all of it disputed → nothing chargeable.
    expect(lateFeeItems($invoice)->exists())->toBeFalse();
});

it('refuses a dispute with no stated reason', function () {
    $invoice = overdueTwoLineInvoice();

    /** @var InvoiceItem $item */
    $item = $invoice->items()->where('type', 'service_charge')->firstOrFail();

    expect(fn () => app(DisputeInvoiceItemService::class)->dispute($item, '   '))
        ->toThrow(DomainException::class);

    // The control: the same line, with a reason, is accepted.
    expect(app(DisputeInvoiceItemService::class)->dispute($item, 'Awaiting the audited pool.')->isDisputed())
        ->toBeTrue();
});

it('refuses a dispute on a line that is already settled', function () {
    CarbonImmutable::setTestNow('2026-04-01');
    $invoice = overdueTwoLineInvoice();

    $payment = Payment::create([
        'tenant_id' => $invoice->tenant_id, 'amount' => 40000,
        'payment_date' => '2026-03-20', 'method' => 'bank_transfer', 'status' => 'captured',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 40000]);
    $invoice->refresh()->recomputeTotals();
    $invoice->save();

    /** @var InvoiceItem $item */
    $item = $invoice->fresh()->items()->where('type', 'service_charge')->firstOrFail();

    expect(fn () => app(DisputeInvoiceItemService::class)->dispute($item, 'Too late.'))
        ->toThrow(DomainException::class);
});

it('refuses a dispute on an invoice that left the books', function () {
    $invoice = overdueTwoLineInvoice();
    $invoice->update(['status' => 'cancelled']);

    /** @var InvoiceItem $item */
    $item = $invoice->items()->where('type', 'service_charge')->firstOrFail();

    expect(fn () => app(DisputeInvoiceItemService::class)->dispute($item, 'Nothing to argue about.'))
        ->toThrow(DomainException::class);
});

it('makes the line chargeable again once the dispute is resolved', function () {
    CarbonImmutable::setTestNow('2026-04-01');
    $invoice = overdueTwoLineInvoice();
    $item = disputeThe('service_charge', $invoice);

    app(DisputeInvoiceItemService::class)->resolve($item);

    app(LateFeeService::class)->runForToday(CarbonImmutable::parse('2026-04-01'));

    expect((float) lateFeeItems($invoice)->sole()->total)->toBe(800.0);
});

it('shows the disputed amount beside the aged figure, not deducted from it', function () {
    // Netting it out of aging would understate what the mall is owed. The debt is still claimed.
    CarbonImmutable::setTestNow('2026-04-01');
    $invoice = overdueTwoLineInvoice();
    disputeThe('service_charge', $invoice);

    $report = app(ReportService::class)->arAgingByChargeType(CarbonImmutable::parse('2026-04-01')->endOfDay());

    expect($report['total'])->toBe(40000.0)
        ->and($report['disputed'])->toBe(10000.0)
        ->and($report['rows']->firstWhere('type', 'service_charge')['disputed'])->toBe(10000.0)
        ->and($report['rows']->firstWhere('type', 'base_rent')['disputed'])->toBe(0.0);
});
