<?php

use App\Models\Charge;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\User;
use App\Services\LeaseRentChangeService;
use App\Services\RecordLeaseEventService;
use App\Support\Vat;
use Carbon\CarbonImmutable;

/**
 * Every commercial change is a dated, reasoned, attributed event (phase 2, story LE-01 —
 * docs/benchmarks/yardi/05-user-stories.md).
 *
 * Phase 1 made the rent a schedule, so the system can answer *what* it was and *when* it changed.
 * It could not answer **why**: a negotiated reduction, an expansion and a typo all looked like rows
 * with dates, and the only trace of intent was a sentence appended to `leases.notes`.
 *
 * The two failure modes worth pinning are the ones that would hollow the table out without failing
 * anything: a change that commits without recording an event, and an event that can be edited
 * afterwards. Both leave a timeline that LOOKS like an audit trail and is not one.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function eventedLease(float $rent = 10000): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2028-12-31',
        'base_rent_monthly' => $rent,
        'has_marketing_levy' => false,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_SEED, 'amount' => $rent, 'currency' => 'EGP',
        'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT,
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);

    return $lease->fresh();
}

it('records a rent change as a dated, reasoned event carrying the before and after', function () {
    $lease = eventedLease(10000);

    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_monthly' => 8500,
        'reason' => 'Anchor vacated; temporary market-rate reset agreed.',
        'document_reference' => 'Amendment 3',
        'effective_from' => '2026-07-01',
    ]);

    $event = $lease->fresh()->events()->first();

    expect($event)->not->toBeNull()
        ->and($event->type)->toBe(LeaseEvent::TYPE_RENT_MODIFICATION)
        // The date the change TAKES EFFECT, not the date it was typed.
        ->and($event->effective_date->toDateString())->toBe('2026-07-01')
        ->and($event->reason)->toBe('Anchor vacated; temporary market-rate reset agreed.')
        ->and($event->document_reference)->toBe('Amendment 3')
        ->and($event->payload['amount_from'])->toEqual(10000.0)
        ->and($event->payload['amount_to'])->toEqual(8500.0)
        // The schedule row the change opened — so the history points at the money, not just at
        // the fact that money moved.
        ->and($event->payload['rows_opened'][0]['start_date'])->toBe('2026-07-01')
        ->and($event->payload['rows_opened'][0]['amount'])->toEqual(8500.0);
});

it('attributes the event to the operator who made the change', function () {
    $lease = eventedLease();
    $user = User::factory()->create(['name' => 'Mona Farid']);
    $this->actingAs($user);

    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_monthly' => 11000,
        'reason' => 'Contracted step.',
    ]);

    expect($lease->fresh()->events()->first()->actorName())->toBe('Mona Farid');
});

it('says System, not a person, when a sweep made the change', function () {
    // A sweep under `artisan` has no authenticated user. The honest answer is "System" — naming
    // whoever happened to be logged in would put a false attribution in the audit trail.
    $lease = eventedLease();

    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_monthly' => 11000,
        'reason' => 'Automatic rent escalation +10%',
    ]);

    $event = $lease->fresh()->events()->first();

    expect($event->user_id)->toBeNull()
        ->and($event->actorName())->toBe(__('admin.lease_events.system_actor'));
});

it('refuses to edit an event, because an editable audit record is not an audit record', function () {
    $event = LeaseEvent::factory()->create(['lease_id' => eventedLease()->id]);

    expect(fn () => $event->update(['reason' => 'something more flattering']))
        ->toThrow(DomainException::class);

    expect($event->fresh()->reason)->toBe('Negotiated rent reduction pending anchor re-letting.');
});

it('refuses to delete an event', function () {
    $event = LeaseEvent::factory()->create(['lease_id' => eventedLease()->id]);

    expect(fn () => $event->delete())->toThrow(DomainException::class);
    expect(LeaseEvent::find($event->id))->not->toBeNull();
});

it('refuses an event with no reason — recording that "something changed" is what the activity log already does', function () {
    $lease = eventedLease();

    expect(fn () => app(RecordLeaseEventService::class)->record(
        $lease, LeaseEvent::TYPE_RENT_MODIFICATION, CarbonImmutable::parse('2026-07-01'), '   ',
    ))->toThrow(InvalidArgumentException::class);

    expect($lease->fresh()->events()->count())->toBe(0);
});

it('refuses an event type outside the vocabulary', function () {
    expect(fn () => app(RecordLeaseEventService::class)->record(
        eventedLease(), 'base_rent_monthly_changed', CarbonImmutable::parse('2026-07-01'), 'x',
    ))->toThrow(InvalidArgumentException::class);
});

it('rolls the event back with the change, so history never records something that did not happen', function () {
    // The event is written INSIDE the rent-change transaction. If the change fails after the
    // event is recorded, both must disappear together — a history entry for a rent that never
    // moved is worse than no history at all.
    $lease = eventedLease();

    // A charge row that would make the resulting schedule ambiguous makes the write throw at the
    // model guard, after the lease row and the event have already been touched in the transaction.
    \Illuminate\Support\Facades\DB::table('charges')->insert([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_SEED, 'amount' => 9999, 'currency' => 'EGP',
        'frequency' => 'monthly', 'vat_applicable' => 0, 'vat_rate' => 0,
        'start_date' => '2026-06-01', 'end_date' => null, 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    try {
        app(LeaseRentChangeService::class)->apply($lease, [
            'base_rent_monthly' => 12345,
            'reason' => 'Should not survive.',
            'effective_from' => '2026-07-01',
        ]);
    } catch (\Throwable) {
        // the refusal is the point
    }

    expect($lease->fresh()->events()->count())->toBe(0)
        ->and((float) $lease->fresh()->base_rent_monthly)->toBe(10000.0);

    // The CONTROL, without which this test passes just as happily when nothing records events at
    // all: remove the conflict and the identical call must both change the rent AND record it.
    Charge::where('lease_id', $lease->id)->where('amount', 9999)->delete();

    app(LeaseRentChangeService::class)->apply($lease->fresh(), [
        'base_rent_monthly' => 12345,
        'reason' => 'Should survive.',
        'effective_from' => '2026-07-01',
    ]);

    expect($lease->fresh()->events()->count())->toBe(1)
        ->and((float) $lease->fresh()->base_rent_monthly)->toBe(12345.0);
});

it('reconstructs the lease history as it stood on a past date', function () {
    // The auditor's question: "what did we know about this lease in August?" An event effective
    // in November must not appear in that answer.
    $lease = eventedLease();

    app(RecordLeaseEventService::class)->record(
        $lease, LeaseEvent::TYPE_RENT_MODIFICATION, CarbonImmutable::parse('2026-03-01'), 'March step',
    );
    app(RecordLeaseEventService::class)->record(
        $lease, LeaseEvent::TYPE_ABATEMENT, CarbonImmutable::parse('2026-11-01'), 'November relief',
    );

    $asOfAugust = $lease->fresh()->eventsAsOf(CarbonImmutable::parse('2026-08-31'));

    expect($asOfAugust)->toHaveCount(1)
        ->and($asOfAugust->first()->reason)->toBe('March step')
        // …and the full history, oldest first, once November has arrived.
        ->and($lease->fresh()->eventsAsOf(CarbonImmutable::parse('2026-12-31'))->pluck('reason')->all())
        ->toBe(['March step', 'November relief']);
});
