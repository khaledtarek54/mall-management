<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\InventoryItem;
use App\Models\LowStockAlert;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Notifications\LowStockNotification;
use App\Services\AssetStaffRecipients;
use App\Services\DraftReorderPurchaseService;
use App\Support\Modules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Alert each mall about the parts IT is running out of (FR-INV-03).
 *
 * **Per property, never portfolio-wide.** FR-INV-01 tracks stock "per mall/location", and the
 * catalog is a SHARED global register — so "are we low on filters?" is only answerable one mall at
 * a time. A portfolio sum would stay silent about the mall that is actually out while another mall
 * sits on a pile, which is exactly the bug this scan's own on-hand column had until it was fixed.
 *
 * Idempotent + lock-safe like every other scan here: the (item, property) alert row is locked and
 * its stamp re-checked INSIDE the transaction, so two overlapping scans cannot both notify. It
 * fires once per shortage, not once per run — and again after a restock-then-dip, because that is
 * a new shortage.
 */
class ScanLowStockCommand extends Command
{
    protected $signature = 'inventory:scan-low-stock
        {--dry-run : Print what would be alerted without writing}
        {--no-draft : Alert only; do not draft a purchase request from the open shortages}';

    protected $description = 'Alert each property about items at or below their reorder level (idempotent).';

    public function handle(AssetStaffRecipients $recipients): int
    {
        if (! Modules::enabled('inventory')) {
            $this->info('Inventory module is off — nothing to scan.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $opened = 0;
        $resolved = 0;
        $failures = 0;

        // Only items with a threshold set. A reorder level of 0 means "we do not track a minimum
        // for this", not "alert whenever it hits zero" — otherwise every item a mall has never
        // stocked would alert forever. Read through the model's own predicate: until SW-195 the
        // list's red on-hand figure and its low-stock filter each wrote this rule out for
        // themselves, and both wrote it the other way round.
        $items = InventoryItem::query()->active()->tracksAReorderLevel()->get();

        if ($items->isEmpty()) {
            $this->info('No items have a reorder level set.');

            return self::SUCCESS;
        }

        // Real properties only — the All-Properties pseudo-asset owns no warehouse.
        $assets = Asset::query()->where('code', '!=', Asset::ALL_PROPERTIES_CODE)->get();

        foreach ($assets as $asset) {
            $warehouseIds = Warehouse::query()->where('asset_id', $asset->id)->pluck('id');

            if ($warehouseIds->isEmpty()) {
                continue; // a property with no storeroom cannot be short of anything
            }

            foreach ($items as $item) {
                try {
                    $onHand = (float) StockMovement::query()
                        ->where('inventory_item_id', $item->id)
                        ->whereIn('warehouse_id', $warehouseIds)
                        ->sum('quantity');

                    $isLow = $item->isLowAt($onHand);

                    if ($dryRun) {
                        if ($isLow) {
                            $this->line("would alert: {$asset->code} / {$item->sku} — {$onHand} <= {$item->reorder_level}");
                        }

                        continue;
                    }

                    $result = DB::transaction(function () use ($item, $asset, $onHand, $isLow, $recipients) {
                        /** @var LowStockAlert|null $alert */
                        $alert = LowStockAlert::query()
                            ->where('inventory_item_id', $item->id)
                            ->where('asset_id', $asset->id)
                            ->lockForUpdate()
                            ->first();

                        if (! $isLow) {
                            // Stock came back. Close the alert so a later dip can fire again.
                            if ($alert && $alert->isOpen()) {
                                $alert->forceFill(['resolved_at' => now(), 'on_hand' => $onHand])->save();

                                return 'resolved';
                            }

                            return null;
                        }

                        // Already alerted and nobody has fixed it — say nothing. The stamp is
                        // re-checked here, inside the lock, so a concurrent scan cannot double-fire.
                        if ($alert && $alert->isOpen()) {
                            return null;
                        }

                        if ($alert) {
                            // Re-opening a resolved alert: the shortage came back, so this row
                            // fires again. (Assigning `tap($alert)->forceFill(...)->save()` here
                            // would assign save()'s BOOLEAN, not the model — the re-alert path
                            // then died on `true->fresh()`. Caught by the restock-then-dip test.)
                            $alert->forceFill([
                                'on_hand' => $onHand,
                                'reorder_level' => $item->reorder_level,
                                'notified_at' => now(),
                                'resolved_at' => null,
                            ])->save();
                        } else {
                            $alert = LowStockAlert::create([
                                'inventory_item_id' => $item->id,
                                'asset_id' => $asset->id,
                                'on_hand' => $onHand,
                                'reorder_level' => $item->reorder_level,
                                'notified_at' => now(),
                            ]);
                        }

                        // After the commit, never under the lock (SW-213). `LowStockNotification`
                        // is `['database']` today, but which channels a notification uses is not a
                        // property to build a lock's duration on — `AlsoSendsByMail` was added to
                        // fourteen of them after they were written. `$alert` is captured by value
                        // after it has been created or re-opened above, so the closure re-reads the
                        // committed row.
                        DB::afterCommit(function () use ($recipients, $asset, $alert) {
                            $staff = $recipients->for($asset->id, ['manager', 'operations']);

                            if ($staff->isNotEmpty()) {
                                Notification::send($staff, new LowStockNotification($alert->refresh()));
                            }
                        });

                        return 'opened';
                    });

                    if ($result === 'opened') {
                        $opened++;
                    } elseif ($result === 'resolved') {
                        $resolved++;
                    }
                } catch (Throwable $e) {
                    // One bad pair must not strand the rest of the sweep.
                    $failures++;
                    report($e);
                    $this->error("Failed on {$asset->code} / {$item->sku}: {$e->getMessage()}");
                }
            }
        }

        $this->info($dryRun
            ? 'Dry run complete.'
            : "Low-stock scan complete: {$opened} alerted, {$resolved} resolved, {$failures} failed.");

        // Close the loop the alert used to leave open: turn the shortages into a DRAFT purchase
        // request per property. A draft, never a submission — the system may do the typing, it may
        // not create an obligation, and a request that enters the ladder by itself would have its
        // approval tier chosen by a value nobody entered.
        //
        // After the alerts are written, because it reads the open ones. Skipped on a dry run for
        // the reason F-96 exists: a preview that writes rows is not a preview.
        if (! $dryRun && ! $this->option('no-draft')) {
            $draft = app(DraftReorderPurchaseService::class)->run();

            $this->info("Reorder drafts: {$draft['drafted']} raised, {$draft['refreshed']} refreshed"
                .($draft['skipped'] > 0 ? ", {$draft['skipped']} skipped (no warehouse)" : '').'.');
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
