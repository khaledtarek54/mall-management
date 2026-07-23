<?php

use App\Models\InventoryItem;
use App\Models\PurchaseRequest;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\PurchaseOrderPdfService;
use App\Services\PurchaseRequestService;
use Database\Seeders\ApprovalRulesSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * The Purchase Order document (module 29).
 *
 * Ordering a request used to flip a status and store a free-text `order_reference` — the vendor
 * received nothing. Every procurement system produces a numbered, itemized PO you actually send.
 * These pin the PO number's identity + generation, and that the document renders for a real order.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ApprovalRulesSeeder::class);

    $this->svc = app(PurchaseRequestService::class);
    $this->asset = makeAsset(['code' => 'PGL']);
    $this->warehouse = Warehouse::create(['asset_id' => $this->asset->id, 'name' => 'Main', 'code' => 'W1']);
    $this->item = InventoryItem::create(['sku' => 'FILT', 'name' => 'Air filter', 'unit' => 'each', 'unit_cost' => 50]);
    $this->buyer = makeUser('operations', [$this->asset->id]);
    $this->manager = makeUser('manager', [$this->asset->id]);
    $this->vendor = Vendor::create(['name' => 'Falcon Facilities', 'status' => Vendor::STATUS_ACTIVE]);
});

function orderedPr(?int $vendorId = null): PurchaseRequest
{
    $r = test()->svc->request([
        'asset_id' => test()->asset->id,
        'justification' => 'Filters low.',
        'warehouse_id' => test()->warehouse->id,
        'lines' => [['inventory_item_id' => test()->item->id, 'quantity' => 10, 'unit_cost' => 50]],
    ], test()->buyer);

    test()->svc->approve($r, null, test()->manager);

    return test()->svc->order($r->fresh(), $vendorId ?? test()->vendor->id, 'Q-8891', test()->manager);
}

it('stamps a distinct PO number at order time, separate from the requisition reference', function () {
    $ordered = orderedPr();

    expect($ordered->po_number)->not->toBeNull()
        ->and($ordered->po_number)->toStartWith('PO-PGL-')
        // The PO number is the ORDER's identity; the reference is the internal requisition's.
        ->and($ordered->po_number)->not->toBe($ordered->reference)
        ->and($ordered->reference)->toStartWith('PR-PGL-')
        // The supplier's own reference is preserved separately.
        ->and($ordered->order_reference)->toBe('Q-8891');
});

it('does not stamp a PO number before the request is ordered', function () {
    $r = test()->svc->request([
        'asset_id' => $this->asset->id,
        'justification' => 'Filters low.',
        'warehouse_id' => $this->warehouse->id,
        'lines' => [['inventory_item_id' => $this->item->id, 'quantity' => 10, 'unit_cost' => 50]],
    ], $this->buyer);

    expect($r->po_number)->toBeNull();

    $this->svc->approve($r, null, $this->manager);
    expect($r->fresh()->po_number)->toBeNull(); // still just an approved requisition, not an order
});

it('gives sequential, unique PO numbers within a property + month', function () {
    $a = orderedPr();
    $b = orderedPr();

    expect($a->po_number)->not->toBe($b->po_number)
        ->and(PurchaseRequest::distinct()->whereNotNull('po_number')->count('po_number'))->toBe(2);
});

it('renders a PO PDF for an ordered request', function () {
    $ordered = orderedPr();

    $svc = app(PurchaseOrderPdfService::class);
    $pdf = $svc->build($ordered);

    expect($pdf)->toStartWith('%PDF-')
        ->and(strlen($pdf))->toBeGreaterThan(1000)
        ->and($svc->filename($ordered))->toBe('PO-'.$ordered->po_number.'.pdf');
});

it('renders the PO in Arabic without falling back to raw keys', function () {
    $ordered = orderedPr();
    app()->setLocale('ar');

    // A raw i18n key would surface as the literal string; the render must not throw and must
    // produce a real document (the "looks very bad, why blade" lesson — keys must resolve).
    $pdf = app(PurchaseOrderPdfService::class)->build($ordered);

    expect($pdf)->toStartWith('%PDF-')
        ->and(__('admin.pdf.purchase_order.title'))->not->toContain('admin.pdf');
});
