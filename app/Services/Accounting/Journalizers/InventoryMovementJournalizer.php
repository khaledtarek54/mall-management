<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\StockMovement;
use App\Services\Accounting\AccountResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Inventory stock movement → GL (module 22, Phase 3). Perpetual inventory:
 *
 *   Receipt         Dr Inventory (asset)           / Cr GRNI (clearing liability)
 *   Consumption     Dr Repairs & Maintenance exp.  / Cr Inventory
 *   Adjustment (+)  Dr Inventory                   / Cr Inventory Adjustment
 *   Adjustment (−)  Dr Inventory Adjustment        / Cr Inventory
 *   Transfer in/out — no GL effect (intra-company location move, same account).
 *
 * Value = |quantity| × unit_cost; the entry is dimensioned to the warehouse's
 * property (asset_id). A soft-deleted movement voids its entry (LedgerPoster::sync).
 *
 * A receipt credits a DEDICATED clearing liability (Goods Received Not Invoiced),
 * NOT the Accounts Payable control account — because the reconcile harness ties AP
 * out to vendor-bill balances, and inventory receipts have no vendor bill. Linking a
 * receipt to its vendor bill (Dr GRNI / Cr AP, clearing it) is a future enhancement.
 */
class InventoryMovementJournalizer implements Journalizer
{
    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var StockMovement $movement */
        $movement = $source;

        // Transfers move stock between warehouses within one inventory account — no
        // change to the company's inventory value, so no GL entry.
        if (in_array($movement->type, ['transfer_in', 'transfer_out'], true)) {
            return null;
        }

        $amount = round(abs((float) $movement->quantity) * (float) $movement->unit_cost, 2);
        if ($amount <= 0) {
            return null; // a zero-value movement (e.g. a note adjustment) has no GL effect
        }

        $assetId = $movement->warehouse?->asset_id;
        if (! $assetId) {
            return null;
        }

        [$debitRole, $creditRole] = $this->accountsFor($movement);
        if ($debitRole === null) {
            return null;
        }

        return [
            'entry_date' => $movement->moved_on,
            'description_en' => 'Stock '.$movement->type.' — '.($movement->item?->name ?? ''),
            'description_ar' => 'حركة مخزون — '.($movement->item?->name ?? ''),
            'asset_id' => $assetId,
            'lines' => [
                ['ledger_account_id' => $this->accounts->id($debitRole, $assetId), 'debit' => $amount, 'credit' => 0, 'asset_id' => $assetId],
                ['ledger_account_id' => $this->accounts->id($creditRole, $assetId), 'debit' => 0, 'credit' => $amount, 'asset_id' => $assetId],
            ],
        ];
    }

    /** @return array{0:?string,1:?string} [debitRole, creditRole] */
    private function accountsFor(StockMovement $movement): array
    {
        return match ($movement->type) {
            'receipt' => ['inventory', 'inventory_grni'],
            'consumption' => ['maintenance_expense', 'inventory'],
            'adjustment' => (float) $movement->quantity >= 0
                ? ['inventory', 'inventory_adjustment']   // found stock
                : ['inventory_adjustment', 'inventory'],  // shrinkage
            default => [null, null],
        };
    }
}
