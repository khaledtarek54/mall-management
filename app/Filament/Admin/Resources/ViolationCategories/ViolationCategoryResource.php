<?php

namespace App\Filament\Admin\Resources\ViolationCategories;

use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\ViolationCategories\Pages\CreateViolationCategory;
use App\Filament\Admin\Resources\ViolationCategories\Pages\EditViolationCategory;
use App\Filament\Admin\Resources\ViolationCategories\Pages\ListViolationCategories;
use App\Filament\Admin\Resources\ViolationCategories\Schemas\ViolationCategoryForm;
use App\Filament\Admin\Resources\ViolationCategories\Tables\ViolationCategoriesTable;
use App\Models\ViolationCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * بنود المخالفات — the mall's house rules, and the standard fine for each.
 *
 * The screen is what makes it a rule book rather than a seeder's output. A mall publishes its rules
 * in the tenant handbook, amends them when a problem recurs, and cites the clause on the notice it
 * serves — "signage / noise / other" cannot carry that, and the migration that introduced the column
 * had already promised the set was the operator's to extend.
 *
 * **Operator-level, not per property** (`#[PortfolioShared]`): the house rules are Eltizam's, applied
 * across the malls it runs, which is also what makes a portfolio-wide repeat-offender report mean
 * anything.
 */
class ViolationCategoryResource extends Resource
{
    use RoleGatedActions;

    protected static ?string $model = ViolationCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 16;

    protected static function permissionModule(): string
    {
        return 'violation_categories';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.violation_categories_screen.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.violation_categories_screen.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.violation_categories_screen.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.operations');
    }

    public static function form(Schema $schema): Schema
    {
        return ViolationCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ViolationCategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListViolationCategories::route('/'),
            'create' => CreateViolationCategory::route('/create'),
            'edit' => EditViolationCategory::route('/{record}/edit'),
        ];
    }
}
