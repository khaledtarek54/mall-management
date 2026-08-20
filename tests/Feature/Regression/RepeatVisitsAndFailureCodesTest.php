<?php

/*
|--------------------------------------------------------------------------
| Four visits, four invoices, four unrelated successes (2026-08-20)
|--------------------------------------------------------------------------
| Close-out step 5 — the reliability primitives. Maximo §7 (failure class → problem → cause →
| remedy) and ServiceChannel §4 (repeat-visit tracking, *"the highest-value cheap signal in retail
| FM"*).
|
| Scenario S6 is the failure: the same escalator handrail reported four times in five weeks, four
| contractor visits, EGP 8,800 — and a register showing four unrelated successes, because nothing
| recorded that all four were the same problem and nothing noticed that somebody had already been
| there.
|
| These are worth nothing on the day they ship and everything two years later, which is exactly why
| they ship before the dashboard that reads them.
*/

use App\Models\Equipment;
use App\Models\FacilityWorkOrder;
use App\Models\FailureCode;
use App\Models\Trade;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->actingAs(makeUser('manager', [$this->asset->id]));
    $this->trade = Trade::where('code', 'elevator')->firstOrFail();

    $this->escalator = Equipment::create([
        'asset_id' => $this->asset->id, 'code' => 'ESC-01', 'name_en' => 'Main escalator',
        'name_ar' => 'السلم الكهربائي', 'trade_id' => $this->trade->id, 'is_active' => true,
    ]);
});

function escalatorVisit($ctx, int $daysAgo, array $attrs = []): FacilityWorkOrder
{
    $order = FacilityWorkOrder::create(array_merge([
        'asset_id' => $ctx->asset->id,
        'equipment_id' => $ctx->escalator->id,
        'work_order_type' => FacilityWorkOrder::TYPE_CM,
        'execution_type' => 'internal',
        'title' => 'Handrail fault',
        'description' => 'Handrail stopped moving.',
        'trade_id' => $ctx->trade->id,
        'status' => 'open',
        'priority' => 'medium',
        'scheduled_for' => now()->subDays($daysAgo)->toDateString(),
    ], $attrs));

    $order->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

    return $order->fresh();
}

/* ---- repeat visits: the S6 signal --------------------------------------- */

it('notices somebody has already been here for this', function () {
    escalatorVisit($this, 20);
    $second = escalatorVisit($this, 10);

    expect($second->isRepeatVisit())->toBeTrue()
        ->and($second->priorVisitCount())->toBe(1);
});

it('does not call the first visit a repeat', function () {
    $first = escalatorVisit($this, 20);

    expect($first->isRepeatVisit())->toBeFalse()
        ->and($first->priorVisitCount())->toBe(0);
});

/** The window rolls: an old fault is not evidence about a new one. */
it('forgets a visit that falls outside the window', function () {
    escalatorVisit($this, 90);
    $now = escalatorVisit($this, 1);

    expect($now->isRepeatVisit())->toBeFalse();

    // …but a wider window sees it, which is what makes the window a JUDGEMENT rather than a fact.
    expect($now->isRepeatVisit(120))->toBeTrue();
});

/**
 * **Same THING, not merely the same property.** Two jobs in one mall are not a repeat of each
 * other; counting them so would make every busy property look like a failure.
 */
it('does not treat another machine in the same mall as a repeat', function () {
    $other = Equipment::create([
        'asset_id' => $this->asset->id, 'code' => 'ESC-02', 'name_en' => 'Second escalator',
        'name_ar' => 'السلم الثاني', 'trade_id' => $this->trade->id, 'is_active' => true,
    ]);

    escalatorVisit($this, 10, ['equipment_id' => $other->id]);
    $mine = escalatorVisit($this, 2);

    expect($mine->isRepeatVisit())->toBeFalse();
});

/** An electrical fault and a plumbing fault in one shop are two problems, not one recurring one. */
it('does not treat a different trade as a repeat', function () {
    escalatorVisit($this, 10, ['trade_id' => tradeId('plumbing')]);
    $mine = escalatorVisit($this, 2);

    expect($mine->isRepeatVisit())->toBeFalse();
});

/**
 * A FOLLOW-UP is a continuation somebody planned, not a fault that came back — and treating it as a
 * repeat would punish the operator for doing the right thing.
 */
it('does not treat a planned follow-up as a repeat', function () {
    $original = escalatorVisit($this, 10);
    $followUp = escalatorVisit($this, 2, ['parent_work_order_id' => $original->id]);

    expect($followUp->isRepeatVisit())->toBeFalse();
});

/** With no machine named, the SHOP is the thing — that is what a tenant reports about. */
it('falls back to the shop when no machine is named', function () {
    $unit = makeUnit($this->asset);

    escalatorVisit($this, 10, ['equipment_id' => null, 'unit_id' => $unit->id]);
    $second = escalatorVisit($this, 2, ['equipment_id' => null, 'unit_id' => $unit->id]);

    expect($second->isRepeatVisit())->toBeTrue();
});

/**
 * **The guard that matters most.** A job naming neither a machine nor a shop must match nothing —
 * without it, every common-area job would "repeat" every other job in its trade and the signal
 * would be noise on day one.
 */
it('refuses to match on nothing when a job names neither machine nor shop', function () {
    escalatorVisit($this, 10, ['equipment_id' => null, 'unit_id' => null, 'area_id' => null]);
    $second = escalatorVisit($this, 2, ['equipment_id' => null, 'unit_id' => null, 'area_id' => null]);

    expect($second->isRepeatVisit())->toBeFalse();
});

/** Scenario S6 end to end: four visits, and the register says so from the second one on. */
it('reads the S6 escalator as one recurring fault, not four successes', function () {
    $visits = collect([35, 25, 12, 3])->map(fn (int $d) => escalatorVisit($this, $d));

    expect($visits->map(fn (FacilityWorkOrder $w) => $w->priorVisitCount())->all())
        // The 35-day visit is outside the 3-day visit's own 30-day lookback — the window rolls.
        ->toBe([0, 1, 2, 2]);
});

/* ---- failure codes ------------------------------------------------------ */

it('ships a starter set that any job can record against', function () {
    expect(FailureCode::where('type', FailureCode::TYPE_PROBLEM)->count())->toBeGreaterThan(0)
        // Trade-null, so a freshly-added trade never has an empty picker.
        ->and(FailureCode::options(FailureCode::TYPE_PROBLEM, $this->trade->id))->not->toBeEmpty();
});

/** An HVAC job is not offered a code somebody scoped to plumbing. */
it('offers a trade\'s own codes plus the ones that belong to every trade', function () {
    $plumbingOnly = FailureCode::create([
        'type' => FailureCode::TYPE_PROBLEM, 'code' => 'blocked', 'trade_id' => tradeId('plumbing'),
        'name_en' => 'Blocked', 'name_ar' => 'انسداد',
    ]);
    $anyTrade = FailureCode::create([
        'type' => FailureCode::TYPE_PROBLEM, 'code' => 'other_fault', 'trade_id' => null,
        'name_en' => 'Other', 'name_ar' => 'أخرى',
    ]);

    $offered = FailureCode::options(FailureCode::TYPE_PROBLEM, $this->trade->id);

    expect($offered)->not->toHaveKey($plumbingOnly->id)
        ->and($offered)->toHaveKey($anyTrade->id);
});

/** The same code word can be a problem AND a cause — "leak" is both, honestly. */
it('lets one word be both a problem and a cause', function () {
    FailureCode::create(['type' => FailureCode::TYPE_PROBLEM, 'code' => 'overheating', 'name_en' => 'Overheating', 'name_ar' => 'ارتفاع الحرارة']);
    FailureCode::create(['type' => FailureCode::TYPE_CAUSE, 'code' => 'overheating', 'name_en' => 'Overheated', 'name_ar' => 'فرط الحرارة']);

    expect(FailureCode::where('code', 'overheating')->count())->toBe(2);
});

/**
 * Retiring a code must not break the jobs that recorded it — the same trap the trade register hit,
 * for the same reason: Filament validates a Select with `Rule::in`.
 */
it('still offers a retired code to the job that already carries it', function () {
    $code = FailureCode::create([
        'type' => FailureCode::TYPE_PROBLEM, 'code' => 'legacy', 'name_en' => 'Legacy', 'name_ar' => 'قديم',
    ]);
    $code->update(['is_active' => false]);

    expect(FailureCode::options(FailureCode::TYPE_PROBLEM, null))->not->toHaveKey($code->id)
        ->and(FailureCode::options(FailureCode::TYPE_PROBLEM, null, $code->id))->toHaveKey($code->id);
});

/** A code a finished job recorded is history; deactivate, never delete. */
it('refuses to delete a code a job has recorded', function () {
    $code = FailureCode::create([
        'type' => FailureCode::TYPE_CAUSE, 'code' => 'wear_test', 'name_en' => 'Wear', 'name_ar' => 'تآكل',
    ]);
    escalatorVisit($this, 1, ['failure_cause_id' => $code->id]);

    expect(fn () => $code->delete())->toThrow(DomainException::class);
});
