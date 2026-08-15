<?php

use App\Models\SlaPenalty;
use App\Models\FacilityWorkOrder;
use App\Models\SlaPolicy;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Services\AssessSlaPenaltyService;
use App\Services\FacilityWorkOrderService;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * SLA penalties against an external maintenance company (FR-CM-08).
 *
 * The FRD says only "automatically flag and calculate a penalty when a CM request exceeds
 * its configured SLA duration" — never on what basis. All three readings are supported as
 * contract configuration rather than one being guessed into the code.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->svc = app(AssessSlaPenaltyService::class);
    $this->wos = app(FacilityWorkOrderService::class);
    $this->asset = makeAsset(['code' => 'PEN']);
    $this->vendor = Vendor::create(['name' => 'CoolAir', 'category' => 'hvac', 'status' => 'active']);
    SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 1]);
});

function contract(string $basis, float $rate): VendorContract
{
    return VendorContract::create([
        'vendor_id' => test()->vendor->id,
        'asset_id' => test()->asset->id,
        'name' => 'HVAC SLA',
        'status' => 'active',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'value' => 100000,
        'sla_penalty_basis' => $basis,
        'sla_penalty_rate' => $rate,
    ]);
}

function externalCm(array $attrs = []): FacilityWorkOrder
{
    return FacilityWorkOrder::create(array_merge([
        'asset_id' => test()->asset->id,
        'work_order_type' => 'cm',
        'execution_type' => 'external',
        'vendor_id' => test()->vendor->id,
        'description' => 'Chiller down',
        'title' => 'Fix chiller',
        'category' => 'hvac',
        'priority' => 'urgent',
        'scheduled_for' => '2026-07-01',
    ], $attrs));
}

/** Accept the job, then let it run late by $hours past its 1h SLA. */
function breachBy(FacilityWorkOrder $order, int $hours): FacilityWorkOrder
{
    app(FacilityWorkOrderService::class)->transition($order, 'in_progress');
    test()->travel($hours + 1)->hours();

    return $order->fresh();
}

/* ---- the three bases ---------------------------------------------------- */

it('charges a flat penalty once, however late the job runs', function () {
    contract('flat', 500);
    $order = breachBy(externalCm(), 5);

    $penalty = $this->svc->assess($order);

    expect((float) $penalty->amount)->toBe(500.0);
    expect($penalty->basis)->toBe('flat');
});

it('accrues a per-day penalty as the job stays late', function () {
    // The reading that forced the design: a per-day penalty cannot be computed once, and
    // the alert's once-per-record stamp could never key it.
    contract('per_day', 200);
    $order = breachBy(externalCm(), 2); // ~3h late → part of a day → 1 day

    expect((float) $this->svc->assess($order)->amount)->toBe(200.0);

    $this->travel(24)->hours(); // now ~27h late → 2 days
    expect((float) $this->svc->assess($order->fresh())->amount)->toBe(400.0);

    $this->travel(24)->hours(); // ~51h late → 3 days
    expect((float) $this->svc->assess($order->fresh())->amount)->toBe(600.0);
});

it('counts part of a day as a whole day', function () {
    // Charging 0.4 of a day's penalty for a nine-hour overrun invites an argument nobody wants.
    contract('per_day', 200);
    $order = breachBy(externalCm(), 1);

    expect((float) $this->svc->assess($order)->amount)->toBe(200.0);
});

it('charges a percentage of the job value', function () {
    contract('percent_of_value', 10);
    $order = breachBy(externalCm(['job_value' => 8000]), 5);

    expect((float) $this->svc->assess($order)->amount)->toBe(800.0);
});

// Sub-hour + day-boundary breaches — the money bug the whole-hour tests above never exercised.
// hoursOverSla() truncated the fractional hour to 0, so a job minutes late assessed NO penalty
// and (being terminal) was never revisited → the vendor escaped it forever.
it('charges a flat penalty on a job completed LESS THAN AN HOUR late (never zero)', function () {
    contract('flat', 500);
    $order = externalCm();
    $this->wos->transition($order, 'in_progress'); // stamps target = accept + 1h SLA
    $this->travel(90)->minutes();                  // 30 min past the 1h SLA
    $this->wos->transition($order->fresh(), 'done'); // completes 30 min late → terminal

    $penalty = $this->svc->assess($order->fresh());

    expect($penalty)->not->toBeNull()             // was null before the fix → penalty escaped forever
        ->and((float) $penalty->amount)->toBe(500.0)
        ->and($penalty->status)->toBe('final');    // frozen on the terminal job
});

it('counts a 48h40m overrun as THREE days, not two (day-boundary ceil)', function () {
    contract('per_day', 200);
    $order = externalCm();
    $this->wos->transition($order, 'in_progress'); // target = accept + 1h
    $this->travel(49)->hours();
    $this->travel(40)->minutes();                  // now accept + 49h40m → 48h40m over the SLA

    // 48h40m spills into the third 24h window → 3 days × 200 (was 2 × 200 with truncation).
    expect((float) $this->svc->assess($order->fresh())->amount)->toBe(600.0);
});

it('assesses nothing on a percent contract when the job has no value captured', function () {
    // Returning 0 would read as "assessed, owes nothing" rather than "we don't know yet".
    contract('percent_of_value', 10);
    $order = breachBy(externalCm(['job_value' => null]), 5);

    expect($this->svc->assess($order))->toBeNull();
    expect(SlaPenalty::count())->toBe(0);
});

/* ---- who is penalised --------------------------------------------------- */

it('does not penalise an in-house job', function () {
    // FR-CM-08 is a contractual remedy against the company that missed its SLA. An
    // internal job running late is a management problem, not a billable one.
    contract('flat', 500);
    $order = breachBy(externalCm(['execution_type' => 'internal', 'vendor_id' => null]), 5);

    expect($this->svc->assess($order))->toBeNull();
});

it('does not penalise when the contract carries no penalty terms', function () {
    // The default: penalties are opt-in per contract, since most won't have one negotiated.
    contract('none', 0);
    $order = breachBy(externalCm(), 5);

    expect($this->svc->assess($order))->toBeNull();
});

it('does not penalise a job that is still within its SLA', function () {
    contract('flat', 500);
    $order = externalCm();
    $this->wos->transition($order, 'in_progress');

    expect($this->svc->assess($order->fresh()))->toBeNull();
});

it('levies under a portfolio-wide contract', function () {
    // asset_id is nullable and the UI offers exactly this, showing such contracts to every
    // property. Demanding an exact asset match let a vendor on a portfolio SLA escape
    // penalties entirely.
    VendorContract::create([
        'vendor_id' => $this->vendor->id, 'asset_id' => null,
        'name' => 'Portfolio SLA', 'status' => 'active',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'value' => 1000,
        'sla_penalty_basis' => 'flat', 'sla_penalty_rate' => 750,
    ]);

    $order = breachBy(externalCm(), 5);

    expect((float) $this->svc->assess($order)->amount)->toBe(750.0);
});

it('prefers a property-specific contract over a portfolio-wide one', function () {
    // The more specific agreement is the one negotiated for this mall.
    VendorContract::create([
        'vendor_id' => $this->vendor->id, 'asset_id' => null,
        'name' => 'Portfolio SLA', 'status' => 'active',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'value' => 1000,
        'sla_penalty_basis' => 'flat', 'sla_penalty_rate' => 750,
    ]);
    contract('flat', 500); // property-specific

    $order = breachBy(externalCm(), 5);

    expect((float) $this->svc->assess($order)->amount)->toBe(500.0);
});

it('levies nothing under a draft or terminated contract', function () {
    // One was never in force; the other no longer is.
    $draft = contract('flat', 999);
    $draft->update(['status' => 'draft']);

    expect($this->svc->assess(breachBy(externalCm(), 5)))->toBeNull();

    $draft->update(['status' => 'terminated']);
    FacilityWorkOrder::query()->delete();

    expect($this->svc->assess(breachBy(externalCm(), 5)))->toBeNull();
});

it('still levies under a contract that has since expired', function () {
    // vendors:expire-contracts flips the status once end_date passes. Excluding `expired`
    // would retroactively erase the penalty on a job that ran while the contract was live —
    // the date window is what judges history.
    $c = contract('flat', 500);
    $order = breachBy(externalCm(['scheduled_for' => '2026-07-01']), 5);
    $c->update(['status' => 'expired']);

    expect((float) $this->svc->assess($order)->amount)->toBe(500.0);
});

it('judges the job by the contract in force when it was scheduled', function () {
    // A vendor may hold several contracts over time; a penalty must not be re-judged by
    // terms signed later.
    contract('flat', 500); // 2026-01-01 .. 2026-12-31
    VendorContract::create([
        'vendor_id' => $this->vendor->id, 'asset_id' => $this->asset->id,
        'name' => 'Renegotiated', 'status' => 'active',
        'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
        'value' => 100000, 'sla_penalty_basis' => 'flat', 'sla_penalty_rate' => 9999,
    ]);

    $order = breachBy(externalCm(['scheduled_for' => '2026-07-01']), 5);

    expect((float) $this->svc->assess($order)->amount)->toBe(500.0);
});

/* ---- accrual stops, and re-running is free ------------------------------ */

it('keeps exactly one penalty per job however often the scan runs', function () {
    // The unique index + update-in-place is what makes an hourly re-assessment safe.
    contract('per_day', 200);
    $order = breachBy(externalCm(), 2);

    $this->svc->assess($order);
    $this->svc->assess($order->fresh());
    $this->svc->assess($order->fresh());

    expect(SlaPenalty::where('facility_work_order_id', $order->id)->count())->toBe(1);
});

it('freezes the amount when the job closes', function () {
    contract('per_day', 200);
    $order = breachBy(externalCm(), 2);
    $this->svc->assess($order);

    $this->wos->transition($order->fresh(), 'done');
    $frozen = SlaPenalty::first();

    expect($frozen->status)->toBe(SlaPenalty::STATUS_FINAL);
    expect($frozen->finalised_at)->not->toBeNull();

    // A closed job's overrun must not keep growing in the archive.
    $amount = (float) $frozen->amount;
    $this->travel(10)->days();
    $this->svc->assess($order->fresh());

    expect((float) SlaPenalty::first()->amount)->toBe($amount);
});

it('assesses a penalty on closure even if the scan never saw the job', function () {
    // The scan only looks at OPEN orders — a job that breached and closed between two runs
    // would otherwise escape entirely.
    contract('flat', 500);
    $order = breachBy(externalCm(), 5);

    $this->wos->transition($order, 'done');

    expect(SlaPenalty::count())->toBe(1);
    expect((float) SlaPenalty::first()->amount)->toBe(500.0);
    expect(SlaPenalty::first()->status)->toBe(SlaPenalty::STATUS_FINAL);
});

/* ---- waiving ------------------------------------------------------------ */

it('waives a penalty with a reason, and the scan never revives it', function () {
    contract('per_day', 200);
    $order = breachBy(externalCm(), 2);
    $penalty = $this->svc->assess($order);
    $actor = makeUser('manager', [$this->asset->id]);

    $this->svc->waive($penalty, 'Part was on back-order; delay was the mall\'s fault.', $actor->id);

    expect($penalty->fresh()->status)->toBe(SlaPenalty::STATUS_WAIVED);
    expect($penalty->fresh()->waived_by_user_id)->toBe($actor->id);

    // The scan runs hourly — it must not silently undo the decision on the next tick.
    $this->travel(48)->hours();
    $this->svc->assess($order->fresh());

    expect(SlaPenalty::first()->status)->toBe(SlaPenalty::STATUS_WAIVED);
    expect((float) SlaPenalty::first()->amount)->toBe(200.0);
});

it('refuses to waive a penalty twice', function () {
    contract('flat', 500);
    $order = breachBy(externalCm(), 5);
    $penalty = $this->svc->assess($order);
    $this->svc->waive($penalty, 'first');

    expect(fn () => $this->svc->waive($penalty->fresh(), 'second'))->toThrow(DomainException::class);
});

it('does not freeze a waived penalty back to final when the job closes', function () {
    contract('flat', 500);
    $order = breachBy(externalCm(), 5);
    $this->svc->waive($this->svc->assess($order), 'waived');

    $this->wos->transition($order->fresh(), 'done');

    expect(SlaPenalty::first()->status)->toBe(SlaPenalty::STATUS_WAIVED);
});

/* ---- the terms are frozen onto the row ---------------------------------- */

it('keeps the terms as applied even if the contract is renegotiated later', function () {
    // Re-deriving from the contract at read time would silently restate history.
    contract('flat', 500);
    $order = breachBy(externalCm(), 5);
    $penalty = $this->svc->assess($order);
    $this->wos->transition($order->fresh(), 'done');

    VendorContract::first()->update(['sla_penalty_rate' => 9999]);

    expect((float) $penalty->fresh()->rate)->toBe(500.0);
    expect((float) $penalty->fresh()->amount)->toBe(500.0);
});
