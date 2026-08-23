<?php

namespace App\Filament\Admin\Resources\CustomFields;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\CustomFields\Pages\CreateCustomField;
use App\Filament\Admin\Resources\CustomFields\Pages\EditCustomField;
use App\Filament\Admin\Resources\CustomFields\Pages\ListCustomFields;
use App\Filament\Admin\Resources\CustomFields\Schemas\CustomFieldForm;
use App\Filament\Admin\Resources\CustomFields\Tables\CustomFieldsTable;
use App\Models\CustomField;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * الحقول المخصصة — the fields this operator added to a record type (D-7 / EG-32).
 *
 * The screen is what makes this a capability rather than a column. Every ERP this is measured
 * against — Yardi UDFs, MRI user-defined fields, Odoo Studio — lets the operator add a field
 * themselves, because the alternative is that every fact the vendor did not model either goes in
 * the notes box where nothing can read it, or costs a deploy.
 *
 * Without this screen the catalogue would be a table only a seeder could fill: fully built, fully
 * tested and unusable, which is precisely the failure `ServiceReachability` exists to catch and
 * which billed nobody for the whole of module 37's life.
 *
 * **Operator-level, not per property** (`#[PortfolioShared]`): what an operator records about a
 * tenant is their vocabulary, not one mall's. Per-property definitions would mean re-adding "parent
 * group" once per mall and losing every portfolio-wide comparison.
 */
class CustomFieldResource extends Resource
{
    // PORTFOLIO-SHARED, so it must opt OUT of the panel's tenancy. Filament scopes a resource by
    // asking the model for an `asset` relationship, and a shared catalogue has none — without this
    // the list page throws a LogicException the moment the table paginates, on every visit.
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = CustomField::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 92;

    protected static function permissionModule(): string
    {
        return 'custom_fields';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.custom_fields.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.custom_fields.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.custom_fields.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.settings');
    }

    public static function form(Schema $schema): Schema
    {
        return CustomFieldForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomFieldsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomFields::route('/'),
            'create' => CreateCustomField::route('/create'),
            'edit' => EditCustomField::route('/{record}/edit'),
        ];
    }
}
