<?php

/*
|--------------------------------------------------------------------------
| Lease amendment scenarios — one lease, four commercial changes, one history.
|--------------------------------------------------------------------------
| NET-NEW relative to the per-story regression files (LeaseEventsTest,
| LeaseReliefTest, LeaseHoldoverBillingTest, LeaseSpaceChangeTest), each of
| which exercises ONE change in isolation. What only shows up here is their
| INTERACTION over a single lease's life: an expansion, then a relief that has
| to reason about the post-expansion rent, then an expiry and a holdover that
| has to read the rent in force at that moment — and, at the end, a history an
| auditor can replay date by date.
|
| Covers docs/benchmarks/yardi/04-scenarios.md S5 (mid-term expansion),
| S6 (negotiated mid-term relief) and S9 (holdover).
*/

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\User;
use App\Services\ConvertLeaseToHoldoverService;
use App\Services\LeaseReliefService;
use App\Services\LeaseSpaceChangeService;
use App\Services\MonthlyBillingService;
use App\Support\Vat;
use Carbon\CarbonImmutable;

afterEach(fn () => CarbonImmutable::setTestNow());

beforeEach(function () {
    $this->asset = makeAsset(['code' => 'AMND']);
    $this->master = makeUnit($this->asset, ['code' => 'A-14', 'area_sqm' => 900, 'status' => 'vacant']);
    $this->adjacent = makeUnit($this->asset, ['code' => 'A-15', 'area_sqm' => 300, 'status' => 'vacant']);
});

it('carries one lease through expansion, relief and holdover, and can replay it afterwards', function () {
    CarbonImmutable::setTestNow('2028-09-20');

    $operator = User::factory()->create(['name' => 'Nour Adel']);
    $this->actingAs($operator);

    $lease = makeLease($this->master, null, [
        'status' => 'active',
        'commencement_date' => '2028-01-01',
        'expiry_date' => '2029-06-30',
        'base_rent_monthly' => 180000,
        'has_marketing_levy' => false,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_SEED, 'amount' => 180000, 'currency' => 'EGP',
        'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT,
        'start_date' => '2028-01-01', 'is_active' => true,
    ]);

    $billed = function (string $month) use ($lease): float {
        Invoice::where('lease_id', $lease->id)->delete();
        app(MonthlyBillingService::class)
            ->generateForLease($lease->fresh(), CarbonImmutable::parse($month));

        return (float) Invoice::where('lease_id', $lease->id)->with('items')->get()
            ->flatMap(fn (Invoice $i) => $i->items)->where('type', 'base_rent')->sum('amount');
    };

    // ── S5 · expansion into A-15 from 1 Nov 2028, rent to 235,000 ─────────────────────────────
    app(LeaseSpaceChangeService::class)->expand($lease, [
        'unit_ids' => [$this->adjacent->id],
        'effective_from' => '2028-11-01',
        'new_total_rent' => 235000,
        'reason' => 'Expansion into A-15.',
        'document_reference' => 'Amendment 4',
    ]);

    expect($billed('2028-10-15'))->toBe(180000.0)
        ->and($billed('2028-11-15'))->toBe(235000.0)
        // Today is 20 Sep: the expansion takes A-15 in November, so nobody occupies it yet — but
        // it IS spoken for, which is 'reserved', not 'vacant'. Marking it vacant would let another
        // lease take it in October and collide on 1 November.
        ->and($this->adjacent->fresh()->status)->toBe('reserved')
        ->and($this->adjacent->fresh()->isActivelyLeased())->toBeTrue();

    // ── S6 · 50% relief for Q1 2029, on the POST-EXPANSION rent ───────────────────────────────
    // The interaction: the relief must be derived from what the schedule would bill in those
    // months (235,000), not from the rent the lease started at.
    app(LeaseReliefService::class)->grant($lease->fresh(), [
        'percent_off' => 50,
        'from' => '2029-01-01',
        'to' => '2029-03-31',
        'reason' => 'Trading collapsed during the anchor refit.',
    ]);

    expect($billed('2028-12-15'))->toBe(235000.0)
        ->and($billed('2029-01-15'))->toBe(117500.0)
        ->and($billed('2029-03-15'))->toBe(117500.0)
        // Reverts on its own, at the expanded rent.
        ->and($billed('2029-04-15'))->toBe(235000.0);

    // ── S9 · the term ends 30 Jun 2029 and the tenant stays ───────────────────────────────────
    CarbonImmutable::setTestNow('2029-07-20');

    // Before conversion: expired, occupied, billing nothing. That is the gap.
    expect($billed('2029-07-15'))->toBe(0.0)
        ->and(Lease::holdoverNeedingAction()->pluck('id')->all())->toContain($lease->id);

    app(ConvertLeaseToHoldoverService::class)->convert($lease->fresh(), [
        'rate_pct' => 150,
        'reason' => 'Tenant remains in occupation pending renewal terms.',
    ]);

    // 150% of the rent in force at expiry — which is the EXPANDED rent, not the original, and not
    // the relieved one that ended in March.
    expect($billed('2029-07-15'))->toBe(352500.0)
        ->and($billed('2029-09-15'))->toBe(352500.0)
        ->and(Lease::holdoverNeedingAction()->pluck('id')->all())->not->toContain($lease->id);

    // ── The history, replayed ─────────────────────────────────────────────────────────────────
    $lease = $lease->fresh();

    expect($lease->events()->pluck('type')->all())->toBe([
        LeaseEvent::TYPE_HOLDOVER,        // effective 2029-07-01, newest first
        LeaseEvent::TYPE_ABATEMENT,       // effective 2029-01-01
        LeaseEvent::TYPE_EXPANSION,       // effective 2028-11-01
    ]);

    // Every change is attributed to the operator who made it, and carries its own effective date.
    expect($lease->events->pluck('user_id')->unique()->all())->toBe([$operator->id]);

    // The auditor's question: what did this lease look like at the end of 2028? The expansion had
    // happened; the relief and the holdover had not.
    $asOf2028 = $lease->eventsAsOf(CarbonImmutable::parse('2028-12-31'));

    expect($asOf2028)->toHaveCount(1)
        ->and($asOf2028->first()->type)->toBe(LeaseEvent::TYPE_EXPANSION)
        ->and($asOf2028->first()->payload['area_to'])->toEqual(1200.0);

    // …and the whole story, oldest first, once it is all done.
    expect($lease->eventsAsOf(CarbonImmutable::parse('2029-12-31'))->pluck('type')->all())->toBe([
        LeaseEvent::TYPE_EXPANSION,
        LeaseEvent::TYPE_ABATEMENT,
        LeaseEvent::TYPE_HOLDOVER,
    ]);
});

it('keeps every month of a heavily-amended lease covered by exactly one rent row', function () {
    // Four writers touch this schedule — the seed, an expansion, a relief overlay and a holdover
    // conversion. Each is unambiguous alone; the risk is what they do to each other. A month
    // covered by two rows bills the tenant twice, and a month covered by none bills nothing.
    CarbonImmutable::setTestNow('2028-09-20');

    $lease = makeLease($this->master, null, [
        'status' => 'active', 'commencement_date' => '2028-01-01', 'expiry_date' => '2029-06-30',
        'base_rent_monthly' => 180000, 'has_marketing_levy' => false,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_SEED, 'amount' => 180000, 'currency' => 'EGP',
        'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT,
        'start_date' => '2028-01-01', 'is_active' => true,
    ]);

    app(LeaseSpaceChangeService::class)->expand($lease, [
        'unit_ids' => [$this->adjacent->id], 'effective_from' => '2028-11-01',
        'new_total_rent' => 235000, 'reason' => 'Expansion.',
    ]);
    app(LeaseReliefService::class)->grant($lease->fresh(), [
        'percent_off' => 50, 'from' => '2029-01-01', 'to' => '2029-03-31', 'reason' => 'Relief.',
    ]);

    CarbonImmutable::setTestNow('2029-07-20');
    app(ConvertLeaseToHoldoverService::class)->convert($lease->fresh(), [
        'rate_pct' => 150, 'reason' => 'Holdover.',
    ]);

    $rows = Charge::where('lease_id', $lease->id)->where('type', 'base_rent')->where('is_active', true)->get();

    for ($m = CarbonImmutable::parse('2028-01-01'); $m->lte(CarbonImmutable::parse('2030-06-01')); $m = $m->addMonth()) {
        $covering = $rows->filter(fn (Charge $c) => (blank($c->start_date) || CarbonImmutable::instance($c->start_date)->lte($m))
            && (blank($c->end_date) || CarbonImmutable::instance($c->end_date)->gte($m)));

        expect($covering)->toHaveCount(1, "month {$m->format('Y-m')} is covered by {$covering->count()} rent rows");
    }
});
