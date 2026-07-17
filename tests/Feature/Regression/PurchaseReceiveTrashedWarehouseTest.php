<?php

use App\Models\InventoryItem;
use App\Models\PurchaseRequest;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\PurchaseRequestService;
use Database\Seeders\ApprovalRulesSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Regression — gap-analysis **F-105** (module 29): receiving into a soft-deleted warehouse.
 *
 * THE BUG. `Warehouse` soft-deletes, so the FK's `nullOnDelete` never fires and
 * `purchase_requests.warehouse_id` keeps pointing at the archived row. `PurchaseRequest::warehouse()`
 * was a plain `belongsTo`, so it resolved to **null** — slipping past `receive()`'s
 * `warehouse_id === null` guard. Then `(int) null->asset_id` = `0` ≠ `asset_id`, so it landed in the
 * **cross-property** branch and told the operator the warehouse *"belongs to another property"* —
 * false, and unfixable: `warehouse_id` is only editable while `requested`, so the order was stuck in
 * `ordered` forever. Under HTTP the same null-deref becomes an `ErrorException`, which
 * `PurchaseRequestsTable` (catching `DomainException` only) would not handle — a 500.
 *
 * Retiring a storeroom while an order is in transit is ordinary. The goods still have to land, and
 * the request must still say where they were going.
 *
 * THE PATTERN, again — a guard that already existed one model away. `StockMovement::warehouse()`
 * carries `withTrashed()` with a comment naming this exact hazard, and says it *"matches
 * Custody/PayrollLine::employee"*. Three models had it; `PurchaseRequest` was the fourth that
 * needed it and didn't.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ApprovalRulesSeeder::class);

    $this->asset = makeAsset(['code' => 'TWH']);
    $this->svc = app(PurchaseRequestService::class);
    $this->buyer = makeUser('operations', [$this->asset->id]);
    $this->senior = makeUser('super_admin', [$this->asset->id]);

    $this->warehouse = Warehouse::create([
        'asset_id' => $this->asset->id, 'code' => 'WH-TWH', 'name' => 'Site storeroom',
    ]);
    $this->item = InventoryItem::create([
        'sku' => 'TWH-1', 'name' => 'Pump seal', 'unit' => 'each', 'unit_cost' => 50,
    ]);
});

/** An ordered purchase, 10 × 50, bound for the storeroom. */
function twhOrderedRequest(): PurchaseRequest
{
    $request = PurchaseRequest::create([
        'asset_id' => test()->asset->id,
        'reference' => 'PR-TWH-'.uniqid(),
        'justification' => 'Seals for the pump',
        'warehouse_id' => test()->warehouse->id,
        'requested_by_user_id' => test()->buyer->id,
    ]);
    $request->lines()->create([
        'inventory_item_id' => test()->item->id, 'quantity' => 10, 'unit_cost' => 50,
    ]);

    $svc = app(PurchaseRequestService::class);
    $svc->approve($request->fresh(), null, test()->senior);
    $svc->order($request->fresh(), null, 'PO-TWH', test()->senior);

    return $request->fresh();
}

it('still receives goods into a warehouse that was archived mid-order', function () {
    $request = twhOrderedRequest();

    // The storeroom is retired while the order is in transit — an ordinary thing to do.
    $this->warehouse->delete();
    expect($request->fresh()->warehouse_id)->not->toBeNull('the FK survives the soft-delete');

    $received = $this->svc->receive($request->fresh(), $this->senior);

    expect($received->status)->toBe(PurchaseRequest::STATUS_RECEIVED);

    // The stock landed, attributed to the right property.
    $movement = StockMovement::where('warehouse_id', $this->warehouse->id)
        ->where('type', 'receipt')->sole();
    expect((float) $movement->quantity)->toBe(10.0)
        ->and((float) $movement->unit_cost)->toBe(50.0);
});

it('resolves the archived warehouse rather than null', function () {
    // The precise mechanic: null here is what sent receive() down the cross-property branch and
    // blamed the wrong thing.
    $request = twhOrderedRequest();
    $this->warehouse->delete();

    expect($request->fresh()->warehouse)->not->toBeNull()
        ->and((int) $request->fresh()->warehouse->asset_id)->toBe((int) $this->asset->id);
});

it('still refuses a warehouse that genuinely belongs to another property', function () {
    // The guard that message was FOR must still work — the fix must not blunt it.
    $other = makeAsset(['code' => 'TWH2']);
    $foreign = Warehouse::create(['asset_id' => $other->id, 'code' => 'WH-X', 'name' => 'Other mall']);

    $request = twhOrderedRequest();
    $request->forceFill(['warehouse_id' => $foreign->id])->saveQuietly();

    expect(fn () => $this->svc->receive($request->fresh(), $this->senior))
        ->toThrow(DomainException::class);

    expect($request->fresh()->status)->toBe(PurchaseRequest::STATUS_ORDERED);
});
