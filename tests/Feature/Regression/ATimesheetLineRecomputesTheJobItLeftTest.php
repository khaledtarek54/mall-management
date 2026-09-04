<?php

/*
|--------------------------------------------------------------------------
| A cost moved between jobs leaves the job it LEFT overstated (SW-085)
|--------------------------------------------------------------------------
| `FacilityWorkOrder::recomputeCosts()` is the single source of truth for what a job cost, and it
| has FOUR channels: labour, part draws, vendor bills, direct expenses. Three of them carried a
| byte-identical twelve-line `saved` block that recomputed the PREVIOUS owner when a row was
| re-homed; `FacilityWorkOrderLabour` — the fourth — carried
| `static::saved(fn ($line) => $line->workOrder?->recomputeCosts())` and nothing else, so moving a
| timesheet line left the job it came off still charged for the hours.
|
| Three copies of one rule is three chances to write it and one chance to miss it. All four now use
| `App\Models\Concerns\CostsAWorkOrder`, so a FIFTH channel is wired by using the trait rather than
| by its author remembering the shape — and the last test here derives the channel list from
| `recomputeCosts()`'s own source, so it cannot go stale behind the method it guards.
|
| The labour hook also read `$line->workOrder`, a `belongsTo` whose cached value is whatever the
| foreign key pointed at when it was FIRST touched. That is the same fault through the other door:
| with the relation warm, the OLD job recomputed and the NEW one never did.
*/

use App\Models\Concerns\CostsAWorkOrder;
use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderLabour;
use App\Models\Trade;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\Eloquent\Relations\Relation;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    $this->trade = Trade::where('code', 'hvac')->firstOrFail();
    $this->trade->update(['standard_hourly_rate' => 300]);

    $job = fn (string $title) => FacilityWorkOrder::create([
        'asset_id' => $this->asset->id,
        'work_order_type' => 'ppm',
        'title' => $title,
        'trade_id' => $this->trade->id,
        'status' => 'open',
        'priority' => 'medium',
        'scheduled_for' => now()->toDateString(),
    ]);

    $this->jobA = $job('Chiller 1 — the job the hours were keyed against');
    $this->jobB = $job('Chiller 2 — the job they were actually done on');
});

it('takes the hours off the job they were moved away from', function () {
    $line = FacilityWorkOrderLabour::create([
        'facility_work_order_id' => $this->jobA->id,
        'worked_on' => now()->toDateString(),
        'hours' => 10,
    ]);

    // The control. Without it a fix that simply zeroed both jobs would read as a pass.
    expect((float) $this->jobA->fresh()->act_labour_cost)->toBe(3000.0)
        ->and((float) $this->jobA->fresh()->act_labour_hours)->toBe(10.0)
        ->and((float) $this->jobB->fresh()->act_labour_cost)->toBe(0.0);

    // The correction an operator makes when a technician keyed the wrong job.
    $line->update(['facility_work_order_id' => $this->jobB->id]);

    expect((float) $this->jobA->fresh()->act_labour_cost)->toBe(0.0)
        ->and((float) $this->jobA->fresh()->act_labour_hours)->toBe(0.0)
        ->and((float) $this->jobA->fresh()->act_total_cost)->toBe(0.0)
        ->and((float) $this->jobB->fresh()->act_labour_cost)->toBe(3000.0)
        ->and((float) $this->jobB->fresh()->act_labour_hours)->toBe(10.0)
        ->and((float) $this->jobB->fresh()->act_total_cost)->toBe(3000.0);
});

it('recomputes the NEW job even when the row is carrying a warm relation to the old one', function () {
    // The other half of the same fault, and the reason the hook resolves the job with a fresh
    // `find()` on the CURRENT foreign key rather than through a display relation. A `belongsTo`
    // loaded before the move answers with the job the row USED to belong to.
    $line = FacilityWorkOrderLabour::create([
        'facility_work_order_id' => $this->jobA->id,
        'worked_on' => now()->toDateString(),
        'hours' => 10,
    ]);

    $line->load('workOrder');

    $line->update(['facility_work_order_id' => $this->jobB->id]);

    expect((float) $this->jobB->fresh()->act_labour_cost)->toBe(3000.0)
        ->and((float) $this->jobA->fresh()->act_labour_cost)->toBe(0.0);
});

it('wires every cost channel `recomputeCosts()` actually reads', function () {
    // DERIVED from the method's own source, never a list beside it: a fifth channel added to
    // `recomputeCosts()` is swept here by existing, which is the whole point of the trait.
    $method = new ReflectionMethod(FacilityWorkOrder::class, 'recomputeCosts');
    $source = implode('', array_slice(
        file($method->getFileName()),
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1,
    ));

    preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)\(\)/', $source, $matches);

    $order = new FacilityWorkOrder;
    $channels = [];

    foreach (array_unique($matches[1]) as $name) {
        $candidate = new ReflectionMethod(FacilityWorkOrder::class, $name);
        $type = $candidate->getReturnType();

        if ($candidate->getNumberOfParameters() > 0
            || ! $type instanceof ReflectionNamedType
            || ! is_a($type->getName(), Relation::class, true)) {
            continue;
        }

        $channels[$name] = $order->{$name}()->getRelated()::class;
    }

    // The sweep must have found something before it reports on it — a regex that matched nothing
    // would otherwise pass while proving nothing (the vacuous-gate shape). A FIFTH channel added to
    // `recomputeCosts()` turns this red on purpose: raise the count, and check the new model uses
    // the trait while you are there.
    expect($channels)->toHaveCount(4);

    foreach ($channels as $model) {
        expect(class_uses_recursive($model))->toContain(CostsAWorkOrder::class);
    }
});
