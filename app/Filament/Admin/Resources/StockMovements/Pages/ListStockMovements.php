<?php

namespace App\Filament\Admin\Resources\StockMovements\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\StockMovements\StockMovementResource;
use App\Models\Bin;
use App\Models\InventoryItem;
use App\Models\Warehouse;
use App\Services\StockMovementService;
use App\Support\Filament\EntitySelect;
use App\Support\ReportCsv;
use App\Support\StatusTabs;
use App\Support\TenantScope;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ListStockMovements extends ListRecords
{
    // Three filters is the threshold `SavedViews` sets for a list worth bookmarking, and the
    // ledger crossed it the day it grew a date range, a store and an item (SW-198).
    // `SavedViewsCoverageConformanceTest` fails in BOTH directions, so this is not optional.
    use SavesTableViews;

    protected static string $resource = StockMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
            $this->receiveAction(),
            $this->adjustAction(),
            $this->transferAction(),
            // The full movement ledger as a spreadsheet — reconcile / audit the stock trail.
            Action::make('export_csv')
                ->label(__('admin.reports.csv.export'))
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn () => StockMovementResource::canViewAny())
                ->authorize(fn () => StockMovementResource::canViewAny())
                ->action(function () {
                    // The ledger the operator is LOOKING AT, not the whole register (SW-198).
                    // Exporting everything while the screen shows one store is the worst kind of
                    // wrong: the file is perfectly valid and answers a different question.
                    $csv = StockMovementResource::movementsCsv($this->getFilteredTableQuery());

                    return ReportCsv::stream('stock-movements', $csv['headers'], $csv['rows']);
                }),
        ];
    }

    private function receiveAction(): Action
    {
        return Action::make('receive')
            ->label(__('admin.inventory.actions.receive'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('success')
            ->visible(fn () => StockMovementResource::canCreate())
            ->authorize(fn () => StockMovementResource::canCreate())
            ->schema([
                ...$this->movementFields(),
                TextInput::make('quantity')
                    ->label(__('admin.inventory.fields.quantity'))
                    ->numeric()
                    ->minValue(0.001)
                    ->required(),
                // Required + prefilled from the item — a 0-cost receipt would add stock
                // but post nothing to the GL (Inventory + GRNI would silently stay flat).
                TextInput::make('unit_cost')
                    ->label(__('admin.inventory.fields.unit_cost'))
                    ->numeric()
                    ->minValue(0.01)
                    ->required()
                    ->prefix('EGP'),
                ...$this->metaFields(),
            ])
            ->action(function (array $data): void {
                $this->assertWarehouseVisible((int) $data['warehouse_id']);

                $this->runMovement(
                    fn () => app(StockMovementService::class)->record([
                        'warehouse_id' => $data['warehouse_id'],
                        'bin_id' => $data['bin_id'] ?? null,
                        'inventory_item_id' => $data['inventory_item_id'],
                        'type' => 'receipt',
                        'quantity' => (float) $data['quantity'],
                        'unit_cost' => (float) ($data['unit_cost'] ?? 0),
                        'reference' => $data['reference'] ?? null,
                        'moved_on' => $data['moved_on'] ?? null,
                        'notes' => $data['notes'] ?? null,
                    ]),
                    __('admin.inventory.movement_failed'),
                    fn () => Notification::make()->title(__('admin.inventory.received'))->success()->send(),
                );
            });
    }

    private function adjustAction(): Action
    {
        return Action::make('adjust')
            ->label(__('admin.inventory.actions.adjust'))
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('warning')
            ->visible(fn () => StockMovementResource::canCreate())
            ->authorize(fn () => StockMovementResource::canCreate())
            ->schema([
                ...$this->movementFields(),
                TextInput::make('quantity')
                    ->label(__('admin.inventory.fields.quantity'))
                    ->helperText(__('admin.placeholders.stock_adjust_sign'))
                    ->numeric()
                    ->required(),
                ...$this->metaFields(),
            ])
            ->action(function (array $data): void {
                $this->assertWarehouseVisible((int) $data['warehouse_id']);

                $this->runMovement(
                    fn () => app(StockMovementService::class)->record([
                        'warehouse_id' => $data['warehouse_id'],
                        'bin_id' => $data['bin_id'] ?? null,
                        'inventory_item_id' => $data['inventory_item_id'],
                        'type' => 'adjustment',
                        'quantity' => (float) $data['quantity'],
                        'reference' => $data['reference'] ?? null,
                        'moved_on' => $data['moved_on'] ?? null,
                        'notes' => $data['notes'] ?? null,
                    ]),
                    __('admin.inventory.movement_failed'),
                    fn () => Notification::make()->title(__('admin.inventory.adjusted'))->success()->send(),
                );
            });
    }

    /**
     * Move stock between two stores in the same property.
     *
     * The ledger already had `transfer_in`/`transfer_out` types, a journalizer branch
     * and a Transfers tab — but nothing anywhere could create one, so that tab was
     * permanently empty and a storeman moving a part between the main store and a
     * sub-store had to fake it as a shrinkage plus a receipt (which posts a GL entry
     * for value that never left the company).
     */
    private function transferAction(): Action
    {
        return Action::make('transfer')
            ->label(__('admin.inventory.transfer.action'))
            ->icon('heroicon-o-arrows-right-left')
            ->color('info')
            ->modalDescription(__('admin.inventory.transfer.hint'))
            ->visible(fn () => StockMovementResource::canCreate())
            ->authorize(fn () => StockMovementResource::canCreate())
            ->schema([
                // The shared record picker, like the item picker below it (SW-196). These were
                // plain Selects fed by a private `warehouseOptions()` helper — which is exactly
                // why `EntitySelectConformanceTest` never saw them: it reads each chain's own
                // text for a `pluck('name', 'id')`, and the pluck was twenty lines further down
                // the file. They searched one raw column, folded neither side, showed one line per
                // option and re-wrote the property scope by hand; every one of those failures
                // renders as an empty or ambiguous dropdown rather than an error, which is why
                // nobody reports it. The scope is DERIVED from `PropertyIsolation` now, and it is
                // the write guard as well as the filter.
                EntitySelect::make('from_warehouse_id')
                    ->label(__('admin.inventory.transfer.from'))
                    ->entity(Warehouse::class)
                    ->modifyOptionsQuery(fn ($query) => $query->where('is_active', true))
                    ->required()
                    ->live(),
                EntitySelect::make('to_warehouse_id')
                    ->label(__('admin.inventory.transfer.to'))
                    ->entity(Warehouse::class)
                    // A transfer to the same store is a no-op that would still write two ledger
                    // rows; take the option away rather than explain it afterwards. Narrowing the
                    // OPTIONS is also what REFUSES it — Filament validates a Select by asking it
                    // to label the submitted value, so a store this picker will not offer is a
                    // store it will not accept.
                    ->modifyOptionsQuery(fn ($query, Get $get) => $query
                        ->where('is_active', true)
                        ->when(
                            filled($get('from_warehouse_id')),
                            fn ($scoped) => $scoped->whereKeyNot($get('from_warehouse_id')),
                        ))
                    ->required(),
                EntitySelect::make('inventory_item_id')
                    ->label(__('admin.inventory.fields.item'))
                    ->entity(InventoryItem::class)
                    ->modifyOptionsQuery(fn ($query) => $query->where('is_active', true))
                    ->required(),
                TextInput::make('quantity')
                    ->label(__('admin.inventory.fields.quantity'))
                    ->numeric()
                    ->minValue(0.001)
                    ->required(),
                ...$this->metaFields(),
            ])
            ->action(function (array $data): void {
                // Both ends re-validated server-side: the pickers are scoped, but a crafted
                // request must not be able to move stock into — or out of — a property the
                // user cannot see.
                $this->assertWarehouseVisible((int) $data['from_warehouse_id']);
                $this->assertWarehouseVisible((int) $data['to_warehouse_id']);

                $from = Warehouse::findOrFail($data['from_warehouse_id']);
                $to = Warehouse::findOrFail($data['to_warehouse_id']);
                $item = InventoryItem::findOrFail($data['inventory_item_id']);

                $this->runMovement(
                    fn () => app(StockMovementService::class)->transfer($from, $to, $item, (float) $data['quantity'], [
                        'reference' => $data['reference'] ?? null,
                        'moved_on' => $data['moved_on'] ?? null,
                        'notes' => $data['notes'] ?? null,
                    ]),
                    __('admin.inventory.transfer.failed'),
                    fn () => Notification::make()
                        ->title(__('admin.inventory.transfer.done'))
                        ->body(__('admin.inventory.transfer.done_body', [
                            'quantity' => rtrim(rtrim(number_format((float) $data['quantity'], 3), '0'), '.'),
                            'unit' => $item->unit ?? '',
                            'item' => $item->name,
                            'from' => $from->name,
                            'to' => $to->name,
                        ]))
                        ->success()
                        ->send(),
                );
            });
    }

    /**
     * Run a ledger write and turn a refusal into a toast.
     *
     * The service refuses real, reachable things — not enough stock on hand, a closed
     * accounting period, a cross-property transfer, an item with no cost. Uncaught,
     * each of those replaced the operator's screen with a raw 422/500 error page and
     * lost whatever they had typed, which reads as "the app broke" rather than "that
     * move is not allowed, here is why".
     */
    private function runMovement(callable $write, string $failureTitle, callable $onSuccess): void
    {
        try {
            $write();
        } catch (HttpException $e) {
            // abort_unless(…, 422) from the overdraw floor: the status carries the meaning,
            // the exception has no message worth showing.
            Notification::make()
                ->title($failureTitle)
                ->body($e->getStatusCode() === 422
                    ? __('admin.inventory.insufficient_stock')
                    : $e->getMessage())
                ->danger()
                ->send();

            return;
        } catch (DomainException $e) {
            // These carry an explanation written for the operator — show it.
            //
            // `DomainException` ONLY, since SW-197. The service used to raise its refusals as
            // `InvalidArgumentException`, which put a raw English sentence in this toast AND put
            // them outside `RefusalsAreTranslatedConformanceTest`, whose stated premise is that an
            // `InvalidArgumentException` is a developer error nobody is meant to read. Narrowing
            // the catch is what keeps that premise true here: a NEW one fails loudly instead of
            // being quietly shown to a storeman in the wrong language.
            Notification::make()
                ->title($failureTitle)
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $onSuccess();
    }

    /**
     * Defense-in-depth against form tampering: the warehouse picker is scoped, but
     * the submitted id must be re-validated server-side so a crafted request can't
     * move stock into a property the user cannot see (null = unrestricted).
     */
    private function assertWarehouseVisible(int $warehouseId): void
    {
        $warehouse = Warehouse::findOrFail($warehouseId);
        $visibleAssetIds = TenantScope::visibleAssetIds();

        if ($visibleAssetIds !== null && ! in_array($warehouse->asset_id, $visibleAssetIds, true)) {
            abort(403);
        }
    }

    /** Warehouse + item pickers, warehouse scoped to the user's visible properties. */
    private function movementFields(): array
    {
        return [
            EntitySelect::make('warehouse_id')
                ->label(__('admin.inventory.fields.warehouse'))
                ->entity(Warehouse::class)
                // The visible-properties half is OptionDisplay's; narrowing to the SELECTED mall
                // stays, because that is a working preference rather than isolation.
                ->modifyOptionsQuery(fn ($query) => $query
                    ->where('is_active', true)
                    ->when(TenantScope::currentAssetId(), fn ($q, $assetId) => $q->where('asset_id', $assetId)))
                ->required()
                // Changing the storeroom invalidates the shelf: bin A-01 in one warehouse is not
                // bin A-01 in another, so a stale selection would file the stock on a shelf in the
                // wrong building.
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('bin_id', null)),
            // Optional, and it stays optional: an operator who does not rack their storeroom pays
            // nothing for bins. Scoped to the warehouse chosen ABOVE — a portfolio-wide bin list
            // would offer shelves that do not exist in the building being counted.
            EntitySelect::make('bin_id')
                ->label(__('admin.inventory.fields.bin'))
                ->entity(Bin::class)
                ->modifyOptionsQuery(fn ($query, Get $get) => $query
                    ->where('is_active', true)
                    // No warehouse chosen yet → no bins, rather than every bin in the portfolio.
                    ->where('warehouse_id', $get('warehouse_id') ?? 0))
                ->helperText(__('admin.inventory.fields.bin_helper')),
            EntitySelect::make('inventory_item_id')
                ->label(__('admin.inventory.fields.item'))
                ->entity(InventoryItem::class)
                ->modifyOptionsQuery(fn ($query) => $query->where('is_active', true))
                ->required()
                ->live()
                // Prefill the receipt cost from the item's standard cost (editable).
                ->afterStateUpdated(fn (Set $set, $state) => $set('unit_cost', (float) (InventoryItem::find($state)?->unit_cost ?? 0))),
        ];
    }

    private function metaFields(): array
    {
        return [
            TextInput::make('reference')
                ->label(__('admin.inventory.fields.reference'))
                ->maxLength(255),
            DatePicker::make('moved_on')
                ->label(__('admin.inventory.fields.moved_on'))
                ->default(now())
                ->required()
                ->native(false),
            Textarea::make('notes')
                ->label(__('admin.inventory.fields.notes'))
                ->rows(2)
                ->columnSpanFull(),
        ];
    }

    /** Stock ledger split by what the movement did. Tabs on `type`, not `status`. */
    public function getTabs(): array
    {
        return StatusTabs::build(StockMovementResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'receipt' => ['label' => __('admin.inventory.types.receipt'), 'statuses' => ['receipt']],
            'consumption' => ['label' => __('admin.inventory.types.consumption'), 'statuses' => ['consumption']],
            'adjustment' => ['label' => __('admin.inventory.types.adjustment'), 'statuses' => ['adjustment']],
            'transfer' => ['label' => __('admin.inventory.transfers'), 'statuses' => ['transfer_in', 'transfer_out']],
        ], column: 'type');
    }
}
