<?php

use App\Models\Invoice;
use App\Models\Lease;
use App\Services\LateFeeService;
use App\Settings\BillingSettings;
use Carbon\CarbonImmutable;

/**
 * EG-35, the other half of finding M-8 — a late fee can be charged again while the debt stands.
 *
 * One fee per invoice, ever: a tenant six months late paid the same penalty as one six days late,
 * and a clause reading *"2% per month while the balance remains outstanding"* could not be
 * expressed. The cap shipped first; this was deferred because it needed a schema change on a money
 * link rather than a settings field.
 *
 * **It ships OFF.** 0 = charge once, which is what every install has done since late fees existed,
 * so no penalty changes on deploy. Charging repeatedly is not a switch to flip for a portfolio by
 * accident — Egyptian practice and the rules around compounding are the accountant's ground — which
 * is why the default is off and the term is negotiable per lease.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

beforeEach(function () {
    $s = app(BillingSettings::class);
    $s->late_fee_percent = 2;
    $s->late_fee_grace_days = 7;
    $s->late_fee_minimum = 50;
    $s->late_fee_maximum = 0;
    $s->late_fee_recurrence_days = 0;
});

function overdueFor(Lease $lease, float $balance = 10000): Invoice
{
    return makeInvoice($lease, ['due_date' => '2028-01-01', 'status' => 'overdue', 'balance' => $balance]);
}

it('charges once and then stops, while recurrence is off', function () {
    // The control, and the deploy safety case.
    CarbonImmutable::setTestNow('2028-02-01');

    $lease = makeLease(makeUnit(makeAsset()), null, ['late_fee_percent' => 2]);
    $invoice = overdueFor($lease);

    expect(app(LateFeeService::class)->applyTo($invoice))->toBeTrue();

    CarbonImmutable::setTestNow('2028-06-01');

    expect(app(LateFeeService::class)->applyTo($invoice->fresh()))->toBeFalse()
        ->and($invoice->fresh()->lateFeesRaised()->count())->toBe(1);
});

it('charges again once the clause window has elapsed', function () {
    CarbonImmutable::setTestNow('2028-02-01');

    $lease = makeLease(makeUnit(makeAsset()), null, [
        'late_fee_percent' => 2, 'late_fee_recurrence_days' => 30,
    ]);
    $invoice = overdueFor($lease);

    expect(app(LateFeeService::class)->applyTo($invoice))->toBeTrue();

    // 29 days later — the window has not elapsed.
    CarbonImmutable::setTestNow('2028-03-01');
    expect(app(LateFeeService::class)->applyTo($invoice->fresh()))->toBeFalse();

    // 30 days after the first fee was ISSUED.
    CarbonImmutable::setTestNow('2028-03-02');
    expect(app(LateFeeService::class)->applyTo($invoice->fresh()))->toBeTrue()
        ->and($invoice->fresh()->lateFeesRaised()->count())->toBe(2);
});

it('never lets a late fee earn a late fee', function () {
    // The bar that must survive recurrence. A fee invoice's only line is itself of type `late_fee`,
    // and it goes past due like any other invoice — with recurrence on, nothing but this stops the
    // penalty compounding on the penalty.
    CarbonImmutable::setTestNow('2028-02-01');

    $lease = makeLease(makeUnit(makeAsset()), null, [
        'late_fee_percent' => 2, 'late_fee_recurrence_days' => 1,
    ]);
    $invoice = overdueFor($lease);

    app(LateFeeService::class)->applyTo($invoice);

    $fee = $invoice->fresh()->lateFeesRaised()->sole();
    $fee->update(['status' => 'overdue', 'balance' => 200, 'due_date' => '2028-02-01']);

    CarbonImmutable::setTestNow('2028-03-01');

    expect(app(LateFeeService::class)->applyTo($fee->fresh()))->toBeFalse();
});

it('keeps every fee linked to what it penalises', function () {
    // The audit trail. Before the back-pointer, the only record that an earlier fee came from this
    // invoice was a sentence inside its own line description.
    CarbonImmutable::setTestNow('2028-02-01');

    $lease = makeLease(makeUnit(makeAsset()), null, [
        'late_fee_percent' => 2, 'late_fee_recurrence_days' => 30,
    ]);
    $invoice = overdueFor($lease);

    app(LateFeeService::class)->applyTo($invoice);
    CarbonImmutable::setTestNow('2028-03-05');
    app(LateFeeService::class)->applyTo($invoice->fresh());

    $fees = $invoice->fresh()->lateFeesRaised;

    expect($fees)->toHaveCount(2)
        ->and($fees->pluck('late_fee_for_invoice_id')->unique()->all())->toBe([$invoice->id])
        // …and the source still names the most recent, which is what the existing readers key on.
        ->and($invoice->fresh()->late_fee_invoice_id)->toBe($fees->first()->id);
});

it('lets a cancelled fee be re-charged without waiting out the window', function () {
    // Unchanged behaviour, and recurrence must not break it: a fee raised in error is voided and
    // re-charged, rather than the tenant waiting a month for the clause to come round again.
    CarbonImmutable::setTestNow('2028-02-01');

    $lease = makeLease(makeUnit(makeAsset()), null, [
        'late_fee_percent' => 2, 'late_fee_recurrence_days' => 30,
    ]);
    $invoice = overdueFor($lease);

    app(LateFeeService::class)->applyTo($invoice);
    $invoice->fresh()->lateFeesRaised()->sole()->update(['status' => 'cancelled']);

    CarbonImmutable::setTestNow('2028-02-02');

    expect(app(LateFeeService::class)->applyTo($invoice->fresh()))->toBeTrue();
});
