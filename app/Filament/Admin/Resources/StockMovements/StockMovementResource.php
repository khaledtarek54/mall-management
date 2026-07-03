<?php

namespace App\Filament\Admin\Resources\StockMovements;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Admin\Resources\StockMovements\Tables\StockMovementsTable;
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
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsUpDown;

    protected static ?int $navigationSort = 42;

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

    public static function getNavigationGroup(): ?string
    {
        return __('admin.inventory.group');
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
        $query = parent::getEloquentQuery()->with(['warehouse', 'item', 'movedBy']);

        if ($assetId = \App\Support\TenantScope::currentAssetId()) {
            $query->whereHas('warehouse', fn ($q) => $q->where('asset_id', $assetId));
        } elseif (($ids = \App\Support\TenantScope::visibleAssetIds()) !== null) {
            $query->whereHas('warehouse', fn ($q) => $q->whereIn('asset_id', $ids));
        }

        return $query;
    }
}
