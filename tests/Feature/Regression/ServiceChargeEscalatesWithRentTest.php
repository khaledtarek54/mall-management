<?php

use App\Models\Charge;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Services\ChargeScheduleService;
use App\Services\RentEscalationService;
use App\Support\LeaseEventNarrative;
use Carbon\CarbonImmutable;

/**
 * An escalation clause can cover the SERVICE CHARGE as well as the rent (2026-09-05).
 *
 * Egyptian mall leases routinely state one escalation for both — "the rent and service charge
 * shall increase by 7% annually" — and Yardi models this as per-charge escalation. Until now the
 * sweep and the ladder projection moved `base_rent_monthly` alone, so the service charge held its
 * signing figure for the life of the lease unless an operator remembered to raise it by hand:
 * the exact revenue leak the sweep exists to close, one column over.
 *
 * `leases.escalation_applies_to_service_charge` is the clause as a row (default false, so nothing
 * an install bills moved on deploy), `Lease::escalatesServiceCharge()` is the ONE predicate both
 * writers read, and the step is the SAME collared percentage on the SAME anniversary — there is
 * deliberately no second rate. Percent-derived clause types only: a step stated in pounds is a
 * statement about the rent, the same reasoning that keeps the collar off `fixed_amount`.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function serviceEscalationLease(array $attributes = []): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, array_merge([
        'status' => 'active',
        'commencement_date' => '2025-01-01',
        'expiry_date' => '2030-12-31',
        'base_rent_monthly' => 100000,
        'service_charge_monthly' => 20000,
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 7,
        'escalation_applies_to_service_charge' => true,
        'next_escalation_date' => '2026-01-01',
    ], $attributes));

    // The rows the lease is actually billing under. Without them an escalation opens each type's
    // FIRST schedule row, which `ChargeScheduleService` dates to the commencement rather than the
    // anniversary — correct behaviour that would make the dating assertions below test the
    // fixture instead of the feature.
    foreach (['base_rent' => ['Base Rent', $lease->base_rent_monthly], 'service_charge' => ['Service Charge', $lease->service_charge_monthly]] as $type => [$name, $amount]) {
        if ((float) $amount <= 0) {
            continue;
        }

        Charge::create([
            'lease_id' => $lease->id,
            'name' => $name,
            'type' => $type,
            'amount' => $amount,
            'currency' => 'EGP',
            'frequency' => 'monthly',
            'start_date' => $lease->commencement_date,
            'is_active' => true,
        ]);
    }

    return $lease->fresh();
}

it('steps the service charge by the same percentage on the same anniversary when the clause covers it', function () {
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = serviceEscalationLease();

    app(RentEscalationService::class)->runForToday();

    $lease->refresh();
    expect((float) $lease->base_rent_monthly)->toBe(107000.0)
        ->and((float) $lease->service_charge_monthly)->toBe(21400.0);

    // The schedule is the proof, not the columns: a new rung dated to the anniversary, the old
    // rung closed the day before — the same close-and-open discipline the rent has always had.
    $rung = $lease->charges()->where('type', 'service_charge')->orderByDesc('start_date')->first();
    $closed = $lease->charges()->where('type', 'service_charge')->orderBy('start_date')->first();

    expect((float) $rung->amount)->toBe(21400.0)
        ->and($rung->start_date->toDateString())->toBe('2026-01-01')
        ->and($closed->end_date->toDateString())->toBe('2025-12-31');
});

it('leaves the service charge alone when the clause does not cover it', function () {
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = serviceEscalationLease(['escalation_applies_to_service_charge' => false]);

    app(RentEscalationService::class)->runForToday();

    $lease->refresh();
    expect((float) $lease->base_rent_monthly)->toBe(107000.0)
        ->and((float) $lease->service_charge_monthly)->toBe(20000.0)
        ->and($lease->charges()->where('type', 'service_charge')->count())->toBe(1);
});

it('applies the COLLARED rate to the service charge, not the stated one', function () {
    // A floor of 5% over a stated 2% — the collar decides what the tenant pays, and both charges
    // must step by what the collar decided or one lease's clause produces two different answers.
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = serviceEscalationLease(['escalation_rate' => 2, 'escalation_floor_rate' => 5]);

    app(RentEscalationService::class)->runForToday();

    $lease->refresh();
    expect((float) $lease->base_rent_monthly)->toBe(105000.0)
        ->and((float) $lease->service_charge_monthly)->toBe(21000.0);
});

it('never steps the service charge on an amount clause, even with the flag set', function () {
    // "+EGP 5,000 a month" is a statement about the RENT — carrying the same figure onto a
    // service charge a fraction of its size charges nobody what they agreed. The flag survives
    // the type switch (like the collar) and is inert until a percent type can read it again.
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = serviceEscalationLease([
        'escalation_type' => 'fixed_amount',
        'escalation_rate' => 0,
        'escalation_amount' => 5000,
    ]);

    expect($lease->escalation_applies_to_service_charge)->toBeTrue();

    app(RentEscalationService::class)->runForToday();

    $lease->refresh();
    expect((float) $lease->base_rent_monthly)->toBe(105000.0)
        ->and((float) $lease->service_charge_monthly)->toBe(20000.0)
        ->and($lease->charges()->where('type', 'service_charge')->count())->toBe(1);
});

it('projects the service-charge ladder up front beside the rent ladder', function () {
    $lease = serviceEscalationLease(['expiry_date' => '2027-12-31']);

    app(ChargeScheduleService::class)->projectTermEscalations($lease);

    // Two anniversaries inside the term (2026 + 2027), compounded and rounded per rung exactly as
    // the rent ladder is — 20,000 → 21,400 → 22,898.
    $amounts = $lease->charges()->where('type', 'service_charge')
        ->orderBy('start_date')->pluck('amount')->map(fn ($a) => (float) $a)->all();

    expect($amounts)->toBe([20000.0, 21400.0, 22898.0]);

    $steps = $lease->charges()->where('type', 'service_charge')
        ->whereNotNull('start_date')->orderBy('start_date')->pluck('start_date')
        ->map(fn ($d) => $d->toDateString())->all();

    expect(array_slice($steps, 1))->toBe(['2026-01-01', '2027-01-01']);
});

it('does not project a service-charge ladder the clause does not state', function () {
    $lease = serviceEscalationLease(['expiry_date' => '2027-12-31', 'escalation_applies_to_service_charge' => false]);

    app(ChargeScheduleService::class)->projectTermEscalations($lease);

    expect($lease->charges()->where('type', 'service_charge')->count())->toBe(1)
        ->and($lease->charges()->where('type', 'base_rent')->count())->toBe(3);
});

it('converges: the sweep finds a projected service-charge step already in force and adds no row', function () {
    // The LS-01 invariant, extended to the second charge: a projected lease and a swept one end on
    // identical rows, so running both never doubles a step.
    $lease = serviceEscalationLease(['expiry_date' => '2027-12-31']);
    app(ChargeScheduleService::class)->projectTermEscalations($lease);

    CarbonImmutable::setTestNow('2026-01-02');
    app(RentEscalationService::class)->runForToday();

    $lease->refresh();
    expect((float) $lease->service_charge_monthly)->toBe(21400.0)
        ->and($lease->charges()->where('type', 'service_charge')->count())->toBe(3)
        ->and($lease->charges()->where('type', 'base_rent')->count())->toBe(3);
});

it('records one event naming both figures, readable in both languages', function () {
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = serviceEscalationLease();

    app(RentEscalationService::class)->runForToday();

    $event = LeaseEvent::query()->where('lease_id', $lease->id)
        ->where('type', LeaseEvent::TYPE_RENT_MODIFICATION)->latest('id')->first();

    expect($event)->not->toBeNull()
        ->and($event->payload[LeaseEventNarrative::KEY])->toBe('rent_escalated_with_service')
        ->and((float) $event->payload['service_amount_from'])->toBe(20000.0)
        ->and((float) $event->payload['service_amount_to'])->toBe(21400.0);

    $en = LeaseEventNarrative::resolve($event, 'en');
    $ar = LeaseEventNarrative::resolve($event, 'ar');

    expect($en)->toContain('107,000.00')->toContain('21,400.00')->not->toContain(':service')
        ->and($ar)->toContain('21,400.00')->not->toContain(':service')
        // A key missing from the Arabic catalogue falls back to English silently — real Arabic
        // script in the resolved sentence is the proof the translation exists.
        ->and(preg_match('/\p{Arabic}/u', $ar))->toBe(1);
});

it('never resurrects a service charge the operator ended', function () {
    // The schedule tab can end a service charge (`base_rent` is barred there; `service_charge`
    // is not) and `close()` never touches `leases.service_charge_monthly` — so a column-sized
    // step would find no active row, and `setAmount` would mint an open-ended rung dated to the
    // COMMENCEMENT: a terminated charge brought back at 107% by an unattended nightly job. The
    // sweep sizes from the schedule and skips a charge with no rung live on the anniversary.
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = serviceEscalationLease();

    $lease->charges()->where('type', 'service_charge')
        ->update(['end_date' => '2025-10-31', 'is_active' => false]);

    app(RentEscalationService::class)->runForToday();

    $lease->refresh();
    expect((float) $lease->base_rent_monthly)->toBe(107000.0)
        ->and((float) $lease->service_charge_monthly)->toBe(20000.0)
        ->and($lease->charges()->where('type', 'service_charge')->count())->toBe(1)
        ->and(LeaseEvent::query()->where('lease_id', $lease->id)->latest('id')->first()
            ->payload[LeaseEventNarrative::KEY])->toBe('rent_escalated');
});

it('does not let a stopped-charge residue take the RENT step down with it', function () {
    // A future-dated stop (and any arrears stop, for one cycle) leaves the row ACTIVE with a past
    // end date — `close()`'s own documented residue. Stepping that row closes-and-opens against
    // its inherited past end, an inverted range the model refuses — and the refusal would roll
    // back the base-rent step written in the same transaction, fail into the ops log, and repeat
    // every night with `next_escalation_date` never advancing. No rung covering the anniversary
    // means NO service step, and the rent step proceeds alone.
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = serviceEscalationLease();

    $lease->charges()->where('type', 'service_charge')
        ->update(['end_date' => '2025-11-30']); // still is_active — the residue shape

    $stats = app(RentEscalationService::class)->runForToday();

    $lease->refresh();
    expect($stats['applied'])->toBe(1)
        ->and($stats['failed'])->toBe(0)
        ->and((float) $lease->base_rent_monthly)->toBe(107000.0)
        ->and($lease->fresh()->next_escalation_date->toDateString())->toBe('2027-01-01')
        ->and($lease->charges()->where('type', 'service_charge')->count())->toBe(1);
});

it('skips a service charge bounded to end exactly at the anniversary', function () {
    // The charge's last covered day is the eve of the step — it is over from the day the step
    // would take effect, so there is nothing live to bill the stepped amount, and stepping it
    // would build the same inverted range as the residue above.
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = serviceEscalationLease();

    $lease->charges()->where('type', 'service_charge')
        ->update(['end_date' => '2025-12-31']);

    $stats = app(RentEscalationService::class)->runForToday();

    expect($stats['applied'])->toBe(1)
        ->and($stats['failed'])->toBe(0)
        ->and($lease->charges()->where('type', 'service_charge')->count())->toBe(1)
        ->and((float) $lease->fresh()->service_charge_monthly)->toBe(20000.0);
});

it('steps what the schedule actually bills, not a drifted lease column', function () {
    // The tab can restate a service charge without touching `leases.service_charge_monthly`.
    // Sizing from the column would cut the tenant's real 30,000 back to a stale 20,000 × 1.07 —
    // and quote figures that never billed in the lease event. The schedule is the authority; the
    // column heals to the stepped figure as a side effect of the same `apply()` call.
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = serviceEscalationLease();

    $lease->charges()->where('type', 'service_charge')->update(['amount' => 30000]);

    app(RentEscalationService::class)->runForToday();

    $lease->refresh();
    expect((float) $lease->service_charge_monthly)->toBe(32100.0)
        ->and((float) $lease->charges()->where('type', 'service_charge')
            ->orderByDesc('start_date')->first()->amount)->toBe(32100.0);

    $event = LeaseEvent::query()->where('lease_id', $lease->id)->latest('id')->first();

    expect((float) $event->payload['service_amount_from'])->toBe(30000.0)
        ->and((float) $event->payload['service_amount_to'])->toBe(32100.0);
});

it('projects a service-only lease: the service ladder alone, no zero rent or levy rows', function () {
    $lease = serviceEscalationLease([
        'base_rent_monthly' => 0,
        'expiry_date' => '2027-12-31',
    ]);

    app(ChargeScheduleService::class)->projectTermEscalations($lease);

    $amounts = $lease->charges()->where('type', 'service_charge')
        ->orderBy('start_date')->pluck('amount')->map(fn ($a) => (float) $a)->all();

    expect($amounts)->toBe([20000.0, 21400.0, 22898.0])
        ->and($lease->charges()->where('type', 'base_rent')->count())->toBe(0)
        ->and($lease->charges()->where('type', 'marketing')->count())->toBe(0);
});

it('stops the projected service ladder where the charge stops billing', function () {
    // A service charge bounded to end mid-term: past its end no row covers the step, and writing
    // one anyway would stretch the ended row through `setAmount`'s latest-active fallback and
    // build an inverted range out of its inherited end date — a DomainException inside whatever
    // door called the projection.
    $lease = serviceEscalationLease(['expiry_date' => '2027-12-31']);

    $lease->charges()->where('type', 'service_charge')->update(['end_date' => '2026-06-30']);

    app(ChargeScheduleService::class)->projectTermEscalations($lease);

    $rows = $lease->charges()->where('type', 'service_charge')->orderBy('start_date')->get();

    expect($rows->map(fn (Charge $c) => (float) $c->amount)->all())->toBe([20000.0, 21400.0])
        ->and($rows->last()->end_date->toDateString())->toBe('2026-06-30')
        ->and($lease->charges()->where('type', 'base_rent')->count())->toBe(3);
});

it('projects the ladder when the toggle is flipped on mid-term', function () {
    // The remedy path a flipped flag would otherwise not have: every creation door projects, and
    // the backfill command skips any lease that already carries ORIGIN_ESCALATION rows — exactly
    // the leases an operator will flag after this ships. Note the projection walks the whole
    // contracted ladder, so the rent rungs appear too — idempotent, and the contract's own terms.
    $lease = serviceEscalationLease([
        'expiry_date' => '2027-12-31',
        'escalation_applies_to_service_charge' => false,
    ]);

    $lease->update(['escalation_applies_to_service_charge' => true]);

    $amounts = $lease->charges()->where('type', 'service_charge')
        ->orderBy('start_date')->pluck('amount')->map(fn ($a) => (float) $a)->all();

    expect($amounts)->toBe([20000.0, 21400.0, 22898.0])
        ->and($lease->charges()->where('type', 'base_rent')->count())->toBe(3);
});

it('never escalates a CAM re-estimate — the true-up re-prices it', function () {
    // `ApplyCamEstimateService` stamps its rungs `cam_estimate` so the clause can tell an
    // ESTIMATE from a contractual figure. Escalating an estimate double-adjusts it: the annual
    // reconciliation already replaces it with the year's actual costs.
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = serviceEscalationLease();

    $lease->charges()->where('type', 'service_charge')
        ->update(['origin' => Charge::ORIGIN_CAM_ESTIMATE]);

    app(RentEscalationService::class)->runForToday();

    $lease->refresh();
    expect((float) $lease->base_rent_monthly)->toBe(107000.0)
        ->and((float) $lease->service_charge_monthly)->toBe(20000.0)
        ->and($lease->charges()->where('type', 'service_charge')->count())->toBe(1)
        ->and(LeaseEvent::query()->where('lease_id', $lease->id)->latest('id')->first()
            ->payload[LeaseEventNarrative::KEY])->toBe('rent_escalated');
});

it('never overwrites a CAM re-estimate that takes over ON the anniversary', function () {
    // The estimate is commonly applied effective 1 January — often the anniversary itself. The
    // outgoing rung is contractual, so a base exists; but the INCOMING rung is the estimate, and
    // `setAmount` would amend it in place with the escalated figure, silently replacing the
    // reconciliation's own answer.
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = serviceEscalationLease();

    $lease->charges()->where('type', 'service_charge')->update(['end_date' => '2025-12-31']);

    Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Service Charge',
        'type' => 'service_charge',
        'origin' => Charge::ORIGIN_CAM_ESTIMATE,
        'amount' => 25000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'start_date' => '2026-01-01',
        'is_active' => true,
    ]);

    app(RentEscalationService::class)->runForToday();

    $estimate = $lease->charges()->where('type', 'service_charge')
        ->orderByDesc('start_date')->first();

    expect((float) $estimate->amount)->toBe(25000.0)
        ->and($estimate->origin)->toBe(Charge::ORIGIN_CAM_ESTIMATE)
        ->and(LeaseEvent::query()->where('lease_id', $lease->id)->latest('id')->first()
            ->payload[LeaseEventNarrative::KEY])->toBe('rent_escalated');
});

it('never uses a CAM re-estimate as the BASE of a step onto a stated successor', function () {
    // The other side of the estimate guard: the estimate governs the year ENDING at the
    // anniversary and an operator has already stated next year's figure. There is no contractual
    // pre-step amount to derive from — estimate × 1.07 is not one — and `setAmount` would amend
    // the stated rung in place with it.
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = serviceEscalationLease();

    $lease->charges()->where('type', 'service_charge')
        ->update(['origin' => Charge::ORIGIN_CAM_ESTIMATE, 'end_date' => '2025-12-31']);

    Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Service Charge',
        'type' => 'service_charge',
        'origin' => Charge::ORIGIN_MANUAL,
        'amount' => 24000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'start_date' => '2026-01-01',
        'is_active' => true,
    ]);

    app(RentEscalationService::class)->runForToday();

    $stated = $lease->charges()->where('type', 'service_charge')
        ->orderByDesc('start_date')->first();

    expect((float) $stated->amount)->toBe(24000.0)
        ->and(LeaseEvent::query()->where('lease_id', $lease->id)->latest('id')->first()
            ->payload[LeaseEventNarrative::KEY])->toBe('rent_escalated');
});

it('projects no service ladder off a CAM re-estimate base', function () {
    $lease = serviceEscalationLease(['expiry_date' => '2027-12-31']);

    $lease->charges()->where('type', 'service_charge')
        ->update(['origin' => Charge::ORIGIN_CAM_ESTIMATE]);

    app(ChargeScheduleService::class)->projectTermEscalations($lease);

    expect($lease->charges()->where('type', 'service_charge')->count())->toBe(1)
        ->and($lease->charges()->where('type', 'base_rent')->count())->toBe(3);
});

it('projects no ladder from an estimate base onto a stated successor', function () {
    // The projection twin of the sweep's outgoing-estimate guard: the estimate governs the year
    // ENDING at the first step and the operator has stated the figure after it. A ladder
    // compounded from the estimate would amend the stated rung in place — estimate × 1.07 is
    // nobody's contract.
    $lease = serviceEscalationLease(['expiry_date' => '2027-12-31']);

    $lease->charges()->where('type', 'service_charge')
        ->update(['origin' => Charge::ORIGIN_CAM_ESTIMATE, 'end_date' => '2025-12-31']);

    Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Service Charge',
        'type' => 'service_charge',
        'origin' => Charge::ORIGIN_MANUAL,
        'amount' => 24000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'start_date' => '2026-01-01',
        'is_active' => true,
    ]);

    app(ChargeScheduleService::class)->projectTermEscalations($lease);

    expect((float) $lease->charges()->where('type', 'service_charge')
        ->orderByDesc('start_date')->first()->amount)->toBe(24000.0);
});

it('drops the flag with the rest of the clause when a lease is switched to none', function () {
    // A field the operator cannot see must not hold a value that can take effect — the same
    // clearing the rate, amount and collar already get.
    $lease = serviceEscalationLease();

    $lease->update(['escalation_type' => 'none']);

    expect($lease->fresh()->escalation_applies_to_service_charge)->toBeFalse();
});

it('mints nothing for a lease whose clause covers a service charge it does not have', function () {
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = serviceEscalationLease(['service_charge_monthly' => 0]);

    app(RentEscalationService::class)->runForToday();

    $lease->refresh();
    expect((float) $lease->base_rent_monthly)->toBe(107000.0)
        ->and((float) $lease->service_charge_monthly)->toBe(0.0)
        ->and($lease->charges()->where('type', 'service_charge')->count())->toBe(0)
        // A rent-only step reads as a rent-only step — the `_with_service` sentence would name a
        // charge that did not move.
        ->and(LeaseEvent::query()->where('lease_id', $lease->id)->latest('id')->first()
            ->payload[LeaseEventNarrative::KEY])->toBe('rent_escalated');
});
