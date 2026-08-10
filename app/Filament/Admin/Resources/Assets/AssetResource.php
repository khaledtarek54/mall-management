<?php

namespace App\Filament\Admin\Resources\Assets;

use App\Filament\Admin\Resources\Assets\Pages\CreateAsset;
use App\Filament\Admin\Resources\Assets\Pages\EditAsset;
use App\Filament\Admin\Resources\Assets\Pages\ListAssets;
use App\Filament\Admin\Resources\Assets\Schemas\AssetForm;
use App\Filament\Admin\Resources\Assets\Tables\AssetsTable;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Concerns\SearchesNormalizedText;
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
    use SearchesNormalizedText;

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
        return __('admin.groups.leasing');
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
            \App\Filament\Admin\RelationManagers\AssetFloorsRelationManager::class,
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

        // The Properties resource sits ABOVE the per-property context (it
        // manages the tenants themselves), so it lists the user's whole
        // portfolio — their assigned set, or every real property for
        // super_admin — regardless of which mall is currently active. This is
        // what lets a super_admin manage/edit any property, and keeps a
        // newly-created property visible/editable (a new mall is never the
        // active tenant). Before the "All Properties" removal this was
        // restricted to `currentAssetId()`; property-first drops that.
        $ids = \App\Support\AssignedAssets::idsForCurrentUser();
        if ($ids !== null) {
            $query->whereIn('assets.id', $ids);
        }

        return $query;
    }

    /**
     * Creating a new property is a portfolio-level action — the Properties
     * resource sits ABOVE the per-property context, so it follows the
     * `assets.create` permission alone. It used to be gated to the "All
     * Properties" view (`currentAssetId() === null`); with that view removed the
     * operator always works inside one mall, so that gate would make onboarding
     * a new mall impossible. A new Asset is not tenant-scoped
     * (`$isScopedToTenant = false`), so it is created standalone regardless of
     * the active mall.
     */
    public static function canCreate(): bool
    {
        return static::hasPermission('create');
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

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            __('admin.tables.asset.code') => $record->code,
            __('admin.tables.asset.city') => $record->city,
            __('admin.tables.asset.type') => __("admin.enums.asset_type.{$record->type}"),
        ];
    }
}
