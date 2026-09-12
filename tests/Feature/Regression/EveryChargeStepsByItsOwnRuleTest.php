<?php

use App\Filament\Admin\RelationManagers\ChargeScheduleRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Charge;
use App\Models\ChargeCode;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Services\ChargeScheduleService;
use App\Services\LeaseCreationService;
use App\Services\RentEscalationService;
use App\Settings\BillingSettings;
use App\Support\ChargeEscalation;
use App\Support\LeaseEventNarrative;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Every charge on a lease steps on the anniversary by ITS OWN rule — Yardi's per-charge grain
 * (meeting 2026-09-02, point 24: *"the annual increase should be on all expenses not only on
 * rent — better to be an option to be a percentage or a fixed number"*).
 *
 * Until 2026-09-12 the escalation clause was lease-level and stepped the base rent; a toggle let
 * the service charge follow it by the same percentage; parking, signage, storage and every other
 * charge row never escalated at all, so a bay contracted at +500 a year held its signing figure
 * until somebody remembered. Voyager holds the escalation schedule per charge code and generates
 * the future rows from it (benchmark 01 §4); MRI's recurring charge carries its own step. Now the
 * rule is a TERM OF THE CHARGE ROW (`charges.escalation_mode` + its own rate or amount, carried
 * onto every successor rung with `billing_timing` and `prorate`), `ChargeEscalation` is the one
 * reading the sweep, the projection, the schedule tab and the lease form take, and the lease
 * form's "Which charges step" table is where an operator rules on each.
 *
 * Deliberately NOT copied from Voyager: a per-row frequency and effective date. Every clause
 * these malls sign steps on the contract's anniversary, so the lease's interval is the one
 * calendar (skill §3b). And a follows-lease row under an AMOUNT clause steps nothing — the
 * 2026-09-05 rule: a step stated in pounds is a statement about the rent.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * A lease at 100,000 rent / 20,000 service charge with three extra charges on its schedule —
 * an admin fee 3,000, a utility recharge 1,500, an "other" 800 (a chiller charge, say) — each
 * carrying the rule the case names. Codes the unseeded catalogue's floor knows. NOT a parking
 * bay: a bay is priced in the rentable-items register and re-derived onto the parking row on
 * every assignment, so it is a derived type here (found by review — the first cut's flagship case
 * was a bay, and the next bay assigned would have undone its rule).
 *
 * @param  array<string, array{0: ?string, 1: ?float, 2: ?float}>  $rules  type => [mode, rate, amount]
 */
function ruledLease(array $lease = [], array $rules = []): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, array_merge([
        'status' => 'active',
        'commencement_date' => '2025-01-01',
        'expiry_date' => '2027-12-31',
        'base_rent_monthly' => 100000,
        'service_charge_monthly' => 20000,
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 7,
        'next_escalation_date' => '2026-01-01',
    ], $lease));

    foreach ([
        'base_rent' => ['Base Rent', $lease->base_rent_monthly],
        'service_charge' => ['Service Charge', $lease->service_charge_monthly],
        'cam_admin_fee' => ['Admin fee', 3000],
        'utility' => ['Utility recharge', 1500],
        'other' => ['Chiller charge', 800],
    ] as $type => [$name, $amount]) {
        [$mode, $rate, $stepAmount] = $rules[$type] ?? [null, null, null];

        Charge::create([
            'lease_id' => $lease->id,
            'name' => $name,
            'type' => $type,
            'origin' => Charge::ORIGIN_SEED,
            'amount' => $amount,
            'currency' => 'EGP',
            'frequency' => 'monthly',
            'escalation_mode' => $mode,
            'escalation_rate' => $rate,
            'escalation_amount' => $stepAmount,
            'start_date' => $lease->commencement_date,
            'is_active' => true,
        ]);
    }

    return $lease->fresh();
}

/** A charge type's active ladder as `amount@Y-m`. */
function ladderOf(Lease $lease, string $type): string
{
    return $lease->charges()->where('type', $type)->where('is_active', true)->orderBy('start_date')->get()
        ->map(fn (Charge $c) => number_format((float) $c->amount, 2, '.', '').'@'.$c->start_date->format('Y-m'))
        ->implode(' ');
}

function rungInForce(Lease $lease, string $type): Charge
{
    return $lease->charges()->where('type', $type)->where('is_active', true)->orderByDesc('start_date')->first();
}

it('steps a charge by its own fixed amount on the rent anniversary, under its own event', function () {
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = ruledLease(rules: ['cam_admin_fee' => [ChargeEscalation::FIXED_AMOUNT, null, 500]]);

    app(RentEscalationService::class)->runForToday();

    // The rent by the clause, the fee by its own amount; the utility, ruled on by nobody,
    // stands still — the shape every charge but the rent had before the column existed.
    expect((float) $lease->fresh()->base_rent_monthly)->toBe(107000.0)
        ->and(ladderOf($lease, 'cam_admin_fee'))->toBe('3000.00@2025-01 3500.00@2026-01')
        ->and(rungInForce($lease, 'cam_admin_fee')->origin)->toBe(Charge::ORIGIN_ESCALATION)
        ->and(ladderOf($lease, 'utility'))->toBe('1500.00@2025-01');

    // Its own timeline row, naming the charge through the catalogue in the READER's language —
    // the rent's `_with_service` sentence states one percentage for both and cannot carry a bay
    // stepping by pounds.
    $event = LeaseEvent::query()->where('lease_id', $lease->id)
        ->get()->first(fn (LeaseEvent $e) => ($e->payload[LeaseEventNarrative::KEY] ?? null) === 'charge_escalated_amount');

    expect($event)->not->toBeNull()
        ->and($event->payload['charge_type'])->toBe('cam_admin_fee')
        ->and(LeaseEventNarrative::resolve($event, 'en'))->toContain(ChargeCode::labelFor('cam_admin_fee', 'en'))->toContain('500.00')->toContain('3,000.00')->toContain('3,500.00')
        ->and(LeaseEventNarrative::resolve($event, 'ar'))->toMatch('/[\x{0600}-\x{06FF}]/u')->toContain('3,500.00');
});

it('steps a charge by its own percentage, not the rent\'s', function () {
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = ruledLease(rules: ['utility' => [ChargeEscalation::PERCENT, 8, null]]);

    app(RentEscalationService::class)->runForToday();

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(107000.0)
        ->and(ladderOf($lease, 'utility'))->toBe('1500.00@2025-01 1620.00@2026-01');
});

it('gives a following charge the rent\'s COLLARED rate', function () {
    // The clause says 2 % under a 5 % floor; the rent steps 5 % and so must the bay that follows
    // it, or one lease's clause produces two answers.
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = ruledLease(
        ['escalation_rate' => 2, 'escalation_floor_rate' => 5],
        ['cam_admin_fee' => [ChargeEscalation::FOLLOWS_LEASE, null, null]],
    );

    app(RentEscalationService::class)->runForToday();

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(105000.0)
        ->and(ladderOf($lease, 'cam_admin_fee'))->toBe('3000.00@2025-01 3150.00@2026-01');
});

it('sweeps a lease whose rent never steps for the charge that does', function () {
    // A `none` clause carries no anniversary of its own; ruling on the fee arms one — at the
    // first anniversary on or after today, never in the past — and the sweep then finds the
    // lease through the row's rule rather than the clause.
    CarbonImmutable::setTestNow('2025-06-01');
    $lease = ruledLease(['escalation_type' => 'none', 'escalation_rate' => 0, 'next_escalation_date' => null]);
    expect($lease->next_escalation_date)->toBeNull();

    app(ChargeScheduleService::class)->setEscalation($lease, 'cam_admin_fee', ChargeEscalation::FIXED_AMOUNT, amount: 500);

    $lease->refresh();
    expect($lease->next_escalation_date?->toDateString())->toBe('2026-01-01')
        // Ruled mid-term, the fee's ladder is written at once — the schedule shows the term.
        ->and(ladderOf($lease, 'cam_admin_fee'))->toBe('3000.00@2025-01 3500.00@2026-01 4000.00@2027-01')
        ->and(ladderOf($lease, 'base_rent'))->toBe('100000.00@2025-01');

    // The pointer survives an ordinary save of the `none` clause — `Lease::saving` clears it only
    // when nothing on the schedule steps either.
    $lease->update(['notes' => 'touched']);
    expect($lease->fresh()->next_escalation_date?->toDateString())->toBe('2026-01-01');

    CarbonImmutable::setTestNow('2026-01-02');
    $stats = app(RentEscalationService::class)->runForToday();

    // The ladder was written when the bay was ruled on, so the sweep finds 3,500 already in
    // force on the anniversary and converges on it — a projected lease and a swept one agree.
    $lease->refresh();
    expect($stats['applied'])->toBe(1)
        ->and((float) $lease->base_rent_monthly)->toBe(100000.0)
        ->and((float) app(ChargeScheduleService::class)->rowCovering($lease, 'cam_admin_fee', CarbonImmutable::parse('2026-01-01'))->amount)->toBe(3500.0)
        ->and(ladderOf($lease, 'cam_admin_fee'))->toBe('3000.00@2025-01 3500.00@2026-01 4000.00@2027-01')
        ->and($lease->next_escalation_date->toDateString())->toBe('2027-01-01');
});

it('writes each ruled charge\'s ladder at signing, and none for a charge ruled to stand still', function () {
    $lease = ruledLease(rules: [
        'cam_admin_fee' => [ChargeEscalation::FIXED_AMOUNT, null, 500],
        'utility' => [ChargeEscalation::PERCENT, 10, null],
        'other' => [ChargeEscalation::NONE, null, null],
        'service_charge' => [ChargeEscalation::FOLLOWS_LEASE, null, null],
    ]);

    app(ChargeScheduleService::class)->projectTermEscalations($lease);

    expect(ladderOf($lease, 'base_rent'))->toBe('100000.00@2025-01 107000.00@2026-01 114490.00@2027-01')
        ->and(ladderOf($lease, 'service_charge'))->toBe('20000.00@2025-01 21400.00@2026-01 22898.00@2027-01')
        ->and(ladderOf($lease, 'cam_admin_fee'))->toBe('3000.00@2025-01 3500.00@2026-01 4000.00@2027-01')
        ->and(ladderOf($lease, 'utility'))->toBe('1500.00@2025-01 1650.00@2026-01 1815.00@2027-01')
        ->and(ladderOf($lease, 'other'))->toBe('800.00@2025-01');

    // A projected lease and a swept one converge: the sweep finds every rung already in force.
    CarbonImmutable::setTestNow('2026-01-02');
    app(RentEscalationService::class)->runForToday();

    expect(ladderOf($lease, 'cam_admin_fee'))->toBe('3000.00@2025-01 3500.00@2026-01 4000.00@2027-01')
        ->and(ladderOf($lease, 'utility'))->toBe('1500.00@2025-01 1650.00@2026-01 1815.00@2027-01');
});

it('carries the rule onto every successor rung and every copy of the row', function () {
    // The list is `Charge::CARRIED_TERMS`, spread by every writer that opens a row from an
    // existing one — a rent change, a relief window's rows and the rung that resumes after it.
    // A successor that dropped it would read as a bay that stops stepping the year its price
    // was revised.
    $lease = ruledLease(rules: ['cam_admin_fee' => [ChargeEscalation::FIXED_AMOUNT, null, 500]]);
    $schedule = app(ChargeScheduleService::class);

    $successor = $schedule->setAmount($lease, 'cam_admin_fee', 3200, CarbonImmutable::parse('2025-07-01'));

    expect($successor->escalation_mode)->toBe(ChargeEscalation::FIXED_AMOUNT)
        ->and((float) $successor->escalation_amount)->toBe(500.0);

    $window = $schedule->overlayWindow($lease, 'cam_admin_fee', CarbonImmutable::parse('2025-09-01'), CarbonImmutable::parse('2025-10-31'), fn (float $a) => $a / 2, Charge::ORIGIN_RELIEF);

    expect($window['relief'][0]->escalation_mode)->toBe(ChargeEscalation::FIXED_AMOUNT)
        ->and($window['resumed']->escalation_mode)->toBe(ChargeEscalation::FIXED_AMOUNT)
        ->and((float) $window['resumed']->escalation_amount)->toBe(500.0);

    // A caller that STATES a term wins over the inheritance — the importer restating a row.
    $restated = $schedule->setAmount($lease, 'other', 900, CarbonImmutable::parse('2025-07-01'), [
        'escalation_mode' => ChargeEscalation::PERCENT,
        'escalation_rate' => 5,
    ]);

    expect($restated->escalation_mode)->toBe(ChargeEscalation::PERCENT)
        ->and((float) $restated->escalation_rate)->toBe(5.0);
});

it('rules every live rung of the type and re-walks only that ladder', function () {
    CarbonImmutable::setTestNow('2025-06-01');
    $lease = ruledLease(rules: ['service_charge' => [ChargeEscalation::FOLLOWS_LEASE, null, null]]);
    $schedule = app(ChargeScheduleService::class);
    $schedule->projectTermEscalations($lease);

    $rentIds = $lease->charges()->where('type', 'base_rent')->where('is_active', true)->pluck('id')->sort()->values()->all();
    $serviceIds = $lease->charges()->where('type', 'service_charge')->where('is_active', true)->pluck('id')->sort()->values()->all();

    $schedule->setEscalation($lease, 'cam_admin_fee', ChargeEscalation::PERCENT, rate: 10);

    // Every active rung of the type carries the rule; the rent and service ladders keep their ids.
    expect($lease->charges()->where('type', 'cam_admin_fee')->where('is_active', true)->pluck('escalation_mode')->unique()->all())->toBe([ChargeEscalation::PERCENT])
        ->and(ladderOf($lease, 'cam_admin_fee'))->toBe('3000.00@2025-01 3300.00@2026-01 3630.00@2027-01')
        ->and($lease->charges()->where('type', 'base_rent')->where('is_active', true)->pluck('id')->sort()->values()->all())->toBe($rentIds)
        ->and($lease->charges()->where('type', 'service_charge')->where('is_active', true)->pluck('id')->sort()->values()->all())->toBe($serviceIds);

    // Ruled to stand still: the projected rungs go, the base row is re-opened, nothing else moves.
    $schedule->setEscalation($lease, 'cam_admin_fee', ChargeEscalation::NONE);

    expect(ladderOf($lease, 'cam_admin_fee'))->toBe('3000.00@2025-01')
        ->and(rungInForce($lease, 'cam_admin_fee')->end_date)->toBeNull()
        ->and($lease->charges()->where('type', 'base_rent')->where('is_active', true)->pluck('id')->sort()->values()->all())->toBe($rentIds);
});

it('clears the figure the mode does not read, at the model', function () {
    $lease = ruledLease();

    $row = Charge::create([
        'lease_id' => $lease->id, 'name' => 'Late fee', 'type' => 'late_fee', 'amount' => 1000, 'currency' => 'EGP',
        'frequency' => 'monthly', 'start_date' => '2025-01-01', 'is_active' => true,
        'escalation_mode' => ChargeEscalation::PERCENT, 'escalation_rate' => 8, 'escalation_amount' => 999,
    ]);

    expect((float) $row->escalation_rate)->toBe(8.0)->and($row->escalation_amount)->toBeNull();

    $row->update(['escalation_mode' => ChargeEscalation::NONE]);
    expect($row->fresh()->escalation_rate)->toBeNull();

    // An unknown mode is refused by the value-set guard, so the column cannot acquire a fifth reading.
    expect(fn () => $row->update(['escalation_mode' => 'sometimes']))->toThrow(DomainException::class);
});

it('proposes the property\'s rule to every door a charge is born through', function () {
    // `billing.new_charges_follow_escalation` is the market's charge-template default per
    // property: off is Yardi's answer and the shipped one; on, every new charge is PROPOSED as
    // following the clause. The wizard, the seeder and the importer all read it, and a file's
    // blank cell lands under the mall's own convention rather than a silent `none`.
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $tenant = makeTenant();

    expect(ChargeEscalation::defaultModeFor($asset->id))->toBe(ChargeEscalation::NONE);

    $lease = makeLease($unit, $tenant, ['status' => 'active', 'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31', 'base_rent_monthly' => 50000, 'service_charge_monthly' => 5000]);
    LeaseCreationService::seedStandardCharges($lease, 50000, 5000);
    expect(ChargeEscalation::modeOf($lease->charges()->where('type', 'service_charge')->first()))->toBe(ChargeEscalation::NONE);

    $settings = app(BillingSettings::class);
    $settings->new_charges_follow_escalation = true;
    $settings->save();

    expect(ChargeEscalation::defaultModeFor($asset->id))->toBe(ChargeEscalation::FOLLOWS_LEASE);

    $second = makeLease(makeUnit($asset), makeTenant(), ['status' => 'active', 'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31', 'base_rent_monthly' => 50000, 'service_charge_monthly' => 5000]);
    LeaseCreationService::seedStandardCharges($second, 50000, 5000);
    expect(ChargeEscalation::modeOf($second->charges()->where('type', 'service_charge')->first()))->toBe(ChargeEscalation::FOLLOWS_LEASE);

    // The form's answer wins over the proposal — the operator ruled.
    $third = makeLease(makeUnit($asset), makeTenant(), ['status' => 'active', 'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31', 'base_rent_monthly' => 50000, 'service_charge_monthly' => 5000]);
    LeaseCreationService::seedStandardCharges($third, 50000, 5000, serviceEscalation: ['escalation_mode' => ChargeEscalation::PERCENT, 'escalation_rate' => 4]);
    $row = $third->charges()->where('type', 'service_charge')->first();
    expect(ChargeEscalation::modeOf($row))->toBe(ChargeEscalation::PERCENT)->and((float) $row->escalation_rate)->toBe(4.0);
});

it('inherits nothing from an amount clause, even when handed a percentage', function () {
    // The sweep and the projection both pass NULL as the inherited percentage under an amount
    // clause, so this guard inside `stepFor()` never decides on the real path — which is why it
    // is asked directly here: a mutation removing it leaves every driven case green, and a
    // second guard covering for the first is exactly how the first rots.
    $lease = ruledLease(['escalation_type' => 'fixed_amount', 'escalation_rate' => 0, 'escalation_amount' => 5000]);
    $row = $lease->charges()->where('type', 'cam_admin_fee')->first();
    $row->update(['escalation_mode' => ChargeEscalation::FOLLOWS_LEASE]);

    expect(ChargeEscalation::stepFor($row->fresh(), $lease, 7.0))->toBeNull()
        ->and(ChargeEscalation::clauseIsFollowable($lease))->toBeFalse();

    $lease->update(['escalation_type' => 'fixed_percent', 'escalation_rate' => 7, 'escalation_amount' => null]);
    expect(ChargeEscalation::stepFor($row->fresh(), $lease->fresh(), 7.0))->toBe(['percent' => 7.0]);
});

it('arms the anniversary through the projection, so a none-clause lease ruled at birth is swept', function () {
    // Found by review: `Lease::saving` arms the pointer for the rent's clause and cannot see a
    // charge row at creation, and only `setEscalation()` armed one — so a lease created with a
    // `none` clause and a service charge on its own 4 % projected a ladder and was never swept.
    // The projection is the one seam every creation door reaches, so it arms.
    CarbonImmutable::setTestNow('2025-06-01');
    $lease = ruledLease(
        ['escalation_type' => 'none', 'escalation_rate' => 0, 'next_escalation_date' => null],
        ['service_charge' => [ChargeEscalation::PERCENT, 4, null]],
    );
    expect($lease->next_escalation_date)->toBeNull();

    app(ChargeScheduleService::class)->projectTermEscalations($lease);

    expect($lease->fresh()->next_escalation_date?->toDateString())->toBe('2026-01-01')
        ->and(ladderOf($lease, 'service_charge'))->toBe('20000.00@2025-01 20800.00@2026-01 21632.00@2027-01');

    CarbonImmutable::setTestNow('2026-01-02');
    $stats = app(RentEscalationService::class)->runForToday();

    expect($stats['applied'])->toBe(1)
        ->and((float) $lease->fresh()->service_charge_monthly)->toBe(20800.0)
        ->and($lease->fresh()->next_escalation_date->toDateString())->toBe('2027-01-01');
});

it('restating a ruled charge keeps its rule, and restating it to stand still takes the stale rungs', function () {
    // Found by review: the Add-charge modal and the importer defaulted every row to the
    // property's proposal, so restating a +500 charge at a new price switched its rule off on
    // the successor while the projected rungs went on stepping — and with the property set to
    // follow the clause, a fixed +500 silently became "7 %".
    CarbonImmutable::setTestNow('2025-06-01');
    $lease = ruledLease(rules: ['cam_admin_fee' => [ChargeEscalation::FIXED_AMOUNT, null, 500]]);
    $schedule = app(ChargeScheduleService::class);
    $schedule->projectTermEscalations($lease);
    expect(ladderOf($lease, 'cam_admin_fee'))->toBe('3000.00@2025-01 3500.00@2026-01 4000.00@2027-01');

    // Null terms INHERIT — what the modal proposes for a restated type and what a blank importer
    // cell sends — so the successor keeps +500 and the re-walk re-bases the ladder on 3,200.
    $schedule->setAmount($lease, 'cam_admin_fee', 3200, CarbonImmutable::parse('2025-07-01'), ['escalation_mode' => null]);
    $schedule->retrueProjectedLadder($lease->fresh(), clause: false, chargeTypes: ['cam_admin_fee']);

    expect(rungInForce($lease, 'cam_admin_fee')->escalation_mode)->toBe(ChargeEscalation::FIXED_AMOUNT)
        ->and(ladderOf($lease, 'cam_admin_fee'))->toBe('3000.00@2025-01 3200.00@2025-07 3700.00@2026-01 4200.00@2027-01');

    // Stated to stand still: the successor carries `none`, and the re-walk (which runs whether
    // or not the new row states a rule) prunes the projected rungs and re-opens the survivor.
    $schedule->setAmount($lease, 'cam_admin_fee', 3300, CarbonImmutable::parse('2025-09-01'), ['escalation_mode' => ChargeEscalation::NONE]);
    $schedule->retrueProjectedLadder($lease->fresh(), clause: false, chargeTypes: ['cam_admin_fee']);

    expect(ladderOf($lease, 'cam_admin_fee'))->toBe('3000.00@2025-01 3200.00@2025-07 3300.00@2025-09')
        ->and(rungInForce($lease, 'cam_admin_fee')->end_date)->toBeNull();
});

it('lets a stated term reach the row already in force — the importer restating the seeded charge', function () {
    // Found by review: `setAmount()` returned on the same-money branch before reading its
    // attributes, so an importer row restating the seeded service charge at its own figure "with
    // percent 5" reported success and stored nothing. Pre-existing for billing timing and
    // proration; the escalation columns added the door and the sentence.
    $lease = ruledLease();
    $schedule = app(ChargeScheduleService::class);

    $row = $schedule->setAmount($lease, 'service_charge', 20000, CarbonImmutable::parse('2025-01-01'), [
        'escalation_mode' => ChargeEscalation::PERCENT, 'escalation_rate' => 5, 'billing_timing' => Charge::TIMING_ARREARS,
    ]);

    expect($row->escalation_mode)->toBe(ChargeEscalation::PERCENT)
        ->and((float) $row->escalation_rate)->toBe(5.0)
        ->and($row->billing_timing)->toBe(Charge::TIMING_ARREARS)
        ->and($lease->charges()->where('type', 'service_charge')->count())->toBe(1);
});

it('never steps a relief window, and steps the contract underneath it', function () {
    // Found by review: the sweep sized a charge's step from the row covering the eve with no
    // relief check, so a flat 5,000 concession on a 20,000 service charge came out as 6,000 for
    // the three months of the window, and `service_charge_monthly` was written as the relieved
    // figure. A relief row is never a base and never a target; the contract underneath still
    // steps — on the column, on the timeline, and on the rung that resumes after the window.
    CarbonImmutable::setTestNow('2025-06-01');
    $lease = ruledLease(rules: ['service_charge' => [ChargeEscalation::FIXED_AMOUNT, null, 1000]]);
    $schedule = app(ChargeScheduleService::class);
    $schedule->projectTermEscalations($lease);

    // A flat 5,000 relief from November to March, across the January step.
    $schedule->overlayWindow($lease, 'service_charge', CarbonImmutable::parse('2025-11-01'), CarbonImmutable::parse('2026-03-31'), fn () => 5000, Charge::ORIGIN_RELIEF);

    CarbonImmutable::setTestNow('2026-01-02');
    app(RentEscalationService::class)->runForToday();

    // Every month of the window still bills the concession — asserted on what COVERS each
    // month, whatever origin a row carries, because an amended-in-place relief row flips to
    // `escalation` and would drop out of a query filtered on origin.
    foreach (['2025-11-15', '2025-12-15', '2026-01-15', '2026-02-15', '2026-03-15'] as $day) {
        $covering = $schedule->rowCovering($lease, 'service_charge', CarbonImmutable::parse($day));
        expect((float) $covering->amount)->toBe(5000.0, "window month {$day}")
            ->and($covering->origin)->toBe(Charge::ORIGIN_RELIEF, "window month {$day}");
    }

    expect((float) $lease->fresh()->service_charge_monthly)->toBe(21000.0)
        // The resumption after the window is the stepped contract, and the ladder beyond it.
        ->and((float) $schedule->rowCovering($lease, 'service_charge', CarbonImmutable::parse('2026-04-01'))->amount)->toBe(21000.0)
        ->and((float) $schedule->rowCovering($lease, 'service_charge', CarbonImmutable::parse('2027-02-01'))->amount)->toBe(22000.0);
});

it('re-walks a ladder under a relief window from the contract, not from the concession', function () {
    // Found by review: the projection seeded its carried figure from the row covering the first
    // step's eve, with no relief check — so a rule changed while a window ran compounded the
    // whole ladder from the relieved amount and re-priced the resumption to it.
    CarbonImmutable::setTestNow('2025-06-01');
    $lease = ruledLease(rules: ['service_charge' => [ChargeEscalation::FIXED_AMOUNT, null, 1000]]);
    $schedule = app(ChargeScheduleService::class);
    $schedule->projectTermEscalations($lease);

    // A flat 5,000 relief from November to March over the projected ladder: the January rung is
    // pushed past the window and becomes the resumption.
    $schedule->overlayWindow($lease, 'service_charge', CarbonImmutable::parse('2025-11-01'), CarbonImmutable::parse('2026-03-31'), fn () => 5000, Charge::ORIGIN_RELIEF);

    // Rule re-stated while the window runs (from 1,000 to 1,500) — the walk starts on an eve the
    // relief covers.
    CarbonImmutable::setTestNow('2025-12-01');
    $schedule->setEscalation($lease, 'service_charge', ChargeEscalation::FIXED_AMOUNT, amount: 1500);

    expect((float) $schedule->rowCovering($lease, 'service_charge', CarbonImmutable::parse('2026-01-15'))->amount)->toBe(5000.0)
        ->and((float) $schedule->rowCovering($lease, 'service_charge', CarbonImmutable::parse('2026-04-01'))->amount)->toBe(21500.0)
        ->and((float) $schedule->rowCovering($lease, 'service_charge', CarbonImmutable::parse('2027-02-01'))->amount)->toBe(23000.0);
});

it('never rules a parking bay — the register prices it, and the next assignment would undo the rule', function () {
    // `AssignRentableItemService::rebuildCharge()` re-derives the one parking row from the sum of
    // the bays' own `monthly_rate` on every assignment and release, so a rule on the row is two
    // truths about one charge. Derived, like the rent and the levy: never swept, never projected,
    // never offered on the form's table (found by review).
    $lease = ruledLease();
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Parking', 'type' => 'parking', 'amount' => 3000, 'currency' => 'EGP',
        'frequency' => 'monthly', 'start_date' => '2025-01-01', 'is_active' => true,
        'escalation_mode' => ChargeEscalation::FIXED_AMOUNT, 'escalation_amount' => 500,
    ]);

    expect(ChargeEscalation::DERIVED_TYPES)->toContain('parking')
        ->and(app(ChargeScheduleService::class)->escalatingChargeTypes($lease))->toBe([])
        ->and(collect(EditLease::chargeEscalationRows($lease))->pluck('type')->all())->not->toContain('parking')
        ->and(fn () => app(ChargeScheduleService::class)->setEscalation($lease, 'parking', ChargeEscalation::PERCENT, rate: 5))
        ->toThrow(InvalidArgumentException::class);

    app(ChargeScheduleService::class)->projectTermEscalations($lease);
    expect(ladderOf($lease, 'parking'))->toBe('3000.00@2025-01');
});

it('never steps a one-off, and asks nothing of it', function () {
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = ruledLease(rules: ['cam_admin_fee' => [ChargeEscalation::FIXED_AMOUNT, null, 500]]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Key money', 'type' => 'nsf_fee', 'amount' => 50000, 'currency' => 'EGP',
        'frequency' => 'one_time', 'start_date' => '2025-01-01', 'is_active' => true,
        'escalation_mode' => ChargeEscalation::PERCENT, 'escalation_rate' => 50,
    ]);

    expect(app(ChargeScheduleService::class)->escalatingChargeTypes($lease))->toBe(['cam_admin_fee']);

    app(RentEscalationService::class)->runForToday();

    expect($lease->charges()->where('type', 'nsf_fee')->count())->toBe(1);
});

describe('through the panel', function () {
    beforeEach(function () {
        $this->seed(RolesPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(makeUser('super_admin'));
        $this->asset = makeAsset(['code' => 'PCE']);
        CarbonImmutable::setTestNow('2025-06-01');
    });

    it('rules a charge from the lease form\'s table, through the one writer, and leaves the untouched rows alone', function () {
        asTenant($this->asset, function () {
            $lease = ruledLease(['unit_id' => makeUnit($this->asset)->id], [
                'service_charge' => [ChargeEscalation::FOLLOWS_LEASE, null, null],
            ]);
            app(ChargeScheduleService::class)->projectTermEscalations($lease);
            $serviceIds = $lease->charges()->where('type', 'service_charge')->where('is_active', true)->pluck('id')->sort()->values()->all();

            $page = Livewire::test(EditLease::class, ['record' => $lease->getKey()]);

            // Filled from the schedule: one row per recurring type, rent and levy never asked.
            $rows = collect($page->get('data.charge_escalations'))->values();
            expect($rows->pluck('type')->sort()->values()->all())->toBe(['cam_admin_fee', 'other', 'service_charge', 'utility'])
                ->and($rows->firstWhere('type', 'service_charge')['escalation_mode'])->toBe(ChargeEscalation::FOLLOWS_LEASE)
                ->and($rows->firstWhere('type', 'cam_admin_fee')['escalation_mode'])->toBe(ChargeEscalation::NONE);

            $edited = $rows->map(fn (array $row) => $row['type'] === 'cam_admin_fee'
                ? ['type' => 'cam_admin_fee', 'escalation_mode' => ChargeEscalation::FIXED_AMOUNT, 'escalation_rate' => null, 'escalation_amount' => 500]
                : $row)->values()->all();

            $page->fillForm(['charge_escalations' => $edited])->call('save')->assertHasNoFormErrors();

            expect(ladderOf($lease, 'cam_admin_fee'))->toBe('3000.00@2025-01 3500.00@2026-01 4000.00@2027-01')
                ->and(rungInForce($lease, 'cam_admin_fee')->escalation_mode)->toBe(ChargeEscalation::FIXED_AMOUNT)
                // The service ladder was not re-minted for a change to the admin fee.
                ->and($lease->charges()->where('type', 'service_charge')->where('is_active', true)->pluck('id')->sort()->values()->all())->toBe($serviceIds);

            // A SECOND save with a figure lingering in a hidden box (the rate box keeps its value
            // after a switch to fixed amount) must not re-mint the fee's ladder: the comparison
            // is normalised by mode, as the model stores it (found by review).
            $feeIds = $lease->charges()->where('type', 'cam_admin_fee')->where('is_active', true)->pluck('id')->sort()->values()->all();
            $lingering = collect($edited)->map(fn (array $row) => $row['type'] === 'cam_admin_fee'
                ? ['type' => 'cam_admin_fee', 'escalation_mode' => ChargeEscalation::FIXED_AMOUNT, 'escalation_rate' => 9, 'escalation_amount' => 500]
                : $row)->values()->all();

            Livewire::test(EditLease::class, ['record' => $lease->getKey()])
                ->fillForm(['charge_escalations' => $lingering])->call('save')->assertHasNoFormErrors();

            expect($lease->charges()->where('type', 'cam_admin_fee')->where('is_active', true)->pluck('id')->sort()->values()->all())->toBe($feeIds);
        });
    });

    it('seeds the service charge with the table\'s answer on the create form', function () {
        asTenant($this->asset, function () {
            $unit = makeUnit($this->asset, ['status' => 'vacant']);
            $tenant = makeTenant();

            Livewire::test(CreateLease::class)->fillForm([
                'unit_id' => $unit->id, 'tenant_id' => $tenant->id, 'status' => 'active',
                'commencement_date' => '2025-06-01', 'term_months' => 36, 'expiry_date' => '2028-05-31',
                'base_rent_monthly' => 1000, 'service_charge_monthly' => 250,
                'escalation_type' => 'fixed_percent', 'escalation_rate' => 10, 'security_deposit_months' => 3,
                'charge_escalations' => [['type' => 'service_charge', 'escalation_mode' => ChargeEscalation::PERCENT, 'escalation_rate' => 4, 'escalation_amount' => null]],
            ])->call('create')->assertHasNoFormErrors();

            $lease = Lease::where('tenant_id', $tenant->id)->sole();
            $row = $lease->charges()->where('type', 'service_charge')->orderBy('start_date')->first();

            expect(ChargeEscalation::modeOf($row))->toBe(ChargeEscalation::PERCENT)
                ->and((float) $row->escalation_rate)->toBe(4.0)
                // Its own ladder, at 4 % beside the rent's 10 %.
                ->and(ladderOf($lease, 'service_charge'))->toBe('250.00@2025-06 260.00@2026-06 270.40@2027-06');
        });
    });

    it('asks how a new charge steps where it is born, and projects its ladder at once', function () {
        asTenant($this->asset, function () {
            $lease = ruledLease(['unit_id' => makeUnit($this->asset)->id]);

            Livewire::test(ChargeScheduleRelationManager::class, ['ownerRecord' => $lease, 'pageClass' => EditLease::class])
                ->callTableAction('addCharge', data: [
                    'type' => 'cam_recovery', 'amount' => 1000, 'frequency' => 'monthly', 'effective_from' => '2025-07-01',
                    'escalation_mode' => ChargeEscalation::PERCENT, 'escalation_rate' => 5,
                ])
                ->assertHasNoTableActionErrors();

            expect(ladderOf($lease, 'cam_recovery'))->toBe('1000.00@2025-07 1050.00@2026-01 1102.50@2027-01');

            // Restated through the same modal as standing still: the ladder is re-walked whether
            // or not the new row states a rule, so the stale rungs go (found by review).
            Livewire::test(ChargeScheduleRelationManager::class, ['ownerRecord' => $lease, 'pageClass' => EditLease::class])
                ->callTableAction('addCharge', data: [
                    'type' => 'cam_recovery', 'amount' => 1200, 'frequency' => 'monthly', 'effective_from' => '2025-09-01',
                    'escalation_mode' => ChargeEscalation::NONE,
                ])
                ->assertHasNoTableActionErrors();

            expect(ladderOf($lease, 'cam_recovery'))->toBe('1000.00@2025-07 1200.00@2025-09');
        });
    });

    it('renders the annual-increase tab and its table in both languages with no raw key', function () {
        asTenant($this->asset, function () {
            $lease = ruledLease(['unit_id' => makeUnit($this->asset)->id], [
                'cam_admin_fee' => [ChargeEscalation::FIXED_AMOUNT, null, 500],
            ]);

            $en = Livewire::test(EditLease::class, ['record' => $lease->getKey()]);
            $en->assertSee('Annual increase')->assertSee('Which charges step')->assertSee('Rent & service charge')
                ->assertSee('Security deposit')->assertDontSee('admin.sections')->assertDontSee('admin.charge_escalation')->assertDontSee('admin.fields.escalation');

            app()->setLocale('ar');
            $ar = Livewire::test(EditLease::class, ['record' => $lease->getKey()]);
            $ar->assertSee('الزيادة السنوية')->assertSee('أي الرسوم تزيد')
                ->assertDontSee('admin.sections')->assertDontSee('admin.charge_escalation')->assertDontSee('admin.fields.escalation');

            $create = Livewire::test(CreateLease::class);
            $create->assertSee('أي الرسوم تزيد')->assertDontSee('admin.charge_escalation');
        });
    });

    it('reads the rule in words on the schedule tab, in both languages', function () {
        asTenant($this->asset, function () {
            $lease = ruledLease(['unit_id' => makeUnit($this->asset)->id], [
                'service_charge' => [ChargeEscalation::FOLLOWS_LEASE, null, null],
                'cam_admin_fee' => [ChargeEscalation::FIXED_AMOUNT, null, 500],
                'utility' => [ChargeEscalation::PERCENT, 8, null],
            ]);

            $en = Livewire::test(ChargeScheduleRelationManager::class, ['ownerRecord' => $lease, 'pageClass' => EditLease::class]);
            $en->assertSee('+7% every year')->assertSee('+EGP 500.00 every year')->assertSee('+8% every year')->assertSee('Stands still');

            app()->setLocale('ar');
            $ar = Livewire::test(ChargeScheduleRelationManager::class, ['ownerRecord' => $lease, 'pageClass' => EditLease::class]);
            $ar->assertSee('يبقى كما هو')->assertDontSee('admin.charge_escalation');
        });
    });
});
