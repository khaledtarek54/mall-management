<?php

namespace App\Filament\Admin\Resources\Equipment;

use App\Filament\Admin\Resources\Concerns\BypassesScopingOnAll;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Equipment\Pages\CreateEquipment;
use App\Filament\Admin\Resources\Equipment\Pages\EditEquipment;
use App\Filament\Admin\Resources\Equipment\Pages\ListEquipment;
use App\Filament\Admin\Resources\Equipment\Schemas\EquipmentForm;
use App\Filament\Admin\Resources\Equipment\Tables\EquipmentTable;
use App\Models\Equipment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The maintainable-asset register (FR-PPM-03/04/05) — scoped to the current property
 * (direct asset_id, like Unit / Warehouse). Part of module 26, so it is gated by the
 * `preventive_maintenance` module flag + `preventive_maintenance.*` permissions: the
 * engineers who service the machines are the people who maintain this list.
 */
class EquipmentResource extends Resource
{
    use BypassesScopingOnAll;
    use GuardsAssetInScope;
    use RoleGatedActions;

    protected static ?string $model = Equipment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 28;

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?string $tenantOwnershipRelationshipName = 'asset';

    protected static function permissionModule(): string
    {
        return 'preventive_maintenance';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.preventive_maintenance.equipment.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.preventive_maintenance.equipment.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.preventive_maintenance.equipment.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.preventive_maintenance.group');
    }

    public static function form(Schema $schema): Schema
    {
        return EquipmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EquipmentTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEquipment::route('/'),
            'create' => CreateEquipment::route('/create'),
            'edit' => EditEquipment::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'name_en', 'name_ar'];
    }
}
