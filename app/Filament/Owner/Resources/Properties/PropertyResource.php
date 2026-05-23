<?php

namespace App\Filament\Owner\Resources\Properties;

use App\Filament\Owner\Resources\Properties\Pages\ListProperties;
use App\Filament\Owner\Resources\Properties\Pages\ViewProperty;
use App\Filament\Owner\Resources\Properties\Schemas\PropertyInfolist;
use App\Filament\Owner\Resources\Properties\Tables\PropertiesTable;
use App\Models\Asset;
use App\Models\Scopes\CurrentOperatorScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class PropertyResource extends Resource
{
    protected static ?string $model = Asset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 1;

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
        return __('admin.groups.portfolio');
    }

    public static function infolist(Schema $schema): Schema
    {
        return PropertyInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PropertiesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProperties::route('/'),
            'view' => ViewProperty::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $userId = Auth::id();

        return parent::getEloquentQuery()
            ->withoutGlobalScopes([CurrentOperatorScope::class])
            ->whereHas('owners', fn ($q) => $q->where('user_id', $userId))
            ->withCount('units');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
