<?php

namespace App\Filament\Admin\Resources\Assets;

use App\Filament\Admin\Resources\Assets\Pages\CreateAsset;
use App\Filament\Admin\Resources\Assets\Pages\EditAsset;
use App\Filament\Admin\Resources\Assets\Pages\ListAssets;
use App\Filament\Admin\Resources\Assets\Schemas\AssetForm;
use App\Filament\Admin\Resources\Assets\Tables\AssetsTable;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Models\Asset;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AssetResource extends Resource
{
    use RoleGatedActions;

    protected static ?string $model = Asset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    // Asset IS the tenant — managing assets sits ABOVE the per-property
    // context, so it bypasses tenancy. The query below still scopes to
    // properties the user is assigned to.
    protected static bool $isScopedToTenant = false;

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.properties');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.asset.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.asset.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.operations');
    }

    public static function form(Schema $schema): Schema
    {
        return AssetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AssetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Admin\RelationManagers\AssetUnitsRelationManager::class,
            \App\Filament\Admin\RelationManagers\AssetStaffRelationManager::class,
            \App\Filament\Admin\RelationManagers\ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssets::route('/'),
            'create' => CreateAsset::route('/create'),
            'edit' => EditAsset::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            // Always hide the synthetic "All Properties" pseudo-asset from
            // the property management list — it's a tenant-switcher artifact,
            // not a real property.
            ->where('assets.code', '!=', Asset::ALL_PROPERTIES_CODE);

        // When inside a specific property context (not the All Properties
        // view), restrict the list to that property only — the operator
        // can edit only the property they currently have active.
        if ($assetId = \App\Support\TenantScope::currentAssetId()) {
            $query->where('assets.id', $assetId);

            return $query;
        }

        // All Properties view (or no tenant): fall back to the
        // user's assigned set.
        $ids = \App\Support\AssignedAssets::idsForCurrentUser();
        if ($ids !== null) {
            $query->whereIn('assets.id', $ids);
        }

        return $query;
    }

    /**
     * Creating a new property is only allowed from the "All Properties"
     * view (or when no tenant is set). Inside a specific property's
     * context, the user can only edit that property.
     */
    public static function canCreate(): bool
    {
        if (\App\Support\TenantScope::currentAssetId() !== null) {
            return false;
        }

        return static::hasPermission('create');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'code', 'city'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            __('admin.tables.asset.code') => $record->code,
            __('admin.tables.asset.city') => $record->city,
            __('admin.tables.asset.type') => __("admin.enums.asset_type.{$record->type}"),
        ];
    }
}
