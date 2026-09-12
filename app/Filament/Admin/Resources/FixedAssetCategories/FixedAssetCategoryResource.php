<?php

namespace App\Filament\Admin\Resources\FixedAssetCategories;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\FixedAssetCategories\Pages\CreateFixedAssetCategory;
use App\Filament\Admin\Resources\FixedAssetCategories\Pages\EditFixedAssetCategory;
use App\Filament\Admin\Resources\FixedAssetCategories\Pages\ListFixedAssetCategories;
use App\Filament\Admin\Resources\FixedAssetCategories\Schemas\FixedAssetCategoryForm;
use App\Filament\Admin\Resources\FixedAssetCategories\Tables\FixedAssetCategoriesTable;
use App\Models\FixedAssetCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * فئات الأصول الثابتة — the asset classes, and what each decides for the assets registered under it
 * (meeting 2026-09-02, points 11 · 13 · 14): the number series, the proposed useful life, the memo
 * value and the tax pool.
 *
 * **Operator-level, not per property** (`#[PortfolioShared]`): an asset class is an accounting
 * definition — SAP keeps it at chart-of-depreciation level — and the same chiller is the same kind of
 * thing in every mall the operator runs. The number series it names is counted per property, which
 * is the register's own identity rule.
 */
class FixedAssetCategoryResource extends Resource
{
    // PORTFOLIO-SHARED, so it must opt OUT of the panel's tenancy — a shared catalogue has no
    // `asset` relationship and the list page 500s the moment a property is selected otherwise.
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = FixedAssetCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static function permissionModule(): string
    {
        return 'fixed_asset_categories';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.fixed_asset_categories_screen.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.fixed_asset_categories_screen.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.fixed_asset_categories_screen.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return FixedAssetCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FixedAssetCategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFixedAssetCategories::route('/'),
            'create' => CreateFixedAssetCategory::route('/create'),
            'edit' => EditFixedAssetCategory::route('/{record}/edit'),
        ];
    }
}
