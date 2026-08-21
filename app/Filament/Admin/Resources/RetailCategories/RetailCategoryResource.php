<?php

namespace App\Filament\Admin\Resources\RetailCategories;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\RetailCategories\Pages\CreateRetailCategory;
use App\Filament\Admin\Resources\RetailCategories\Pages\EditRetailCategory;
use App\Filament\Admin\Resources\RetailCategories\Pages\ListRetailCategories;
use App\Filament\Admin\Resources\RetailCategories\Schemas\RetailCategoryForm;
use App\Filament\Admin\Resources\RetailCategories\Tables\RetailCategoriesTable;
use App\Models\RetailCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * التصنيفات التجارية — the merchandising mix.
 *
 * The screen is what makes it a catalogue rather than a seeder's output: the mix is the leasing
 * team's working vocabulary, revised per mall and per season, and a mall that lands a cinema or a
 * clinic cluster wants it in the store directory that afternoon.
 *
 * **Operator-level, not per property** (`#[PortfolioShared]`): one vocabulary across the portfolio,
 * so two malls' tenant mix can be compared.
 */
class RetailCategoryResource extends Resource
{
    // PORTFOLIO-SHARED, so it must opt OUT of the panel's tenancy. Filament scopes a resource by
    // asking the model for an `asset` relationship, and a shared catalogue has none — the list page
    // 500'd with a LogicException the moment a property was selected, which is every page load.
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = RetailCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 15;

    protected static function permissionModule(): string
    {
        return 'retail_categories';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.retail_categories_screen.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.retail_categories_screen.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.retail_categories_screen.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.leasing');
    }

    public static function form(Schema $schema): Schema
    {
        return RetailCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RetailCategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRetailCategories::route('/'),
            'create' => CreateRetailCategory::route('/create'),
            'edit' => EditRetailCategory::route('/{record}/edit'),
        ];
    }
}
