<?php

use App\Models\Charge;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseOption;
use App\Models\Unit;
use App\Services\ExerciseLeaseOptionService;
use App\Support\Vat;
use Carbon\CarbonImmutable;

/**
 * Exercising an option writes the deal (phase 3 close-out, stories OP-04 and OP-03).
 *
 * The option already knew the contracted rent — `LeaseOption::projectedRent()` computes it from the
 * basis — and exercising stamped a status and stopped. The renewal form then asked the operator to
 * type a rent from scratch, so the system held the number the contract specifies and threw it away
 * at the moment it mattered. A five-year renewal at a contracted +10% typed as the old rent is a
 * mis-priced tenancy that surfaces at the next reconciliation, if at all.
 *
 * OP-03 is the same shape: `LeaseOption::encumbersUnit()` existed since options shipped and
 * **nothing in the codebase called it**, so a unit under someone else's expansion right looked as
 * free as any other in the lease-creation picker.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function exercisableOptionLease(float $rent = 100000, string $expiry = '2030-06-30'): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active',
        'commencement_date' => '2025-07-01',
        'expiry_date' => $expiry,
        'base_rent_monthly' => $rent,
        'term_months' => 60,
        'has_marketing_levy' => false,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_SEED, 'amount' => $rent, 'currency' => 'EGP',
        'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT,
        'start_date' => '2025-07-01', 'is_active' => true,
    ]);

    return $lease->fresh();
}

it('hands the renewal the contracted term and rent instead of making someone retype them', function () {
    // S7's deal: one 5-year renewal at last rent + 10%.
    CarbonImmutable::setTestNow('2030-05-01');
    $lease = exercisableOptionLease(100000, '2030-06-30');

    $option = $lease->options()->create([
        'type' => 'renewal',
        'status' => 'open',
        'earliest_notice_date' => '2029-07-01',
        'latest_notice_date' => '2029-10-01',
        'term_months' => 60,
        'rent_basis' => 'uplift_percent',
        'uplift_percent' => 10,
    ]);

    app(ExerciseLeaseOptionService::class)->exercise($option, ['notice_given_at' => '2029-09-15']);

    $terms = app(ExerciseLeaseOptionService::class)->pendingRenewalTerms($lease->fresh());

    expect($terms['term_months'])->toBe(60)
        ->and($terms['rent'])->toBe(110000.0)
        // The renewal starts the day after the current term ends, not the day notice was served.
        ->and($terms['commencement']->toDateString())->toBe('2030-07-01');
});

it('records the exercise on the lease history as what it DID, not as a mechanism', function () {
    CarbonImmutable::setTestNow('2030-05-01');
    $lease = exercisableOptionLease(100000);

    $option = $lease->options()->create([
        'type' => 'renewal', 'status' => 'open', 'term_months' => 60,
        'rent_basis' => 'uplift_percent', 'uplift_percent' => 10,
    ]);

    app(ExerciseLeaseOptionService::class)->exercise($option, [
        'notice_given_at' => '2029-09-15',
        'document_reference' => 'Notice 15/09/2029',
    ]);

    $event = $lease->fresh()->events()->sole();

    // A renewal option EXTENDS the lease — the timeline reads in deal terms, not in option terms.
    expect($event->type)->toBe(LeaseEvent::TYPE_EXTENSION)
        ->and($event->document_reference)->toBe('Notice 15/09/2029')
        ->and($event->payload['option_type'])->toBe('renewal')
        ->and($event->payload['notice_given_at'])->toBe('2029-09-15')
        ->and($event->payload['amount_from'])->toEqual(100000.0)
        ->and($event->payload['amount_to'])->toEqual(110000.0);
});

it('types the event by what the option does — an expansion expands, a termination terminates', function () {
    CarbonImmutable::setTestNow('2030-05-01');

    foreach ([
        'expansion' => LeaseEvent::TYPE_EXPANSION,
        'contraction' => LeaseEvent::TYPE_CONTRACTION,
        'termination' => LeaseEvent::TYPE_TERMINATION,
    ] as $optionType => $eventType) {
        $lease = exercisableOptionLease();
        $option = $lease->options()->create(['type' => $optionType, 'status' => 'open', 'rent_basis' => 'fixed']);

        app(ExerciseLeaseOptionService::class)->exercise($option);

        expect($lease->fresh()->events()->sole()->type)->toBe($eventType);
    }
});

it('exercises a market-review option without inventing a rent', function () {
    // A valuation is not a number this system may produce — the same rule the escalation sweep
    // follows for CPI. The option resolves; the rent stays for the parties to agree.
    CarbonImmutable::setTestNow('2030-05-01');
    $lease = exercisableOptionLease(100000);

    $option = $lease->options()->create([
        'type' => 'renewal', 'status' => 'open', 'term_months' => 36, 'rent_basis' => 'market',
    ]);

    app(ExerciseLeaseOptionService::class)->exercise($option);

    $terms = app(ExerciseLeaseOptionService::class)->pendingRenewalTerms($lease->fresh());

    expect($terms['term_months'])->toBe(36)
        ->and($terms['rent'])->toBeNull()
        // …and the history SAYS the rent is still to be agreed rather than omitting it silently.
        ->and($lease->fresh()->events()->sole()->payload['rent_to_be_agreed'])->toBeTrue();
});

it('keeps the notice date the tenant actually served, even when recorded late', function () {
    // Refusing a late-RECORDED notice would push the operator to falsify the date, which is worse
    // than accepting it: the window is judged against when notice was served.
    CarbonImmutable::setTestNow('2030-05-01');
    $lease = exercisableOptionLease();

    $option = $lease->options()->create([
        'type' => 'renewal', 'status' => 'open', 'term_months' => 60,
        'latest_notice_date' => '2029-10-01', 'rent_basis' => 'fixed', 'fixed_rent' => 120000,
    ]);

    app(ExerciseLeaseOptionService::class)->exercise($option, ['notice_given_at' => '2029-09-15']);

    expect($option->fresh()->notice_given_at->toDateString())->toBe('2029-09-15')
        ->and($option->fresh()->resolved_at->toDateString())->toBe('2030-05-01')
        ->and($option->fresh()->status)->toBe('exercised');
});

it('refuses to exercise an option that is already resolved', function () {
    CarbonImmutable::setTestNow('2030-05-01');
    $lease = exercisableOptionLease();
    $option = $lease->options()->create(['type' => 'renewal', 'status' => 'open', 'rent_basis' => 'fixed', 'fixed_rent' => 1]);

    app(ExerciseLeaseOptionService::class)->exercise($option);

    expect(fn () => app(ExerciseLeaseOptionService::class)->exercise($option->fresh()))
        ->toThrow(InvalidArgumentException::class);

    // The control: exactly one event, so the refusal is not just a silent no-op.
    expect($lease->fresh()->events()->count())->toBe(1);
});

it('writes no history when an option is waived, because nothing about the lease changed', function () {
    CarbonImmutable::setTestNow('2030-05-01');
    $lease = exercisableOptionLease();
    $option = $lease->options()->create(['type' => 'renewal', 'status' => 'open', 'rent_basis' => 'fixed', 'fixed_rent' => 1]);

    app(ExerciseLeaseOptionService::class)->resolveWithout($option, 'waived');

    expect($option->fresh()->status)->toBe('waived')
        ->and($lease->fresh()->events()->count())->toBe(0);
});

it('flags a unit another tenant holds an expansion right over', function () {
    // OP-03. `encumbersUnit()` has known this since options shipped; nothing read it.
    CarbonImmutable::setTestNow('2030-05-01');
    $asset = makeAsset();
    $target = makeUnit($asset, ['code' => 'A-15']);
    $neighbour = exercisableOptionLease();

    $neighbour->options()->create([
        'type' => 'expansion', 'status' => 'open', 'unit_id' => $target->id, 'rent_basis' => 'fixed',
    ]);

    expect($target->fresh()->isEncumbered())->toBeTrue()
        // …but not to the lease that holds the option — its own right does not block its own deal.
        ->and($target->fresh()->isEncumbered($neighbour->id))->toBeFalse();
});

it('stops flagging a unit once the option is resolved', function () {
    // Space the mall is free to let must not stay blocked by a right nobody holds any more.
    CarbonImmutable::setTestNow('2030-05-01');
    $asset = makeAsset();
    $target = makeUnit($asset, ['code' => 'A-15']);
    $neighbour = exercisableOptionLease();

    $option = $neighbour->options()->create([
        'type' => 'expansion', 'status' => 'open', 'unit_id' => $target->id, 'rent_basis' => 'fixed',
    ]);

    expect($target->fresh()->isEncumbered())->toBeTrue();

    app(ExerciseLeaseOptionService::class)->resolveWithout($option, 'waived');

    expect($target->fresh()->isEncumbered())->toBeFalse();
});

it('does not treat a renewal option as an encumbrance on anyone else’s space', function () {
    // Only expansion/ROFR/ROFO/purchase tie up a unit. A renewal right over the tenant's OWN
    // premises encumbers nothing that anyone else could be sold.
    CarbonImmutable::setTestNow('2030-05-01');
    $lease = exercisableOptionLease();
    $unit = $lease->unit;

    $lease->options()->create([
        'type' => 'renewal', 'status' => 'open', 'unit_id' => $unit->id, 'rent_basis' => 'fixed',
    ]);

    expect($unit->fresh()->isEncumbered())->toBeFalse();
});
