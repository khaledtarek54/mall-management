<?php

namespace App\Filament\Admin\Resources\StockMovements;

use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
use App\Filament\Admin\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Admin\Resources\StockMovements\Tables\StockMovementsTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\StockMovement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The stock ledger — append-only (no create/edit pages; stock changes go through
 * the Receive / Adjust actions on the list, which call StockMovementService so the
 * sign is always correct). Scoped to the current property via warehouse.asset_id.
 */
class StockMovementResource extends Resource
{
    // Scopes manually via warehouse.asset_id in getEloquentQuery — opt out of
    // Filament's auto tenancy (StockMovement has no direct `asset` relationship).
    use RoleGatedActions;
    use ScopesToProperty;
    use SearchesNormalizedText;

    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsUpDown;

    /**
     * By source-document reference, or by the item or store it moved.
     *
     * Every path ends in `search_text` on purpose — see
     * App\Filament\Concerns\SearchesNormalizedText.
     *
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'search_text',
            'item.search_text',
            'warehouse.search_text',
        ];
    }

    protected static function permissionModule(): string
    {
        return 'inventory';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.inventory.movement.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.inventory.movement.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.inventory.movement.plural');
    }

    public static function table(Table $table): Table
    {
        return StockMovementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockMovements::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // The `warehouse` hop is StockMovement's own #[PropertyOwned(via: 'warehouse')] — declared
        // on the model, so this resource no longer names the relation a second time.
        return static::scopeToProperty(
            parent::getEloquentQuery()->with(['warehouse', 'item', 'movedBy'])
        );
    }

    /**
     * The stock movement ledger as CSV rows — the append-only audit trail of every receipt,
     * consumption and adjustment, in the accountant's working format. Reads the same
     * property-scoped query the table shows so the export can never disagree with the screen.
     *
     * @return array{headers: array<int,string>, rows: array<int, array<int, string|float>>}
     */
    public static function movementsCsv(): array
    {
        $rows = [];

        /** @var StockMovement $movement */
        foreach (static::getEloquentQuery()->latest('moved_on')->latest('id')->get() as $movement) {
            $rows[] = [
                // moved_on is a NOT-NULL date column — always a Carbon, no nullsafe needed.
                $movement->moved_on->format('Y-m-d'),
                (string) data_get($movement, 'warehouse.name', ''),
                (string) data_get($movement, 'item.name', ''),
                __('admin.inventory.types.'.$movement->type),
                round((float) $movement->quantity, 3),
                (string) ($movement->reference ?? ''),
                (string) data_get($movement, 'movedBy.name', ''),
            ];
        }

        return [
            'headers' => [
                __('admin.inventory.fields.moved_on'), __('admin.inventory.fields.warehouse'),
                __('admin.inventory.fields.item'), __('admin.inventory.fields.type'),
                __('admin.inventory.fields.quantity'), __('admin.inventory.fields.reference'),
                __('admin.inventory.fields.moved_by'),
            ],
            'rows' => $rows,
        ];
    }
}
