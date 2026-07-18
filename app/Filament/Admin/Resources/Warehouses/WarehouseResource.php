<?php

namespace App\Filament\Admin\Resources\Warehouses;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Warehouses\Pages\CreateWarehouse;
use App\Filament\Admin\Resources\Warehouses\Pages\EditWarehouse;
use App\Filament\Admin\Resources\Warehouses\Pages\ListWarehouses;
use App\Filament\Admin\Resources\Warehouses\Schemas\WarehouseForm;
use App\Filament\Admin\Resources\Warehouses\Tables\WarehousesTable;
use App\Models\Warehouse;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stock locations, scoped to the current property (direct asset_id, like Unit /
 * UtilityMeter). Gated by the `inventory` module + `inventory.*` permissions.
 */
class WarehouseResource extends Resource
{
    // NOT Filament auto-tenancy: asset_id is CLIENT-supplied (the operator picks the mall, and that
    // Select is enabled in All-Properties mode). Filament's ownership `creating` hook would force
    // asset_id to the current tenant — and in All-mode the tenant is the ALL pseudo-asset, silently
    // clobbering the chosen mall (the "Announcements tenancy trap"). BypassesFilamentTenantAutoScope
    // turns that hook off; reads are scoped in getEloquentQuery() below and the submitted asset_id is
    // re-validated by assertAssetInScope() on create + edit.
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = Warehouse::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'name';

    protected static function permissionModule(): string
    {
        return 'inventory';
    }

    /** Property-scope the list ourselves (Filament auto-tenancy is off — see the trait note above). */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if ($assetId = TenantScope::currentAssetId()) {
            $query->where('asset_id', $assetId);
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
            // All-Properties mode: a restricted user still sees only their own malls.
            $query->whereIn('asset_id', $ids);
        }

        return $query;
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
        return __('admin.inventory.group');
    }

    public static function form(Schema $schema): Schema
    {
        return WarehouseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WarehousesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWarehouses::route('/'),
            'create' => CreateWarehouse::route('/create'),
            'edit' => EditWarehouse::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'code'];
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
