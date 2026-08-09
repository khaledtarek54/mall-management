<?php

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Services\AllocatePaymentToInvoiceItemsService;
use App\Support\InvoiceItemSettlement;
use Carbon\CarbonImmutable;

/**
 * Which lines a payment settles (phase 8, story MF-06).
 *
 * **The invoice stays the AR record.** `Invoice::recomputeTotals()` is still the single source of
 * what has been paid, across all four channels; `InvoiceItemSettlement` only splits that one number
 * across the lines. Every test here checks the same property from a different angle: **the item
 * outstandings sum back to `invoices.balance`**. A stored per-item balance would have been the
 * obvious shape and the wrong one — a second truth about the same money, drifting the first time a
 * credit note landed without an item breakdown.
 *
 * **Two ways a line gets settled**: explicitly, when the tenant's remittance advice said what they
 * were paying for; and by charge-type priority for everything else, which is what Yardi does
 * (02-yardi-money-flow.md §4) with the order in `InvoiceItemSettlement::TYPE_PRIORITY`.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

/** An invoice with rent, CAM and a late fee — the shape the story is about. */
function threeLineInvoice(): Invoice
{
    $lease = makeLease(makeUnit(makeAsset(), ['code' => 'AL-1']), null, ['status' => 'active']);

    $invoice = makeInvoice($lease, [
        'status' => 'issued',
        'subtotal' => 40000, 'vat_amount' => 1400, 'total' => 41400,
    ]);

    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'description' => 'Base rent — March',
        'type' => 'base_rent', 'amount' => 30000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 30000,
    ]);
    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'description' => 'Service charge — March',
        'type' => 'service_charge', 'amount' => 10000, 'vat_rate' => 14, 'vat_amount' => 1400, 'total' => 11400,
    ]);
    // Deliberately last in id order and last in priority, so the two orderings can be told apart.
    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'description' => 'Late fee — February',
        'type' => 'late_fee', 'amount' => 0, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 0,
    ]);

    return $invoice->fresh();
}

function payTowards(Invoice $invoice, float $amount): Payment
{
    $payment = Payment::create([
        'tenant_id' => $invoice->tenant_id,
        'amount' => $amount,
        'payment_date' => '2026-03-10',
        'method' => 'bank_transfer',
        'status' => 'captured',
    ]);

    $payment->invoices()->attach($invoice->id, ['allocated_amount' => $amount]);
    $invoice->refresh()->recomputeTotals();
    $invoice->save();

    return $payment->fresh();
}

/** The property every other test is really about. */
function expectItemsToTieToBalance(Invoice $invoice): void
{
    $rows = InvoiceItemSettlement::for($invoice->fresh());

    expect(round((float) $rows->sum('outstanding'), 2))
        ->toBe(round((float) $invoice->fresh()->balance, 2));
}

it('ties the item outstandings back to the invoice balance', function () {
    $invoice = threeLineInvoice();
    payTowards($invoice, 15000);

    expectItemsToTieToBalance($invoice);
});

it('settles rent before the service charge when nobody said otherwise', function () {
    // Yardi applies a receipt by charge-code priority. Rent first is the obligation a landlord most
    // needs secured, and it is what the operator would assume a part payment covered.
    $invoice = threeLineInvoice();
    payTowards($invoice, 30000);

    $rows = InvoiceItemSettlement::for($invoice->fresh())->keyBy('type');

    expect($rows['base_rent']['outstanding'])->toBe(0.0)
        ->and($rows['service_charge']['outstanding'])->toBe(11400.0);

    expectItemsToTieToBalance($invoice);
});

it('records the split the tenant actually sent', function () {
    // THE story: "here is the CAM, we are still arguing about the rent." Without the allocation the
    // priority order would have said the opposite, and the aging would blame the wrong line.
    $invoice = threeLineInvoice();
    $payment = payTowards($invoice, 11400);

    app(AllocatePaymentToInvoiceItemsService::class)->apply($payment, $invoice, [
        $invoice->items()->where('type', 'service_charge')->value('id') => 11400,
    ]);

    $rows = InvoiceItemSettlement::for($invoice->fresh())->keyBy('type');

    expect($rows['service_charge']['outstanding'])->toBe(0.0)
        ->and($rows['base_rent']['outstanding'])->toBe(30000.0);

    expectItemsToTieToBalance($invoice);
});

it('falls back to priority for whatever the split did not cover', function () {
    $invoice = threeLineInvoice();
    $payment = payTowards($invoice, 20000);

    // The advice named only 11,400 of CAM; the other 8,600 is unexplained and follows priority.
    app(AllocatePaymentToInvoiceItemsService::class)->apply($payment, $invoice, [
        $invoice->items()->where('type', 'service_charge')->value('id') => 11400,
    ]);

    $rows = InvoiceItemSettlement::for($invoice->fresh())->keyBy('type');

    expect($rows['service_charge']['outstanding'])->toBe(0.0)
        ->and($rows['base_rent']['settled'])->toBe(8600.0)
        ->and($rows['base_rent']['outstanding'])->toBe(21400.0);

    expectItemsToTieToBalance($invoice);
});

it('counts a credit note against the lines too', function () {
    // A credit note settles AR without touching the payments pivot — the second of four channels.
    // Because the split derives from `paid_amount`, it needs no knowledge of which channel paid.
    $invoice = threeLineInvoice();
    $invoice->credit_applied_amount = 30000;
    $invoice->recomputeTotals();
    $invoice->save();

    $rows = InvoiceItemSettlement::for($invoice->fresh())->keyBy('type');

    expect($rows['base_rent']['outstanding'])->toBe(0.0);
    expectItemsToTieToBalance($invoice);
});

it('stops counting a split whose payment was refunded', function () {
    // The allocation rows survive the refund; honouring them would report a line as paid out of
    // money that is no longer on the invoice, and the tie-out would break.
    $invoice = threeLineInvoice();
    $payment = payTowards($invoice, 11400);

    app(AllocatePaymentToInvoiceItemsService::class)->apply($payment, $invoice, [
        $invoice->items()->where('type', 'service_charge')->value('id') => 11400,
    ]);

    $payment->update(['status' => 'refunded']);
    $invoice->refresh()->recomputeTotals();
    $invoice->save();

    $rows = InvoiceItemSettlement::for($invoice->fresh())->keyBy('type');

    expect($rows['service_charge']['outstanding'])->toBe(11400.0);
    expectItemsToTieToBalance($invoice);
});

it('ignores a refunded payment’s split while another payment still stands', function () {
    // The case that actually needs the received-status filter, and the one the obvious test misses:
    // with a live payment beside the refunded one, the pool is not zero, so simply scaling the
    // allocations to fit would spread the live money across BOTH splits — reporting the CAM as
    // part-paid when it was paid in full, and the rent as part-paid when it was refunded outright.
    $invoice = threeLineInvoice();
    $service = app(AllocatePaymentToInvoiceItemsService::class);

    $live = payTowards($invoice, 11400);
    $service->apply($live, $invoice, [
        $invoice->items()->where('type', 'service_charge')->value('id') => 11400,
    ]);

    $refunded = payTowards($invoice, 30000);
    $service->apply($refunded, $invoice, [
        $invoice->items()->where('type', 'base_rent')->value('id') => 30000,
    ]);

    $refunded->update(['status' => 'refunded']);
    $invoice->refresh()->recomputeTotals();
    $invoice->save();

    $rows = InvoiceItemSettlement::for($invoice->fresh())->keyBy('type');

    expect($rows['service_charge']['outstanding'])->toBe(0.0)
        ->and($rows['base_rent']['outstanding'])->toBe(30000.0);

    expectItemsToTieToBalance($invoice);
});

it('shows nothing outstanding on a cancelled invoice', function () {
    // `recomputeTotals()` forces a cancelled invoice's balance to zero because it left the books.
    // The lines have to agree, or the tie-out is a lie on exactly the invoices an auditor looks at.
    $invoice = threeLineInvoice();
    $invoice->status = 'cancelled';
    $invoice->recomputeTotals();
    $invoice->save();

    expect(InvoiceItemSettlement::for($invoice->fresh())->sum('outstanding'))->toBe(0.0);
    expectItemsToTieToBalance($invoice);
});

it('refuses a split that claims more than the payment gave the invoice', function () {
    $invoice = threeLineInvoice();
    $payment = payTowards($invoice, 5000);

    expect(fn () => app(AllocatePaymentToInvoiceItemsService::class)->apply($payment, $invoice, [
        $invoice->items()->where('type', 'base_rent')->value('id') => 30000,
    ]))->toThrow(DomainException::class);

    // The control: the same call within the payment's own allocation is accepted.
    app(AllocatePaymentToInvoiceItemsService::class)->apply($payment, $invoice, [
        $invoice->items()->where('type', 'base_rent')->value('id') => 5000,
    ]);

    expect(InvoiceItemSettlement::for($invoice->fresh())->keyBy('type')['base_rent']['explicit'])
        ->toBe(5000.0);
});

it('refuses a split against a line on somebody else’s invoice', function () {
    $invoice = threeLineInvoice();
    $other = threeLineInvoice();
    $payment = payTowards($invoice, 5000);

    expect(fn () => app(AllocatePaymentToInvoiceItemsService::class)->apply($payment, $invoice, [
        $other->items()->where('type', 'base_rent')->value('id') => 5000,
    ]))->toThrow(DomainException::class);
});

it('replaces a split rather than stacking a second one', function () {
    $invoice = threeLineInvoice();
    $payment = payTowards($invoice, 11400);
    $service = app(AllocatePaymentToInvoiceItemsService::class);
    $rent = $invoice->items()->where('type', 'base_rent')->value('id');
    $cam = $invoice->items()->where('type', 'service_charge')->value('id');

    $service->apply($payment, $invoice, [$rent => 11400]);
    $service->apply($payment, $invoice, [$cam => 11400]);   // the tenant clarified

    $rows = InvoiceItemSettlement::for($invoice->fresh())->keyBy('type');

    expect($rows['service_charge']['explicit'])->toBe(11400.0)
        ->and($rows['base_rent']['explicit'])->toBe(0.0);

    expectItemsToTieToBalance($invoice);
});

it('reports what is outstanding by charge type', function () {
    // What RR-03's aging is built on.
    $invoice = threeLineInvoice();
    payTowards($invoice, 30000);

    expect(InvoiceItemSettlement::outstandingByType($invoice->fresh()))
        ->toBe(['service_charge' => 11400.0]);
});

it('splits the aging by charge type and still ties to the invoice balances', function () {
    // RR-03. The same money as the AR aging summary, re-cut by what is owed — a headline that reads
    // as delinquent rent, when most of it is a disputed service charge, prompts the wrong call.
    CarbonImmutable::setTestNow('2026-05-01');
    $invoice = threeLineInvoice();
    payTowards($invoice, 30000);              // rent settled by priority; CAM left open

    $report = app(\App\Services\Reports\ReportService::class)
        ->arAgingByChargeType(CarbonImmutable::parse('2026-05-01')->endOfDay());

    expect($report['rows']->pluck('type')->all())->toBe(['service_charge'])
        ->and($report['total'])->toBe(11400.0)
        // Ties to the aging summary, because it re-buckets the same invoices.
        ->and($report['total'])->toBe(round((float) $invoice->fresh()->balance, 2));
});

it('blames the line the tenant actually disputed, not the rent', function () {
    // The point of the whole chain. Same money, same bucket — but the split says which line it is,
    // and only because the operator recorded what the remittance advice said.
    CarbonImmutable::setTestNow('2026-05-01');
    $invoice = threeLineInvoice();
    $payment = payTowards($invoice, 11400);

    app(AllocatePaymentToInvoiceItemsService::class)->apply($payment, $invoice, [
        $invoice->items()->where('type', 'service_charge')->value('id') => 11400,
    ]);

    $report = app(\App\Services\Reports\ReportService::class)
        ->arAgingByChargeType(CarbonImmutable::parse('2026-05-01')->endOfDay());

    expect($report['rows']->pluck('type')->all())->toBe(['base_rent'])
        ->and($report['total'])->toBe(30000.0);
});
