<?php

namespace App\Filament\Admin\Resources\Equipment;

use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
use App\Filament\Admin\Resources\Equipment\Pages\CreateEquipment;
use App\Filament\Admin\Resources\Equipment\Pages\EditEquipment;
use App\Filament\Admin\Resources\Equipment\Pages\ListEquipment;
use App\Filament\Admin\Resources\Equipment\Schemas\EquipmentForm;
use App\Filament\Admin\Resources\Equipment\Tables\EquipmentTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\Equipment;
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
 * `facility` module flag + `facility.*` permissions: the
 * engineers who service the machines are the people who maintain this list.
 */
class EquipmentResource extends Resource
{
    // NOT Filament auto-tenancy: asset_id is CLIENT-supplied (the operator picks the mall, and that
    // Select is enabled in All-Properties mode). Filament's ownership `creating` hook would force
    // asset_id to the current tenant — and in All-mode the tenant is the ALL pseudo-asset, silently
    // clobbering the chosen mall (the "Announcements tenancy trap"). ScopesToProperty
    // turns that hook off AND scopes reads from the model's own #[PropertyOwned]; the submitted asset_id is
    // re-validated by assertAssetInScope() on create + edit.
    use GuardsAssetInScope;
    use RoleGatedActions;
    use ScopesToProperty;
    use SearchesNormalizedText;

    protected static ?string $model = Equipment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $recordTitleAttribute = 'code';

    protected static function permissionModule(): string
    {
        return 'facility';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.facility.equipment.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.facility.equipment.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.facility.equipment.plural');
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
     *                             can see the columns — the alternative was ten baseline entries.
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        // The TRADE, not `category` (SW-076) — `equipment.category` was dropped with the work
        // order's when the Trade catalogue replaced both, and a missing attribute is NULL rather
        // than an error, so this printed a permanently blank row. `EquipmentTable:46` already
        // reads `$record->trade?->label()`; the search result is the same fact through another
        // door, and the two had been allowed to disagree.
        return [
            __('admin.facility.fields.trade') => $record->trade?->label() ?? '—',
            __('admin.tables.meter.location') => $record->location,
        ];
    }

    /**
     * Eager-load what the search details reach for — otherwise the trade is one query per row,
     * per keystroke, on top of the search itself. The work-order resource states the same reason.
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('trade');
    }
}
