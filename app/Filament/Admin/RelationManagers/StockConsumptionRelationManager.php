<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\TenantRequest;
use App\Models\Warehouse;
use App\Services\StockMovementService;
use App\Support\Modules;
use App\Support\TenantScope;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Materials consumed on a maintenance ticket (inventory module 22, Phase 2). Each
 * "Log consumed item" creates a `consumption` StockMovement linked to the request
 * via the polymorphic `source`, decrementing on-hand and capturing who/what — the
 * basis for per-ticket material cost (Phase 3 GL costing).
 */
class StockConsumptionRelationManager extends RelationManager
{
    protected static string $relationship = 'stockConsumptions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.inventory.consumption');
    }

    /** Only when the inventory module is on AND the user may view inventory. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Modules::enabled('inventory') && (auth()->user()?->can('inventory.view') ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['item', 'warehouse', 'movedBy']))
            ->columns([
                TextColumn::make('moved_on')
                    ->label(__('admin.inventory.fields.moved_on'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('item.name')
                    ->label(__('admin.inventory.fields.item'))
                    ->description(fn ($record) => $record->item?->sku)
                    ->weight('medium'),
                TextColumn::make('warehouse.name')
                    ->label(__('admin.inventory.fields.warehouse'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('quantity')
                    ->label(__('admin.inventory.fields.quantity'))
                    // Consumption is stored negative; show the magnitude used.
                    ->state(fn ($record) => abs((float) $record->quantity))
                    ->numeric(decimalPlaces: 3)
                    ->suffix(fn ($record) => ' ' . ($record->item?->unit ?? '')),
                TextColumn::make('value')
                    ->label(__('admin.inventory.fields.value'))
                    ->state(fn ($record) => $record->value())
                    ->money('EGP'),
                TextColumn::make('movedBy.name')
                    ->label(__('admin.inventory.fields.moved_by'))
                    ->placeholder('—'),
            ])
            ->headerActions([
                Action::make('consume')
                    ->label(__('admin.inventory.actions.consume'))
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('warning')
                    // Gate on inventory.create AND the request being editable (not terminal).
                    ->visible(fn (RelationManager $livewire) => (auth()->user()?->can('inventory.create') ?? false)
                        && MaintenanceRequestResource::canEdit($livewire->getOwnerRecord()))
                    ->authorize(fn () => auth()->user()?->can('inventory.create') ?? false)
                    ->schema([
                        Select::make('warehouse_id')
                            ->label(__('admin.inventory.fields.warehouse'))
                            ->options(fn (RelationManager $livewire) => $this->warehouseOptions($livewire->getOwnerRecord()))
                            ->required()
                            ->searchable()
                            ->native(false),
                        Select::make('inventory_item_id')
                            ->label(__('admin.inventory.fields.item'))
                            ->options(fn () => InventoryItem::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->required()
                            ->searchable()
                            ->native(false),
                        TextInput::make('quantity')
                            ->label(__('admin.inventory.fields.quantity'))
                            ->numeric()
                            ->minValue(0.001)
                            ->required(),
                        DatePicker::make('moved_on')
                            ->label(__('admin.inventory.fields.moved_on'))
                            ->default(now())
                            ->required()
                            ->native(false),
                        Textarea::make('notes')
                            ->label(__('admin.inventory.fields.notes'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->action(function (array $data, RelationManager $livewire): void {
                        /** @var TenantRequest $request */
                        $request = $livewire->getOwnerRecord();
                        // Server-side re-check (the authorize() closure can't see the record):
                        // no consumption may be logged against a terminal/uneditable ticket.
                        abort_unless(MaintenanceRequestResource::canEdit($request), 403);
                        $warehouse = $this->authorizedWarehouse($request, (int) $data['warehouse_id']);
                        $item = InventoryItem::findOrFail($data['inventory_item_id']);

                        app(StockMovementService::class)->record([
                            'warehouse_id' => $warehouse->id,
                            'inventory_item_id' => $item->id,
                            'type' => 'consumption',
                            'quantity' => (float) $data['quantity'],
                            'unit_cost' => (float) $item->unit_cost, // cost-out at standard cost
                            'source_type' => $request->getMorphClass(),
                            'source_id' => $request->getKey(),
                            'moved_on' => $data['moved_on'],
                            'notes' => $data['notes'] ?? null,
                        ]);

                        Notification::make()->title(__('admin.inventory.consumed'))->success()->send();
                    }),
            ])
            ->defaultSort('moved_on', 'desc');
    }

    /** Warehouses for the ticket's property (or the user's visible set if it has no unit). */
    private function warehouseOptions(TenantRequest $request): array
    {
        $query = Warehouse::query()->where('is_active', true);

        if ($assetId = $request->unit?->asset_id) {
            $query->where('asset_id', $assetId);
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
            $query->whereIn('asset_id', $ids);
        }

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Re-validate the submitted warehouse server-side (form-tamper guard). */
    private function authorizedWarehouse(TenantRequest $request, int $warehouseId): Warehouse
    {
        $warehouse = Warehouse::findOrFail($warehouseId);

        if ($assetId = $request->unit?->asset_id) {
            // The ticket's property is known — the warehouse must belong to it.
            if ((int) $warehouse->asset_id !== (int) $assetId) {
                abort(403);
            }
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null && ! in_array($warehouse->asset_id, $ids, true)) {
            abort(403);
        }

        return $warehouse;
    }
}
