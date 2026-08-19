<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\LowStockAlert;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Turn a property's open low-stock alerts into a DRAFT purchase request.
 *
 * `inventory:scan-low-stock` has alerted per property since it was built, and the alert only ever
 * rang a bell — somebody then re-typed the same shortages into a purchase request by hand. This
 * closes that loop, and closes it at the one point where it can be closed safely.
 *
 * **A draft, never a submission** (operator's decision, 2026-08-19). The system may do the typing;
 * it may not create an obligation. A request that goes straight into the approval ladder has its
 * approval tier chosen by a value nobody entered, so the ladder would be answering a question no
 * human asked — the module whose whole job is to fail closed, deciding on its own input. A human
 * opens the draft, adjusts the quantities and submits it, and from that moment it is an ordinary
 * request with an ordinary approver.
 *
 * **One draft per property, not one per item.** An operator raising a purchase buys a batch from a
 * supplier; a draft per shortage would produce eleven documents for one phone call.
 *
 * **Idempotent by construction.** While a system-raised draft is still open for a property, the
 * next run REFRESHES its lines rather than creating a second one — otherwise a weekly scan leaves
 * a drift of stale drafts, and the operator learns to ignore all of them. Refreshing also means a
 * shortage that resolved itself drops off the draft instead of being ordered anyway.
 *
 * The draft is recognisable as system-raised by `requested_by_user_id === null`: nobody asked for
 * it. That is a fact about the row rather than a flag to maintain, and it is exactly what the
 * screen needs in order to say so.
 */
class DraftReorderPurchaseService
{
    /**
     * @return array{drafted:int, refreshed:int, skipped:int}
     */
    public function run(): array
    {
        $drafted = 0;
        $refreshed = 0;
        $skipped = 0;

        $assets = Asset::query()
            ->where('code', '!=', Asset::ALL_PROPERTIES_CODE)
            ->pluck('id');

        foreach ($assets as $assetId) {
            $alerts = LowStockAlert::query()
                ->where('asset_id', $assetId)
                ->whereNull('resolved_at')
                ->with('item')
                ->get()
                ->filter(fn (LowStockAlert $a): bool => $a->item !== null);

            if ($alerts->isEmpty()) {
                continue;
            }

            // A property with no storeroom cannot take delivery of anything, and a purchase
            // request needs a warehouse to receive into.
            $warehouseId = Warehouse::query()->where('asset_id', $assetId)->value('id');

            if ($warehouseId === null) {
                $skipped++;

                continue;
            }

            $outcome = DB::transaction(function () use ($assetId, $warehouseId, $alerts): string {
                /** @var PurchaseRequest|null $existing */
                $existing = PurchaseRequest::query()
                    ->where('asset_id', $assetId)
                    ->where('status', PurchaseRequest::STATUS_DRAFT)
                    ->whereNull('requested_by_user_id')
                    ->lockForUpdate()
                    ->first();

                $request = $existing ?? PurchaseRequest::create([
                    'asset_id' => $assetId,
                    'warehouse_id' => $warehouseId,
                    'status' => PurchaseRequest::STATUS_DRAFT,
                    'justification' => __('admin.procurement.reorder_justification'),
                    // Nobody asked for it — this null IS the record that it was system-raised.
                    'requested_by_user_id' => null,
                ]);

                // Rebuilt rather than merged: a shortage that has resolved since the last run must
                // drop OFF the draft. Merging would leave it there to be ordered anyway, which is
                // the failure mode of every "helpful" pre-filled document.
                $request->lines()->delete();

                foreach ($alerts as $alert) {
                    $item = $alert->item;

                    // Stated reorder quantity if the operator has set one; otherwise the shortfall,
                    // which lands the item exactly on its threshold and is therefore a number to be
                    // corrected, not accepted. Inventing a multiple here would be inventing a
                    // purchasing policy — and a plausible wrong number in a draft gets approved,
                    // whereas a blank gets filled in.
                    $quantity = $item->reorder_quantity !== null
                        ? (float) $item->reorder_quantity
                        : max((float) $alert->reorder_level - (float) $alert->on_hand, 0.0);

                    if ($quantity <= 0) {
                        continue;
                    }

                    $unitCost = (float) ($item->unit_cost ?? 0);

                    PurchaseRequestLine::create([
                        'purchase_request_id' => $request->getKey(),
                        // The catalog item, and NO description: a line is one or the other, and the
                        // model refuses both. The item carries its own name, so repeating it here
                        // would be a second copy of a fact that can go stale.
                        'inventory_item_id' => $item->getKey(),
                        'quantity' => $quantity,
                        'unit_cost' => $unitCost,
                        'line_value' => round($quantity * $unitCost, 2),
                    ]);
                }

                // Through the model's own recompute, so the total and the approval tier derive the
                // way they do for every other request — F-104 was exactly this rule being bypassed.
                $request->recomputeTotal();

                return $existing ? 'refreshed' : 'drafted';
            });

            $outcome === 'drafted' ? $drafted++ : $refreshed++;
        }

        return ['drafted' => $drafted, 'refreshed' => $refreshed, 'skipped' => $skipped];
    }
}
