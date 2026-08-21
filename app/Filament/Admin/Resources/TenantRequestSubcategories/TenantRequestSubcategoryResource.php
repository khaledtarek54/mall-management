<?php

namespace App\Filament\Admin\Resources\TenantRequestSubcategories;

use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\TenantRequestSubcategories\Pages\CreateTenantRequestSubcategory;
use App\Filament\Admin\Resources\TenantRequestSubcategories\Pages\EditTenantRequestSubcategory;
use App\Filament\Admin\Resources\TenantRequestSubcategories\Pages\ListTenantRequestSubcategories;
use App\Filament\Admin\Resources\TenantRequestSubcategories\Schemas\TenantRequestSubcategoryForm;
use App\Filament\Admin\Resources\TenantRequestSubcategories\Tables\TenantRequestSubcategoriesTable;
use App\Models\TenantRequestSubcategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * تصنيفات الطلبات — what a tenant may report, under each kind of request.
 *
 * The screen is what makes the catalogue a catalogue. Without it the rows are a seeder's output and
 * nothing more: an operator could not add a problem their tenants keep reporting, retire one that
 * confuses people, re-point a subcategory at a different trade, or correct an Arabic label. The
 * model's `instead:` clause, `optionsFor()`'s docblock and the seeder all describe an operator doing
 * exactly those things — this is where they do them.
 *
 * **Operator-level, not per property** (`#[PortfolioShared]`): what a tenant may report is one
 * vocabulary across the portfolio.
 */
class TenantRequestSubcategoryResource extends Resource
{
    use RoleGatedActions;

    protected static ?string $model = TenantRequestSubcategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 14;

    protected static function permissionModule(): string
    {
        return 'tenant_request_subcategories';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.tenant_request_subcategories.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.tenant_request_subcategories.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.tenant_request_subcategories.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.operations');
    }

    public static function form(Schema $schema): Schema
    {
        return TenantRequestSubcategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TenantRequestSubcategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenantRequestSubcategories::route('/'),
            'create' => CreateTenantRequestSubcategory::route('/create'),
            'edit' => EditTenantRequestSubcategory::route('/{record}/edit'),
        ];
    }
}
