<?php

namespace App\Filament\Admin\Resources\UtilityMeters;

use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\UtilityMeters\Pages\CreateUtilityMeter;
use App\Filament\Admin\Resources\UtilityMeters\Pages\EditUtilityMeter;
use App\Filament\Admin\Resources\UtilityMeters\Pages\ListUtilityMeters;
use App\Filament\Admin\Resources\UtilityMeters\Schemas\UtilityMeterForm;
use App\Filament\Admin\Resources\UtilityMeters\Tables\UtilityMetersTable;
use App\Models\UtilityMeter;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class UtilityMeterResource extends Resource
{
    use RoleGatedActions;

    protected static ?string $model = UtilityMeter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?int $navigationSort = 8;

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.energy');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.utility_meter.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.utility_meter.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.operations');
    }

    public static function form(Schema $schema): Schema
    {
        return UtilityMeterForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UtilityMetersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUtilityMeters::route('/'),
            'create' => CreateUtilityMeter::route('/create'),
            'edit' => EditUtilityMeter::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['asset', 'unit']);

        $ids = \App\Support\AssignedAssets::idsForCurrentUser();
        if ($ids !== null) {
            $query->whereIn('utility_meters.asset_id', $ids);
        }

        return $query;
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
