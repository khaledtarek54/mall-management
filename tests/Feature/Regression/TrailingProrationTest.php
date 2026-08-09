<?php

use App\Models\Charge;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Lease;
use App\Services\LeaseTerminationService;
use App\Services\MonthlyBillingService;
use App\Support\Vat;
use Carbon\CarbonImmutable;

/**
 * A lease that ends mid-month pays for the days it ran (phase 4, story MF-02 — scenario S8).
 *
 * Proration was commencement-only: a lease terminating on the 18th was billed the whole month, and
 * S8 records the workaround — "the fix is a manual credit note". Two halves now:
 *
 *   - the invoice NOT YET raised prorates to the termination date;
 *   - the invoice ALREADY raised (rent bills in advance on the 1st, so this is the normal case) is
 *     credited back automatically, using the same month-fraction rule it was billed with.
 *
 * The trap worth pinning: a credit must claw back RENT, never the one-off lines on the same invoice
 * — a utility recharge or a fine is earned in full for something that already happened.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function terminatingLease(float $rent = 300000, string $expiry = '2028-12-31'): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active',
        'commencement_date' => '2028-01-01',
        'expiry_date' => $expiry,
        'base_rent_monthly' => $rent,
        'has_marketing_levy' => false,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_SEED, 'amount' => $rent, 'currency' => 'EGP',
        'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT,
        'start_date' => '2028-01-01', 'is_active' => true,
    ]);

    return $lease->fresh();
}

it('prorates the month a lease expires in, down to the day', function () {
    CarbonImmutable::setTestNow('2028-09-05');
    // Expiry 18 Sep: 18 of September's 30 days.
    $lease = terminatingLease(300000, '2028-09-18');

    $result = app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2028-09-01'));

    expect($result['status'])->toBe('created')
        ->and((float) $result['invoice']->total)->toBe(180000.0)   // 300,000 × 18/30
        ->and($result['invoice']->period_end->toDateString())->toBe('2028-09-18');
});

it('still bills a full month when the lease runs to the last day of it', function () {
    // The control. A lease ending 30 September owes all of September — proration must not shave a
    // day off every end-of-month expiry.
    CarbonImmutable::setTestNow('2028-09-05');
    $lease = terminatingLease(300000, '2028-09-30');

    $result = app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2028-09-01'));

    expect((float) $result['invoice']->total)->toBe(300000.0);
});

it('credits back the unearned days when the month was already billed in full', function () {
    // S8's actual shape: September billed in full on the 1st, the tenant leaves on the 18th.
    CarbonImmutable::setTestNow('2028-09-01');
    $lease = terminatingLease(300000, '2028-12-31');

    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2028-09-01'))['invoice'];

    expect((float) $invoice->total)->toBe(300000.0);

    CarbonImmutable::setTestNow('2028-09-18');
    $lease = app(LeaseTerminationService::class)->terminate($lease->fresh(), [
        'termination_date' => '2028-09-18',
        'reason' => 'Tenant exercised the break clause.',
    ]);

    $note = CreditNote::where('lease_id', $lease->id)->sole();

    // 12 unearned days of 30 → 300,000 × 12/30.
    expect((float) $note->total)->toBe(120000.0)
        ->and($note->issue_date->toDateString())->toBe('2028-09-18')
        ->and($note->invoice_id)->toBe($invoice->id)
        // …and it is APPLIED, so the tenant's balance reflects what they actually owe.
        ->and((float) $invoice->fresh()->balance)->toBe(180000.0);
});

it('leaves a paid invoice alone in AR and parks the credit as tenant credit to refund', function () {
    // When the tenant has already paid, nothing can be netted off the invoice — the money is with
    // the landlord and has to come back. The note stays open, which is what the final account
    // settles.
    CarbonImmutable::setTestNow('2028-09-01');
    $lease = terminatingLease(300000, '2028-12-31');

    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2028-09-01'))['invoice'];

    $invoice->update(['status' => 'paid', 'paid_amount' => 300000, 'balance' => 0]);

    CarbonImmutable::setTestNow('2028-09-18');
    app(LeaseTerminationService::class)->terminate($lease->fresh(), [
        'termination_date' => '2028-09-18', 'reason' => 'Break clause.',
    ]);

    $note = CreditNote::where('lease_id', $lease->id)->sole();

    expect((float) $note->total)->toBe(120000.0)
        ->and((float) $note->balance)->toBe(120000.0)          // nothing to absorb it
        ->and((float) $note->applied_amount)->toBe(0.0)
        ->and((float) $invoice->fresh()->balance)->toBe(0.0);  // the paid invoice is untouched
});

it('claws back rent but never a one-off line earned for something that already happened', function () {
    // The trap. A utility recharge or a fine on the same invoice is for water used and damage done
    // — crediting it pro-rata would refund the tenant for both.
    CarbonImmutable::setTestNow('2028-09-01');
    $lease = terminatingLease(300000, '2028-12-31');

    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2028-09-01'))['invoice'];

    $invoice->items()->create([
        'description' => 'Electricity recharge - August',
        'type' => 'utilities',
        'amount' => 50000,
        'vat_rate' => 0,
        'vat_amount' => 0,
        'total' => 50000,
    ]);
    $invoice->fresh()->recomputeTotals();

    CarbonImmutable::setTestNow('2028-09-18');
    app(LeaseTerminationService::class)->terminate($lease->fresh(), [
        'termination_date' => '2028-09-18', 'reason' => 'Break clause.',
    ]);

    // 12/30 of the RENT only. Nothing of the 50,000 recharge.
    expect((float) CreditNote::where('lease_id', $lease->id)->sole()->total)->toBe(120000.0);
});

it('raises no credit when the lease runs to the end of the billed period', function () {
    // The other control: a lease terminating on the last day of the month it was billed for owes
    // nothing back, and a credit note for zero would be noise in the ledger.
    CarbonImmutable::setTestNow('2028-09-01');
    $lease = terminatingLease(300000, '2028-12-31');

    app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2028-09-01'));

    CarbonImmutable::setTestNow('2028-09-30');
    app(LeaseTerminationService::class)->terminate($lease->fresh(), [
        'termination_date' => '2028-09-30', 'reason' => 'Term run out.',
    ]);

    expect(CreditNote::where('lease_id', $lease->id)->count())->toBe(0);
});

it('lets an operator terminate without the credit when the books say they must', function () {
    CarbonImmutable::setTestNow('2028-09-01');
    $lease = terminatingLease(300000, '2028-12-31');
    app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2028-09-01'));

    CarbonImmutable::setTestNow('2028-09-18');
    app(LeaseTerminationService::class)->terminate($lease->fresh(), [
        'termination_date' => '2028-09-18', 'reason' => 'Credit to follow manually.',
        'credit_unearned' => false,
    ]);

    expect(CreditNote::where('lease_id', $lease->id)->count())->toBe(0)
        ->and($lease->fresh()->status)->toBe('terminated');
});

it('credits the same fraction the invoice was billed with, on a quarterly lease', function () {
    // Two rules that must agree: the invoice's multiplier and the credit's earned/unearned split.
    // A quarter's months are 31, 30 and 31 days, so a day-count across the whole window would
    // disagree with a month-by-month one — which is why both go through monthsCovered().
    CarbonImmutable::setTestNow('2028-07-01');
    $lease = terminatingLease(300000, '2028-12-31');
    $lease->update(['billing_frequency' => 'quarterly']);

    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease->fresh(), CarbonImmutable::parse('2028-07-01'))['invoice'];

    expect((float) $invoice->total)->toBe(900000.0)                       // Jul + Aug + Sep
        ->and($invoice->period_end->toDateString())->toBe('2028-09-30');

    CarbonImmutable::setTestNow('2028-08-15');
    app(LeaseTerminationService::class)->terminate($lease->fresh(), [
        'termination_date' => '2028-08-15', 'reason' => 'Break clause.',
    ]);

    // Earned = Jul (1) + 15/31 of Aug = 1.483871 of 3 months. Unearned = 1.516129/3 of 900,000.
    $earned = 1 + 15 / 31;
    $expected = round(900000 * (1 - $earned / 3), 2);

    expect((float) CreditNote::where('lease_id', $lease->id)->sole()->total)->toBe($expected);
});
