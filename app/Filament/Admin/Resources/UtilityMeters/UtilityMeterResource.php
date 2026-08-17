<?php

namespace App\Filament\Admin\Resources\UtilityMeters;

use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
use App\Filament\Admin\Resources\UtilityMeters\Pages\CreateUtilityMeter;
use App\Filament\Admin\Resources\UtilityMeters\Pages\EditUtilityMeter;
use App\Filament\Admin\Resources\UtilityMeters\Pages\ListUtilityMeters;
use App\Filament\Admin\Resources\UtilityMeters\RelationManagers\ReadingsRelationManager;
use App\Filament\Admin\Resources\UtilityMeters\Schemas\UtilityMeterForm;
use App\Filament\Admin\Resources\UtilityMeters\Tables\UtilityMetersTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\Unit;
use App\Models\UtilityMeter;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UtilityMeterResource extends Resource
{
    // NOT Filament auto-tenancy: asset_id is CLIENT-supplied (the operator picks the mall, and that
    // Select is enabled in All-Properties mode). Filament's ownership `creating` hook would force
    // asset_id to the current tenant — and in All-mode the tenant is the ALL pseudo-asset, silently
    // clobbering the chosen mall (the "Announcements tenancy trap"). BypassesFilamentTenantAutoScope
    // turns that hook off; reads are scoped in getEloquentQuery() below and the submitted asset_id is
    // re-validated by assertAssetInScope() on create + edit.
    use GuardsAssetInScope;
    use RoleGatedActions;
    use ScopesToProperty;
    use SearchesNormalizedText;

    protected static ?string $model = UtilityMeter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?int $navigationSort = 7;

    /**
     * By the number stamped on the meter, or by the unit it serves.
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
            'unit.search_text',
        ];
    }

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
        return __('admin.groups.receivables');
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

    public static function getRelations(): array
    {
        return [
            ReadingsRelationManager::class,
        ];
    }

    /** Property-scope the list ourselves (Filament auto-tenancy is off — see the trait note above). */
    public static function getEloquentQuery(): Builder
    {
        return static::scopeToProperty(parent::getEloquentQuery()->with(['asset', 'unit']));
    }

    /**
     * Context under the title. A bare reference does not tell an operator whether the
     * row in front of them is the one they were hunting for.
     *
     * @param  UtilityMeter  $record  Narrowed from Filament's Model signature so static analysis
     *                                can see the columns — the alternative was ten baseline entries.
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Unit|null $unit */
        $unit = $record->unit;

        return [
            __('admin.tables.common.unit') => $unit?->code,
            __('admin.fields.meter_type') => $record->type,
            __('admin.fields.meter_provider') => $record->provider,
        ];
    }

    /**
     * Eager-load exactly what getGlobalSearchResultDetails() reaches for. Without this
     * the details above fire one query per row, per keystroke, on top of the search.
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['unit']);
    }
}
