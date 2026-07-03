<?php

namespace App\Filament\Admin\Resources\InventoryItems;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\InventoryItems\Pages\CreateInventoryItem;
use App\Filament\Admin\Resources\InventoryItems\Pages\EditInventoryItem;
use App\Filament\Admin\Resources\InventoryItems\Pages\ListInventoryItems;
use App\Filament\Admin\Resources\InventoryItems\Schemas\InventoryItemForm;
use App\Filament\Admin\Resources\InventoryItems\Tables\InventoryItemsTable;
use App\Models\InventoryItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The item catalog — shared (global) reference data, so it is NOT property-scoped.
 * On-hand quantity is DERIVED per row (SUM of movements) via withSum. Gated by the
 * `inventory` module + `inventory.*` permissions.
 */
class InventoryItemResource extends Resource
{
    // Global catalog — not property-scoped; opt out of Filament's asset tenancy.
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = InventoryItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?int $navigationSort = 41;

    protected static ?string $recordTitleAttribute = 'name';

    protected static function permissionModule(): string
    {
        return 'inventory';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.inventory.item.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.inventory.item.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.inventory.item.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.inventory.group');
    }

    public static function form(Schema $schema): Schema
    {
        return InventoryItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InventoryItemsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventoryItems::route('/'),
            'create' => CreateInventoryItem::route('/create'),
            'edit' => EditInventoryItem::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Derived on-hand (across all warehouses) in one subquery — no per-row N+1.
        return parent::getEloquentQuery()->withSum('movements as on_hand', 'quantity');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['sku', 'name'];
    }
}
