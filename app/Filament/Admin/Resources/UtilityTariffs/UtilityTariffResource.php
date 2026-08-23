<?php

namespace App\Filament\Admin\Resources\UtilityTariffs;

use App\Filament\Admin\RelationManagers\ActivitiesRelationManager;
use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\TaxCodes\TaxCodeResource;
use App\Filament\Admin\Resources\UtilityTariffs\Pages\CreateUtilityTariff;
use App\Filament\Admin\Resources\UtilityTariffs\Pages\EditUtilityTariff;
use App\Filament\Admin\Resources\UtilityTariffs\Pages\ListUtilityTariffs;
use App\Filament\Admin\Resources\UtilityTariffs\Schemas\UtilityTariffForm;
use App\Filament\Admin\Resources\UtilityTariffs\Tables\UtilityTariffsTable;
use App\Models\UtilityTariff;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * تعريفة المرافق — what a unit of electricity, water or gas costs, and the dated ladder of what it
 * has cost.
 *
 * The screen that replaces re-typing a number on every meter. Before it, a decreed tariff rise meant
 * editing `rate_per_unit` on every affected meter, on the morning it took effect, with no way to
 * tell which had been done — and a reading keyed either side of a half-finished edit priced two
 * tenants differently for the same supply on the same day.
 *
 * Shared, not property-scoped, for the reason {@see TaxCodeResource}
 * is: a published tariff is not a property of one mall. A property that genuinely has its own price
 * gets its own tariff row and points its meters at it.
 */
class UtilityTariffResource extends Resource
{
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = UtilityTariff::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.utility_tariffs');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.utility_tariff.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.utility_tariff.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return UtilityTariffForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UtilityTariffsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            // The price ladder. A relation manager rather than a repeater: a rung is a dated record
            // with its own audit trail, and entering next quarter's price must not mean re-saving
            // the tariff itself.
            RelationManagers\RatesRelationManager::class,
            ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUtilityTariffs::route('/'),
            'create' => CreateUtilityTariff::route('/create'),
            'edit' => EditUtilityTariff::route('/{record}/edit'),
        ];
    }
}
