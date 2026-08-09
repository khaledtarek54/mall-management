<?php

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Services\ChargeScheduleService;
use App\Services\LeaseReliefService;
use App\Services\MonthlyBillingService;
use App\Support\Vat;
use Carbon\CarbonImmutable;

/**
 * Temporary relief reverts by itself (phase 2, story LE-03 — scenario S6).
 *
 * The old model could not tell a six-month discount from a permanent rent cut: the operator changed
 * the rent down and kept a diary note to change it back. A failed diary meant half rent forever and
 * nothing in the system objected.
 *
 * The assertion that matters is not "a relief row exists" — it is that **January bills full rent
 * with nobody touching the system**. Everything else here is in service of that.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function reliefLease(float $rent = 100000): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active',
        'commencement_date' => '2027-01-01',
        'expiry_date' => '2029-12-31',
        'base_rent_monthly' => $rent,
        'has_marketing_levy' => false,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_SEED, 'amount' => $rent, 'currency' => 'EGP',
        'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT,
        'start_date' => '2027-01-01', 'is_active' => true,
    ]);

    return $lease->fresh();
}

function rentBilledIn(Lease $lease, string $month): float
{
    Invoice::where('lease_id', $lease->id)->delete();

    app(MonthlyBillingService::class)->generateForLease($lease->fresh(), CarbonImmutable::parse($month));

    return (float) Invoice::where('lease_id', $lease->id)
        ->with('items')
        ->get()
        ->flatMap(fn (Invoice $i) => $i->items)
        ->where('type', 'base_rent')
        ->sum('amount');
}

it('bills the relieved rent inside the window and the full rent again after it, with nobody touching the system', function () {
    // S6: 50% relief for six months, Jul–Dec 2027, then reverts.
    $lease = reliefLease(100000);

    app(LeaseReliefService::class)->grant($lease, [
        'percent_off' => 50,
        'from' => '2027-07-01',
        'to' => '2027-12-31',
        'reason' => 'Six-month concession while the anchor unit is re-let.',
        'document_reference' => 'Side letter 12/2027',
    ]);

    expect(rentBilledIn($lease, '2027-06-15'))->toBe(100000.0)   // before  — untouched
        ->and(rentBilledIn($lease, '2027-07-15'))->toBe(50000.0) // first relieved month
        ->and(rentBilledIn($lease, '2027-12-15'))->toBe(50000.0) // last relieved month
        // THE assertion: January reverts on its own.
        ->and(rentBilledIn($lease, '2028-01-15'))->toBe(100000.0)
        ->and(rentBilledIn($lease, '2028-06-15'))->toBe(100000.0);
});

it('leaves the contracted rent alone, because a concession is not a renegotiation', function () {
    $lease = reliefLease(100000);

    app(LeaseReliefService::class)->grant($lease, [
        'percent_off' => 50, 'from' => '2027-07-01', 'to' => '2027-12-31',
        'reason' => 'Concession.',
    ]);

    // What the lease SAYS is owed is unchanged; only what BILLS moved, and only for six months.
    expect((float) $lease->fresh()->base_rent_monthly)->toBe(100000.0);
});

it('resumes at the post-step amount when the relief window spans a contracted escalation', function () {
    // The trap: relief Jul–Dec over a schedule that steps up on 1 October. Closing the row in
    // force and re-opening it after December would silently delete the October step and under-bill
    // the tenant for the rest of the term.
    $lease = reliefLease(100000);

    app(ChargeScheduleService::class)->setAmount(
        $lease, 'base_rent', 110000, CarbonImmutable::parse('2027-10-01'),
        ['name' => 'Base Rent', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT],
        Charge::ORIGIN_ESCALATION,
    );

    app(LeaseReliefService::class)->grant($lease, [
        'percent_off' => 50, 'from' => '2027-07-01', 'to' => '2027-12-31',
        'reason' => 'Concession spanning the October step.',
    ]);

    expect(rentBilledIn($lease, '2027-09-15'))->toBe(50000.0)   // half of the PRE-step rent
        ->and(rentBilledIn($lease, '2027-10-15'))->toBe(55000.0) // half of the POST-step rent
        // …and the step survives the relief: January bills 110,000, not the pre-July 100,000.
        ->and(rentBilledIn($lease, '2028-01-15'))->toBe(110000.0);
});

it('honours a flat relieved amount as well as a percentage', function () {
    $lease = reliefLease(100000);

    app(LeaseReliefService::class)->grant($lease, [
        'amount' => 30000, 'from' => '2027-07-01', 'to' => '2027-09-30',
        'reason' => 'Fixed concession rent agreed.',
    ]);

    expect(rentBilledIn($lease, '2027-08-15'))->toBe(30000.0)
        ->and(rentBilledIn($lease, '2027-10-15'))->toBe(100000.0);
});

it('never leaves two rows covering one month, so the billing run stays unambiguous', function () {
    $lease = reliefLease(100000);

    app(LeaseReliefService::class)->grant($lease, [
        'percent_off' => 25, 'from' => '2027-07-01', 'to' => '2027-12-31',
        'reason' => 'Concession.',
    ]);

    // Walk the whole term month by month: exactly one active row may govern each month. This is
    // the invariant the write-time overlap guard and assertScheduleUnambiguous() both protect, and
    // an overlay is the operation most likely to break it.
    $rows = Charge::where('lease_id', $lease->id)->where('type', 'base_rent')->where('is_active', true)->get();

    for ($m = CarbonImmutable::parse('2027-01-01'); $m->lte(CarbonImmutable::parse('2029-12-01')); $m = $m->addMonth()) {
        $covering = $rows->filter(fn (Charge $c) => (blank($c->start_date) || CarbonImmutable::instance($c->start_date)->lte($m))
            && (blank($c->end_date) || CarbonImmutable::instance($c->end_date)->gte($m)));

        expect($covering)->toHaveCount(1, "month {$m->format('Y-m')} is covered by {$covering->count()} rows");
    }
});

it('records the relief as an abatement event naming the window and what it reverts to', function () {
    $lease = reliefLease(100000);

    app(LeaseReliefService::class)->grant($lease, [
        'percent_off' => 50, 'from' => '2027-07-01', 'to' => '2027-12-31',
        'reason' => 'Six-month concession while the anchor unit is re-let.',
        'document_reference' => 'Side letter 12/2027',
    ]);

    $event = $lease->fresh()->events()->first();

    expect($event->type)->toBe(LeaseEvent::TYPE_ABATEMENT)
        ->and($event->document_reference)->toBe('Side letter 12/2027')
        ->and($event->payload['window_from'])->toBe('2027-07-01')
        ->and($event->payload['window_to'])->toBe('2027-12-31')
        ->and($event->payload['percent_off'])->toEqual(50)
        ->and($event->payload['reverts_to'])->toEqual(100000.0);
});

it('refuses relief on a charge type the lease does not have', function () {
    expect(fn () => app(LeaseReliefService::class)->grant(reliefLease(), [
        'percent_off' => 50, 'from' => '2027-07-01', 'to' => '2027-12-31',
        'type' => 'service_charge', 'reason' => 'Nothing to relieve.',
    ]))->toThrow(DomainException::class);
});

it('refuses a window that ends before it starts, and relief with neither a percentage nor an amount', function () {
    $lease = reliefLease();

    expect(fn () => app(LeaseReliefService::class)->grant($lease, [
        'percent_off' => 50, 'from' => '2027-12-01', 'to' => '2027-07-31', 'reason' => 'Backwards.',
    ]))->toThrow(DomainException::class);

    expect(fn () => app(LeaseReliefService::class)->grant($lease, [
        'from' => '2027-07-01', 'to' => '2027-12-31', 'reason' => 'Relief of what?',
    ]))->toThrow(InvalidArgumentException::class);

    // The control: neither refusal is a no-op path — a valid grant still works on this lease.
    app(LeaseReliefService::class)->grant($lease, [
        'percent_off' => 50, 'from' => '2027-07-01', 'to' => '2027-12-31', 'reason' => 'Valid.',
    ]);
    expect($lease->fresh()->events()->count())->toBe(1);
});
