<?php

namespace App\Filament\Admin\Resources\Equipment;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Equipment\Pages\CreateEquipment;
use App\Filament\Admin\Resources\Equipment\Pages\EditEquipment;
use App\Filament\Admin\Resources\Equipment\Pages\ListEquipment;
use App\Filament\Admin\Resources\Equipment\Schemas\EquipmentForm;
use App\Filament\Admin\Resources\Equipment\Tables\EquipmentTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\Equipment;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The maintainable-asset register (FR-PPM-03/04/05) — scoped to the current property
 * (direct asset_id, like Unit / Warehouse). Part of module 26, so it is gated by the
 * `preventive_maintenance` module flag + `preventive_maintenance.*` permissions: the
 * engineers who service the machines are the people who maintain this list.
 */
class EquipmentResource extends Resource
{
    // NOT Filament auto-tenancy: asset_id is CLIENT-supplied (the operator picks the mall, and that
    // Select is enabled in All-Properties mode). Filament's ownership `creating` hook would force
    // asset_id to the current tenant — and in All-mode the tenant is the ALL pseudo-asset, silently
    // clobbering the chosen mall (the "Announcements tenancy trap"). BypassesFilamentTenantAutoScope
    // turns that hook off; reads are scoped in getEloquentQuery() below and the submitted asset_id is
    // re-validated by assertAssetInScope() on create + edit.
    use BypassesFilamentTenantAutoScope;
    use GuardsAssetInScope;
    use RoleGatedActions;
    use SearchesNormalizedText;

    protected static ?string $model = Equipment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'code';

    protected static function permissionModule(): string
    {
        return 'preventive_maintenance';
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
     * Context under the title. A bare reference does not tell an operator whether the
     * row in front of them is the one they were hunting for.
     *
     * @param  Equipment  $record  Narrowed from Filament's Model signature so static analysis
     *                    can see the columns — the alternative was ten baseline entries.
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            __('admin.fields.category') => $record->category,
            __('admin.tables.meter.location') => $record->location,
        ];
    }

}
