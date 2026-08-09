<?php

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Services\ConvertLeaseToHoldoverService;
use App\Services\MonthlyBillingService;
use App\Support\Vat;
use Carbon\CarbonImmutable;

/**
 * Holdover bills (phase 2, story LE-04 — scenario S9).
 *
 * A lease past its expiry with the tenant still in the unit kept the unit marked occupied, showed
 * on the ActionRequired dashboard, and billed **nothing**: `isBillableForPeriod()` refuses any
 * period starting after expiry. The tenant traded rent-free in the mall's own space until somebody
 * renewed or terminated them, and the alert was the end of the story.
 *
 * The assertion that matters: after conversion, the monthly run produces an invoice at the
 * contracted multiple — through the real service and the real eligibility rules, not by calling
 * the schedule directly.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function expiredLease(float $rent = 100000, string $expiry = '2028-06-30'): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active',
        'commencement_date' => '2026-07-01',
        'expiry_date' => $expiry,
        'base_rent_monthly' => $rent,
        'has_marketing_levy' => false,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_SEED, 'amount' => $rent, 'currency' => 'EGP',
        'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT,
        'start_date' => '2026-07-01', 'is_active' => true,
    ]);

    return $lease->fresh();
}

function rentInvoicedFor(Lease $lease, string $month): float
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

it('bills nothing past expiry until an operator converts, then bills the contracted multiple', function () {
    CarbonImmutable::setTestNow('2028-08-15');
    $lease = expiredLease(100000, '2028-06-30');

    // The gap, reproduced: the tenant is in the unit and owes nothing.
    expect(rentInvoicedFor($lease, '2028-07-15'))->toBe(0.0);

    app(ConvertLeaseToHoldoverService::class)->convert($lease, [
        'rate_pct' => 150,
        'reason' => 'Tenant remains in occupation while renewal terms are negotiated.',
        'document_reference' => 'Letter 04/08/2028',
    ]);

    // …and now it bills, at 150% of the last rent in force, month after month.
    expect(rentInvoicedFor($lease, '2028-07-15'))->toBe(150000.0)
        ->and(rentInvoicedFor($lease, '2028-08-15'))->toBe(150000.0)
        ->and(rentInvoicedFor($lease, '2028-12-15'))->toBe(150000.0);
});

it('uses the settings default when the lease states no holdover rate', function () {
    CarbonImmutable::setTestNow('2028-08-15');
    $lease = expiredLease(100000, '2028-06-30');

    app(ConvertLeaseToHoldoverService::class)->convert($lease, [
        'reason' => 'Occupation continues.',
    ]);

    // BillingSettings::holdover_default_rate_pct — 150% is the Egyptian commercial standard, and a
    // deterrent by design: holding over should cost more than renewing.
    expect((float) $lease->fresh()->holdover_rate_pct)->toBe(150.0)
        ->and(rentInvoicedFor($lease, '2028-07-15'))->toBe(150000.0);
});

it('bases the holdover rent on the rent in force AT EXPIRY, not on a step the term never reached', function () {
    // The trap: phase 1 projects the whole escalation ladder at signing, so a lease can carry a
    // step dated after its own expiry. Reading "the latest row" would hold the tenant over at a
    // rent the lease never actually charged.
    CarbonImmutable::setTestNow('2028-08-15');
    $lease = expiredLease(100000, '2028-06-30');

    // Through the real writer, which is how a projected ladder actually arrives: it closes the
    // row in force at 30 June and opens the July step.
    app(\App\Services\ChargeScheduleService::class)->setAmount(
        $lease, 'base_rent', 130000, CarbonImmutable::parse('2028-07-01'),
        ['name' => 'Base Rent', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT],
        Charge::ORIGIN_ESCALATION,
    );

    app(ConvertLeaseToHoldoverService::class)->convert($lease, [
        'rate_pct' => 150, 'reason' => 'Occupation continues.',
    ]);

    // 150% of 100,000 (what the lease billed), not of 130,000 (what it never reached).
    expect(rentInvoicedFor($lease, '2028-07-15'))->toBe(150000.0);
});

it('leaves exactly one row covering each holdover month', function () {
    CarbonImmutable::setTestNow('2028-08-15');
    $lease = expiredLease(100000, '2028-06-30');

    app(ConvertLeaseToHoldoverService::class)->convert($lease, [
        'rate_pct' => 150, 'reason' => 'Occupation continues.',
    ]);

    $rows = Charge::where('lease_id', $lease->id)->where('type', 'base_rent')->where('is_active', true)->get();

    for ($m = CarbonImmutable::parse('2026-07-01'); $m->lte(CarbonImmutable::parse('2029-06-01')); $m = $m->addMonth()) {
        $covering = $rows->filter(fn (Charge $c) => (blank($c->start_date) || CarbonImmutable::instance($c->start_date)->lte($m))
            && (blank($c->end_date) || CarbonImmutable::instance($c->end_date)->gte($m)));

        expect($covering)->toHaveCount(1, "month {$m->format('Y-m')} is covered by {$covering->count()} rows");
    }
});

it('bills the same way through the scheduled bulk run as through the manual one', function () {
    // The two eligibility copies — isBillableForPeriod() and scopeBillableForPeriod() — are how
    // the manual and scheduled paths drifted apart before. A holdover exemption in only one of
    // them would be that bug again.
    CarbonImmutable::setTestNow('2028-08-15');
    $lease = expiredLease(100000, '2028-06-30');

    app(ConvertLeaseToHoldoverService::class)->convert($lease, [
        'rate_pct' => 150, 'reason' => 'Occupation continues.',
    ]);

    Invoice::where('lease_id', $lease->id)->delete();
    app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2028-07-01'));

    $billed = (float) Invoice::where('lease_id', $lease->id)
        ->with('items')->get()
        ->flatMap(fn (Invoice $i) => $i->items)
        ->where('type', 'base_rent')->sum('amount');

    expect($billed)->toBe(150000.0);
});

it('drops off the action-required card once converted, but stays under the holdover filter', function () {
    CarbonImmutable::setTestNow('2028-08-15');
    $lease = expiredLease(100000, '2028-06-30');

    expect(Lease::holdoverNeedingAction()->pluck('id')->all())->toContain($lease->id);

    app(ConvertLeaseToHoldoverService::class)->convert($lease, [
        'rate_pct' => 150, 'reason' => 'Occupation continues.',
    ]);

    expect(Lease::holdoverNeedingAction()->pluck('id')->all())->not->toContain($lease->id)
        // …still a holdover, still findable — the state did not change, only the outstanding task.
        ->and(Lease::holdover()->pluck('id')->all())->toContain($lease->id);
});

it('records the conversion as a holdover event naming the multiple and what it was applied to', function () {
    CarbonImmutable::setTestNow('2028-08-15');
    $lease = expiredLease(100000, '2028-06-30');

    app(ConvertLeaseToHoldoverService::class)->convert($lease, [
        'rate_pct' => 150,
        'reason' => 'Tenant remains in occupation while renewal terms are negotiated.',
        'document_reference' => 'Letter 04/08/2028',
    ]);

    $event = $lease->fresh()->events()->first();

    expect($event->type)->toBe(LeaseEvent::TYPE_HOLDOVER)
        ->and($event->effective_date->toDateString())->toBe('2028-07-01')
        ->and($event->document_reference)->toBe('Letter 04/08/2028')
        ->and($event->payload['holdover_rate_pct'])->toEqual(150)
        ->and($event->payload['amount_from'])->toEqual(100000.0)
        ->and($event->payload['amount_to'])->toEqual(150000.0)
        ->and($event->payload['expired_on'])->toBe('2028-06-30');
});

it('refuses to convert a lease that has not expired, and refuses to convert twice', function () {
    CarbonImmutable::setTestNow('2028-08-15');

    $current = expiredLease(100000, '2030-06-30');
    expect(fn () => app(ConvertLeaseToHoldoverService::class)->convert($current, ['reason' => 'Too early.']))
        ->toThrow(InvalidArgumentException::class);

    $expired = expiredLease(100000, '2028-06-30');
    app(ConvertLeaseToHoldoverService::class)->convert($expired, ['reason' => 'Occupation continues.']);

    expect(fn () => app(ConvertLeaseToHoldoverService::class)->convert($expired->fresh(), ['reason' => 'Again.']))
        ->toThrow(InvalidArgumentException::class);

    // The control: exactly one conversion happened, and it billed.
    expect($expired->fresh()->events()->where('type', LeaseEvent::TYPE_HOLDOVER)->count())->toBe(1)
        ->and(rentInvoicedFor($expired, '2028-07-15'))->toBe(150000.0);
});

it('refuses a holdover starting in a month the lease term already covered', function () {
    CarbonImmutable::setTestNow('2028-08-15');
    $lease = expiredLease(100000, '2028-06-30');

    expect(fn () => app(ConvertLeaseToHoldoverService::class)->convert($lease, [
        'effective_from' => '2028-06-01', 'reason' => 'Would double-bill June.',
    ]))->toThrow(InvalidArgumentException::class);
});
