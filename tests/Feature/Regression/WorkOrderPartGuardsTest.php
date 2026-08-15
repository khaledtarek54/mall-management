<?php

use App\Models\ApprovalRule;
use App\Models\InventoryItem;
use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderPart;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\StockMovementService;
use App\Services\WorkOrderPartService;
use Database\Seeders\ApprovalRulesSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Bugs found reviewing the spare-parts feature (2026-07-16), each proven before it was fixed.
 * Every one of them was a business rule that lived in the Filament form instead of at the
 * write boundary — so every one of them was reachable by any other caller.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ApprovalRulesSeeder::class);
    $this->svc = app(WorkOrderPartService::class);
    $this->asset = makeAsset(['code' => 'AAA']);
    $this->other = makeAsset(['code' => 'BBB']);
    $this->wh = Warehouse::create(['asset_id' => $this->asset->id, 'name' => 'Ours', 'code' => 'W1']);
    $this->foreignWh = Warehouse::create(['asset_id' => $this->other->id, 'name' => 'Theirs', 'code' => 'W2']);
    $this->item = InventoryItem::create(['sku' => 'S', 'name' => 'Seal', 'unit' => 'each', 'unit_cost' => 100]);

    foreach ([$this->wh, $this->foreignWh] as $w) {
        app(StockMovementService::class)->record([
            'warehouse_id' => $w->id, 'inventory_item_id' => $this->item->id,
            'type' => 'receipt', 'quantity' => 50, 'unit_cost' => 100,
        ]);
    }

    $this->order = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id, 'work_order_type' => 'cm', 'execution_type' => 'internal',
        'description' => 'd', 'title' => 't', 'category' => 'plumbing', 'scheduled_for' => '2026-07-01',
    ]);
});

it('refuses a draw from another property\'s warehouse at the service, not just the form', function () {
    // Proven before the fix: a job on AAA consumed BBB's stock, dropping BBB's on-hand and
    // posting the cost to BBB's GL dimension. The Filament option list was the only thing
    // stopping it, so the mobile API and any future service walked straight past.
    expect(fn () => $this->svc->requestInternal($this->order, [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->foreignWh->id, 'quantity' => 5,
    ], makeUser('operations', [$this->asset->id])->id))->toThrow(DomainException::class);

    expect(FacilityWorkOrderPart::count())->toBe(0);
    expect((float) StockMovement::where('warehouse_id', $this->foreignWh->id)->sum('quantity'))->toBe(50.0);
});

it('prices a blank unit cost from the catalog instead of zero', function () {
    // Proven before the fix: `?? ` doesn't catch '', so (float) '' === 0.0 priced the part at
    // zero and dropped a 500 EGP draw to tier_1 — an approval-ladder bypass by empty string.
    $part = $this->svc->requestInternal($this->order, [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->wh->id,
        'quantity' => 5, 'unit_cost' => '',
    ], makeUser('operations', [$this->asset->id])->id);

    expect((float) $part->value)->toBe(500.0);
    expect($part->required_permission)->toBe(ApprovalRule::TIER_1); // 500 genuinely is tier_1…

    // …but the tier now follows the real value: the same blank cost on a big draw escalates.
    $big = $this->svc->requestInternal($this->order, [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->wh->id,
        'quantity' => 40, 'unit_cost' => '', // 4,000
    ], makeUser('operations', [$this->asset->id])->id);

    expect((float) $big->value)->toBe(4000.0);
    expect($big->required_permission)->toBe(ApprovalRule::TIER_2);
});

it('stops charging the job for a draw whose movement was voided', function () {
    // Proven before the fix: voiding put the stock back (45 → 50) while partsCost() still
    // reported 500 — the job charged for parts sitting on the shelf.
    $part = $this->svc->requestInternal($this->order, [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->wh->id, 'quantity' => 5,
    ], makeUser('operations', [$this->asset->id])->id);
    $part = $this->svc->approve($part, makeUser('manager', [$this->asset->id]));

    expect($this->order->partsCost())->toBe(500.0);

    trashBypassingDeletionPolicy(StockMovement::find($part->stock_movement_id)); // void

    expect((float) StockMovement::where('warehouse_id', $this->wh->id)->sum('quantity'))->toBe(50.0);
    expect($this->order->partsCost())->toBe(0.0);
    expect($part->fresh()->movementWasVoided())->toBeTrue(); // and the UI can say so
});

it('refuses a negative unit cost on any write path', function () {
    // A negative cost would make a part *reduce* the job's materials cost.
    expect(fn () => $this->svc->recordExternal($this->order, [
        'description' => 'refund?', 'quantity' => 1, 'unit_cost' => -5000,
    ], makeUser('operations', [$this->asset->id])->id))->toThrow(InvalidArgumentException::class);

    expect($this->order->partsCost())->toBe(0.0);
});

it('lets a mistyped external purchase be removed, and keeps the record', function () {
    // External is the one path with no approval to catch a fat-finger, and it counted forever.
    $part = $this->svc->recordExternal($this->order, [
        'description' => 'typo', 'quantity' => 1, 'unit_cost' => 99999,
    ], makeUser('operations', [$this->asset->id])->id);

    expect($this->order->partsCost())->toBe(99999.0);

    $this->svc->remove($part, 'Typo — should have been 99.99.', makeUser('manager', [$this->asset->id]));

    expect($this->order->partsCost())->toBe(0.0);
    expect(FacilityWorkOrderPart::withTrashed()->find($part->id)->decision_notes)
        ->toBe('Typo — should have been 99.99.'); // soft-deleted, not erased
});

it('refuses to remove an internal draw, and refuses a viewer', function () {
    // An internal draw has its own undo paths (reject while pending, void once issued) — a
    // silent delete would strand the stock movement it already made.
    $internal = $this->svc->requestInternal($this->order, [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->wh->id, 'quantity' => 5,
    ], makeUser('operations', [$this->asset->id])->id);

    expect(fn () => $this->svc->remove($internal, 'no', makeUser('manager', [$this->asset->id])))
        ->toThrow(DomainException::class);

    $external = $this->svc->recordExternal($this->order, [
        'description' => 'gasket', 'quantity' => 1, 'unit_cost' => 100,
    ], makeUser('operations', [$this->asset->id])->id);

    expect(fn () => $this->svc->remove($external, 'no', makeUser('viewer', [$this->asset->id])))
        ->toThrow(DomainException::class);
    expect(FacilityWorkOrderPart::whereKey($external->id)->exists())->toBeTrue();
});

it('names the tier in words rather than leaking a translation key', function () {
    // Proven before the fix: __() reads dots as nesting, so a 'approvals.tier_1' array key
    // could never resolve — every pending row read "Needs admin.facility.
    // parts.tiers.approvals.tier_1", as did the notification after every request.
    $part = $this->svc->requestInternal($this->order, [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->wh->id, 'quantity' => 2,
    ], makeUser('operations', [$this->asset->id])->id);

    expect($part->awaitingTierLabel())->toBe('a supervisor')
        ->not->toContain('admin.facility');

    // Arabic resolves too — the same dotted-key bug hit both files.
    app()->setLocale('ar');
    expect($part->awaitingTierLabel())->toBe('مشرف');
    app()->setLocale('en');

    // A permission outside the ladder degrades to a vague truth, never a raw key.
    $part->update(['required_permission' => 'approvals.some_future_tier']);
    expect($part->fresh()->awaitingTierLabel())->toBe('a higher authority');

    // Nothing pending → nothing to wait on.
    expect($this->svc->reject($part->fresh(), 'no', makeUser('manager', [$this->asset->id]))->awaitingTierLabel())
        ->toBeNull();
});

it('registers an activity-log subject label for the part', function () {
    // useLogName('work_order_part') with no matching key rendered the raw slug in the log.
    expect(__('admin.activity.subjects.work_order_part'))->toBe('Work Order Part');
    app()->setLocale('ar');
    expect(__('admin.activity.subjects.work_order_part'))->not->toBe('admin.activity.subjects.work_order_part');
    app()->setLocale('en');
});

it('refuses a read-only viewer even when no ladder is configured', function () {
    // Regression: ApprovalPolicy answers "which manager", never "may this person touch
    // inventory at all" — with no bands it returns true for ANY signed-in user. Checking it
    // alone made deleting the bands an open door: a viewer approved 50,000 EGP and moved the
    // stock. The base inventory right must be checked independently of the ladder.
    $part = $this->svc->requestInternal($this->order, [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->wh->id, 'quantity' => 10, // 1,000
    ], makeUser('operations', [$this->asset->id])->id);

    ApprovalRule::query()->delete();

    $viewer = makeUser('viewer', [$this->asset->id]);
    expect($viewer->can('inventory.create'))->toBeFalse();
    expect(fn () => $this->svc->approve($part, $viewer))->toThrow(DomainException::class);
    expect(fn () => $this->svc->reject($part->fresh(), 'no', $viewer))->toThrow(DomainException::class);
    expect((float) StockMovement::where('warehouse_id', $this->wh->id)->sum('quantity'))->toBe(50.0);

    // ...and the module still works for someone who legitimately holds the right.
    expect($this->svc->approve($part->fresh(), makeUser('manager', [$this->asset->id]))->status)
        ->toBe(FacilityWorkOrderPart::STATUS_APPROVED);
});
