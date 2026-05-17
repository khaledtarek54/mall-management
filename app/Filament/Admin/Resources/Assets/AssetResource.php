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
