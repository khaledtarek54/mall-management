<?php

namespace App\Filament\Admin\Resources\TaxCodes;

use App\Filament\Admin\RelationManagers\ActivitiesRelationManager;
use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\TaxCodes\Pages\CreateTaxCode;
use App\Filament\Admin\Resources\TaxCodes\Pages\EditTaxCode;
use App\Filament\Admin\Resources\TaxCodes\Pages\ListTaxCodes;
use App\Filament\Admin\Resources\TaxCodes\Schemas\TaxCodeForm;
use App\Filament\Admin\Resources\TaxCodes\Tables\TaxCodesTable;
use App\Models\TaxCode;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * الأكواد الضريبية — every tax this system may apply, and the dated ladder of rates it carries.
 *
 * The screen the accountant owns. Before it, the standard VAT rate was a field on the Settings page
 * with no date attached, and withholding was a second one — so "VAT goes to 15% on 1 January" could
 * not be entered at all, only remembered and typed on the day.
 *
 * Shared, not property-scoped: a tax rate is national. Where a mall needs a different answer it is
 * because a different SUPPLY is being billed, which is a charge-code decision one level down.
 */
class TaxCodeResource extends Resource
{
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = TaxCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.tax_codes');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.tax_code.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.tax_code.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return TaxCodeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TaxCodesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            // The rate ladder. A relation manager rather than a repeater on the form: a rung is a
            // dated record with its own audit trail, and adding next year's rate must not mean
            // re-saving the code itself.
            RelationManagers\RatesRelationManager::class,
            ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaxCodes::route('/'),
            'create' => CreateTaxCode::route('/create'),
            'edit' => EditTaxCode::route('/{record}/edit'),
        ];
    }
}
