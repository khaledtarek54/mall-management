<?php

/*
|--------------------------------------------------------------------------
| The job's stored material cost ignored the stock ledger (SW-071)
|--------------------------------------------------------------------------
| Two definitions of one number. `FacilityWorkOrder::partsCost()` reads
| `FacilityWorkOrderPart::scopeCounted()`, which follows the STOCK LEDGER — an approved draw counts
| only while its movement is live, because voiding one puts the stock back on the shelf — and
| `docs/modules/26-facility.md` documents exactly that ("a voided draw's movement | the entry is
| voided and `counted()` stops charging the job"). `recomputeCosts()` asked a different question:
| `whereIn('status', ['approved', 'recorded'])`, i.e. the STATUS alone, which nothing updates when
| a movement is voided. So the stored `act_material_cost` went on charging a job for parts sitting
| back in the store while `partsCost()` beside it said zero, and the module doc described a
| behaviour the column did not have.
|
| **Measured, and measured honestly:** nothing in the running system can void a stock movement
| today — `StockMovement` carries `#[NeverDeletable]` and `RefusesDeletionOfCommittedRecords`
| refuses the delete from every path — so this closes a SPLIT DEFINITION rather than a live money
| leak. The panel already renders `movementWasVoided()` on the parts tab, so the state is one the
| application expects to meet; the fix is to make the one method that owns the cost read the one
| scope that owns the answer.
*/

use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderPart;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\StockMovementService;
use App\Services\WorkOrderPartService;
use Database\Seeders\ApprovalRulesSeeder;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ApprovalRulesSeeder::class);
    ensureAllPropertiesAsset();

    $this->svc = app(WorkOrderPartService::class);
    $this->asset = makeAsset(['code' => 'SHLF']);
    $this->engineer = makeUser('operations', [$this->asset->id]);
    $this->approver = makeUser('manager', [$this->asset->id]);

    $this->warehouse = Warehouse::create(['asset_id' => $this->asset->id, 'name' => 'Store', 'code' => 'W1']);
    $this->item = InventoryItem::create(['sku' => 'SEAL', 'name' => 'Seal', 'unit' => 'each', 'unit_cost' => 100]);

    app(StockMovementService::class)->record([
        'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->item->id,
        'type' => 'receipt',
        'quantity' => 50,
        'unit_cost' => 100,
    ]);

    $this->job = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id,
        'work_order_type' => 'cm',
        'execution_type' => 'internal',
        'title' => 'Reseal the pump',
        'description' => 'Weeping gland',
        'trade_id' => tradeId('plumbing'),
        'priority' => 'medium',
        'status' => 'open',
        'scheduled_for' => '2026-07-01',
    ]);
});

/** An approved internal draw — the only path that mints a stock movement. */
function costObjectDraw(float $quantity = 5): FacilityWorkOrderPart
{
    $part = test()->svc->requestInternal(test()->job, [
        'inventory_item_id' => test()->item->id,
        'warehouse_id' => test()->warehouse->id,
        'quantity' => $quantity,
    ], test()->engineer->id);

    return test()->svc->approve($part, test()->approver);
}

it('stops charging the job for a draw whose stock went back on the shelf', function () {
    $part = costObjectDraw();

    // The control: while the movement is live both readings agree, so a fix that simply stopped
    // counting parts would not satisfy this.
    expect($this->job->fresh()->partsCost())->toBe(500.0)
        ->and((float) $this->job->fresh()->act_material_cost)->toBe(500.0);

    trashBypassingDeletionPolicy(StockMovement::find($part->stock_movement_id));

    // Voiding a movement is not one of the three cost channels, so nothing recomputes on its own;
    // any later part, labour or bill write on this job runs the same method, which is what makes
    // the stale figure permanent rather than momentary.
    $this->job->fresh()->recomputeCosts();

    expect($this->job->fresh()->partsCost())->toBe(0.0)
        ->and((float) $this->job->fresh()->act_material_cost)->toBe(0.0)
        ->and((float) $this->job->fresh()->act_total_cost)->toBe(0.0)
        // The row itself still says "issued" — which is why a status filter could never answer
        // this and the panel has `movementWasVoided()` to say so.
        ->and($part->fresh()->status)->toBe(FacilityWorkOrderPart::STATUS_APPROVED)
        ->and($part->fresh()->movementWasVoided())->toBeTrue();
});

it('still counts a part bought outside, which never had a movement at all', function () {
    // `counted()` is `(approved AND its movement is live) OR recorded`. An external purchase has
    // no stock to relieve — that is what makes it external — so requiring a movement everywhere
    // would silently drop the whole outside-purchase channel out of the job's cost.
    $this->svc->recordExternal($this->job, [
        'description' => 'Bespoke gasket',
        'quantity' => 1,
        'unit_cost' => 750,
    ], $this->engineer->id);

    expect($this->job->fresh()->partsCost())->toBe(750.0)
        ->and((float) $this->job->fresh()->act_material_cost)->toBe(750.0);
});

it('gives the stored figure and the live one one definition, in every state', function () {
    // The invariant the two readings had drifted from, asserted at each step rather than only at
    // the end — an equality that holds at 0.00 and nowhere else would read as a pass.
    $agree = function (float $expected): void {
        $job = test()->job->fresh();

        expect($job->partsCost())->toBe($expected)
            ->and((float) $job->act_material_cost)->toBe($expected);
    };

    $agree(0.0);

    $part = costObjectDraw(3);                 // 3 x 100
    $agree(300.0);

    $this->svc->recordExternal($this->job, [
        'description' => 'Bespoke gasket', 'quantity' => 1, 'unit_cost' => 750,
    ], $this->engineer->id);
    $agree(1050.0);

    trashBypassingDeletionPolicy(StockMovement::find($part->stock_movement_id));
    $this->job->fresh()->recomputeCosts();
    $agree(750.0);                             // the outside purchase stands; the draw comes back out
});
