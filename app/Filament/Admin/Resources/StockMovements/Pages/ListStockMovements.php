<?php

namespace App\Filament\Admin\Resources\StockMovements\Pages;

use App\Filament\Admin\Resources\StockMovements\StockMovementResource;
use App\Models\InventoryItem;
use App\Models\Warehouse;
use App\Services\StockMovementService;
use App\Support\ReportCsv;
use App\Support\StatusTabs;
use App\Support\TenantScope;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Set;

class ListStockMovements extends ListRecords
{
    protected static string $resource = StockMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->receiveAction(),
            $this->adjustAction(),
            // The full movement ledger as a spreadsheet — reconcile / audit the stock trail.
            Action::make('export_csv')
                ->label(__('admin.reports.csv.export'))
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn () => StockMovementResource::canViewAny())
                ->authorize(fn () => StockMovementResource::canViewAny())
                ->action(function () {
                    $csv = StockMovementResource::movementsCsv();

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
                app(StockMovementService::class)->record([
                    'warehouse_id' => $data['warehouse_id'],
                    'inventory_item_id' => $data['inventory_item_id'],
                    'type' => 'receipt',
                    'quantity' => (float) $data['quantity'],
                    'unit_cost' => (float) ($data['unit_cost'] ?? 0),
                    'reference' => $data['reference'] ?? null,
                    'moved_on' => $data['moved_on'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]);

                Notification::make()->title(__('admin.inventory.received'))->success()->send();
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
                    ->helperText('+ found / − shrinkage')
                    ->numeric()
                    ->required(),
                ...$this->metaFields(),
            ])
            ->action(function (array $data): void {
                $this->assertWarehouseVisible((int) $data['warehouse_id']);
                app(StockMovementService::class)->record([
                    'warehouse_id' => $data['warehouse_id'],
                    'inventory_item_id' => $data['inventory_item_id'],
                    'type' => 'adjustment',
                    'quantity' => (float) $data['quantity'],
                    'reference' => $data['reference'] ?? null,
                    'moved_on' => $data['moved_on'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]);

                Notification::make()->title(__('admin.inventory.adjusted'))->success()->send();
            });
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
            Select::make('warehouse_id')
                ->label(__('admin.inventory.fields.warehouse'))
                ->options(function () {
                    $query = Warehouse::query()->where('is_active', true);
                    if ($assetId = TenantScope::currentAssetId()) {
                        $query->where('asset_id', $assetId);
                    } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
                        $query->whereIn('asset_id', $ids);
                    }

                    return $query->orderBy('name')->pluck('name', 'id')->all();
                })
                ->required()
                ->searchable()
                ->native(false),
            Select::make('inventory_item_id')
                ->label(__('admin.inventory.fields.item'))
                ->options(fn () => InventoryItem::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->required()
                ->searchable()
                ->native(false)
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
