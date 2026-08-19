<?php

/*
|--------------------------------------------------------------------------
| Low stock rang a bell and nothing else (2026-08-19)
|--------------------------------------------------------------------------
| `inventory:scan-low-stock` has alerted per property since it was built, and the alert was the
| whole mechanism: somebody then re-typed the same shortages into a purchase request by hand. The
| benchmark tools close this loop automatically.
|
| The reason it stayed open is a policy question rather than a missing query — **who approves a
| purchase the system raised by itself?** Answered by the operator on 2026-08-19: the scan drafts,
| a human submits.
|
| That answer needed a state that did not exist. `PurchaseRequest` had no `draft`: `requested` is
| already IN the approval ladder, so a system-raised request would have had its approval tier
| chosen by a value nobody entered — the module whose whole job is to fail closed, deciding on its
| own input. `draft` can only be submitted or cancelled; it can never be approved.
|
| The draft is recognisable as system-raised by `requested_by_user_id === null` — nobody asked for
| it. A fact about the row rather than a flag to maintain.
*/

use App\Models\InventoryItem;
use App\Models\LowStockAlert;
use App\Models\PurchaseRequest;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\DraftReorderPurchaseService;
use App\Services\PurchaseRequestService;
use Database\Seeders\ApprovalRulesSeeder;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    // The ladder itself, because the tier is derived from it — without rules `permissionFor()`
    // correctly returns null and the submit test would assert against an unconfigured system.
    $this->seed(ApprovalRulesSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->warehouse = Warehouse::create([
        'asset_id' => $this->asset->id, 'code' => 'WH1', 'name' => 'Main store',
    ]);
});

function shortItem($ctx, array $attrs = []): InventoryItem
{
    $item = InventoryItem::create(array_merge([
        'sku' => 'SKU-'.uniqid(),
        'name' => 'Air filter',
        'category' => 'consumable',
        'unit' => 'pcs',
        'unit_cost' => 100,
        'reorder_level' => 10,
        'is_active' => true,
    ], $attrs));

    // On hand 2 against a reorder level of 10.
    StockMovement::create([
        'inventory_item_id' => $item->id,
        'warehouse_id' => $ctx->warehouse->id,
        'asset_id' => $ctx->asset->id,
        'type' => 'receipt',
        'quantity' => 2,
        'unit_cost' => 100,
        'moved_on' => now(),
    ]);

    return $item;
}

it('drafts a purchase request from the open shortages', function () {
    shortItem($this);

    $this->artisan('inventory:scan-low-stock')->assertExitCode(0);

    $draft = PurchaseRequest::where('status', PurchaseRequest::STATUS_DRAFT)->first();

    expect($draft)->not->toBeNull()
        ->and($draft->asset_id)->toBe($this->asset->id)
        ->and($draft->lines()->count())->toBe(1)
        // Nobody asked for it — the null requester IS the record that it was system-raised.
        ->and($draft->requested_by_user_id)->toBeNull()
        // And critically: no approval tier. A value the system chose must not select the approver
        // who will decide it.
        ->and($draft->required_permission)->toBeNull();
});

it('orders the stated reorder quantity when the operator has set one', function () {
    shortItem($this, ['reorder_quantity' => 50]);

    $this->artisan('inventory:scan-low-stock')->assertExitCode(0);

    $line = PurchaseRequest::where('status', PurchaseRequest::STATUS_DRAFT)->first()->lines()->first();

    expect((float) $line->quantity)->toBe(50.0);
});

/**
 * With no stated reorder quantity the line carries the SHORTFALL — which lands the item exactly on
 * its own threshold and is therefore a number to be corrected, not accepted. That is deliberate:
 * inventing a multiple of the reorder level would be inventing a purchasing policy, and a
 * plausible wrong number in a draft gets approved whereas a blank gets filled in.
 */
it('falls back to the shortfall when no reorder quantity is stated', function () {
    shortItem($this);

    $this->artisan('inventory:scan-low-stock')->assertExitCode(0);

    $line = PurchaseRequest::where('status', PurchaseRequest::STATUS_DRAFT)->first()->lines()->first();

    expect((float) $line->quantity)->toBe(8.0); // reorder level 10 − on hand 2
});

/**
 * Idempotence, and the reason it must be a REFRESH rather than a skip: a shortage that resolved
 * itself has to drop off the draft. Merging would leave it there to be ordered anyway, which is
 * the failure mode of every helpfully pre-filled document.
 */
it('refreshes the existing draft instead of piling up a second one', function () {
    shortItem($this);

    $this->artisan('inventory:scan-low-stock')->assertExitCode(0);
    $this->artisan('inventory:scan-low-stock')->assertExitCode(0);

    expect(PurchaseRequest::where('status', PurchaseRequest::STATUS_DRAFT)->count())->toBe(1);
});

it('cannot be approved while it is a draft', function () {
    shortItem($this);
    $this->artisan('inventory:scan-low-stock')->assertExitCode(0);

    $draft = PurchaseRequest::where('status', PurchaseRequest::STATUS_DRAFT)->first();
    $approver = makeUser('super_admin', [$this->asset->id]);

    // The whole safety property: the system may write the typing, it may never create an
    // obligation. `draft` has no path to `approved` in the transition matrix.
    expect(fn () => app(PurchaseRequestService::class)->approve($draft, null, $approver))
        ->toThrow(DomainException::class);
});

it('becomes an ordinary request once a human submits it', function () {
    shortItem($this);
    $this->artisan('inventory:scan-low-stock')->assertExitCode(0);

    $draft = PurchaseRequest::where('status', PurchaseRequest::STATUS_DRAFT)->first();
    $buyer = makeUser('super_admin', [$this->asset->id]);

    $submitted = app(PurchaseRequestService::class)->submit($draft, $buyer);

    expect($submitted->status)->toBe(PurchaseRequest::STATUS_REQUESTED)
        // The submitter owns it from here — that assignment is the moment a person takes
        // responsibility for a document the system typed.
        ->and($submitted->requested_by_user_id)->toBe($buyer->id)
        // And only NOW does it get an approval tier, derived from the total as submitted.
        ->and($submitted->required_permission)->not->toBeNull();
});

/** The dry run must preview, not write — F-96 is exactly this bug in this module. */
it('writes no draft on a dry run', function () {
    shortItem($this);

    $this->artisan('inventory:scan-low-stock', ['--dry-run' => true])->assertExitCode(0);

    expect(PurchaseRequest::count())->toBe(0);
});

it('can be switched off without losing the alert', function () {
    shortItem($this);

    $this->artisan('inventory:scan-low-stock', ['--no-draft' => true])->assertExitCode(0);

    expect(PurchaseRequest::count())->toBe(0)
        ->and(LowStockAlert::count())->toBe(1);
});

/**
 * A property with no storeroom cannot take delivery of anything, and a purchase request needs a
 * warehouse to receive into. Skipped rather than drafted against a null.
 */
it('skips a property with no warehouse', function () {
    $other = makeAsset();
    $item = shortItem($this);

    // A shortage recorded against a property that has nowhere to put stock.
    LowStockAlert::create([
        'inventory_item_id' => $item->id,
        'asset_id' => $other->id,
        'on_hand' => 0,
        'reorder_level' => 10,
        'notified_at' => now(),
    ]);

    $result = app(DraftReorderPurchaseService::class)->run();

    expect($result['skipped'])->toBe(1)
        ->and(PurchaseRequest::where('asset_id', $other->id)->count())->toBe(0);
});
