<?php

namespace App\Filament\Admin\Resources\Warehouses;

use App\Filament\Admin\RelationManagers\StockMovementsRelationManager;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
use App\Filament\Admin\Resources\Warehouses\Pages\CreateWarehouse;
use App\Filament\Admin\Resources\Warehouses\Pages\EditWarehouse;
use App\Filament\Admin\Resources\Warehouses\Pages\ListWarehouses;
use App\Filament\Admin\Resources\Warehouses\Schemas\WarehouseForm;
use App\Filament\Admin\Resources\Warehouses\Tables\WarehousesTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\Warehouse;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Stock locations, scoped to the current property (direct asset_id, like Unit /
 * UtilityMeter). Gated by the `inventory` module + `inventory.*` permissions.
 */
class WarehouseResource extends Resource
{
    // NOT Filament auto-tenancy: asset_id is CLIENT-supplied (the operator picks the mall, and that
    // Select is enabled in All-Properties mode). Filament's ownership `creating` hook would force
    // asset_id to the current tenant — and in All-mode the tenant is the ALL pseudo-asset, silently
    // clobbering the chosen mall (the "Announcements tenancy trap"). ScopesToProperty
    // turns that hook off AND scopes reads from the model's own #[PropertyOwned]; the submitted asset_id is
    // re-validated by assertAssetInScope() on create + edit.
    use RoleGatedActions;
    use ScopesToProperty;
    use SearchesNormalizedText;

    protected static ?string $model = Warehouse::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    protected static function permissionModule(): string
    {
        return 'inventory';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.inventory.warehouse.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.inventory.warehouse.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.inventory.warehouse.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.inventory_assets');
    }

    public static function form(Schema $schema): Schema
    {
        return WarehouseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WarehousesTable::configure($table);
    }

    /**
     * What actually moved. On-hand is DERIVED from these rows, so a stock figure the operator
     * doubts is only explicable by reading them — and they were reachable solely from the movements
     * register, filtered by hand.
     */
    public static function getRelations(): array
    {
        return [
            StockMovementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWarehouses::route('/'),
            'create' => CreateWarehouse::route('/create'),
            'edit' => EditWarehouse::route('/{record}/edit'),
        ];
    }

    /**
     * Searched through the fold-normalized blob, never a raw column.
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
        ];
    }

    /**
     * Server-side guard against a tampered `asset_id` on create/edit — in
     * "All Properties" mode the Select is enabled and its value is client-supplied,
     * so re-validate that the target property is within the user's visible set
     * (null = portfolio user, sees all). Matches the other property-scoped resources.
     */
    public static function assertAssetInScope(mixed $assetId): void
    {
        $visible = TenantScope::visibleAssetIds();
        if ($visible !== null && ! in_array((int) $assetId, $visible, true)) {
            abort(403);
        }
    }
}
