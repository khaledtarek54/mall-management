<?php

namespace App\Filament\Admin\Resources\ExpenseCategories;

use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\ExpenseCategories\Pages\CreateExpenseCategory;
use App\Filament\Admin\Resources\ExpenseCategories\Pages\EditExpenseCategory;
use App\Filament\Admin\Resources\ExpenseCategories\Pages\ListExpenseCategories;
use App\Filament\Admin\Resources\ExpenseCategories\Schemas\ExpenseCategoryForm;
use App\Filament\Admin\Resources\ExpenseCategories\Tables\ExpenseCategoriesTable;
use App\Models\ExpenseCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * بنود المصروفات — the kinds of cost, and the P&L account each one books to.
 *
 * The screen is the point. The category decided which expense account every supplier bill, expense
 * and custody spend hit, and it lived in a six-entry `private const` inside a journalizer trait —
 * so insurance, government fees, bank charges, legal fees and generator fuel all landed in
 * `admin_expense` behind a `Log::warning` nobody reads.
 *
 * The account matters beyond the P&L: `SyncCamPoolFromLedgerService` builds a CAM pool from the GL
 * BY ACCOUNT, so pointing a category at an account inside a pool starts recovering those costs from
 * tenants. (`cost_nature` here does NOT — that is `cam_pool_accounts.cost_nature`, a different
 * column on a different table.)
 *
 * **Operator-level, not per property** (`#[PortfolioShared]`): how Eltizam classifies its costs is
 * one chart of overhead, not a per-mall opinion. There is no property picker and nothing to scope.
 */
class ExpenseCategoryResource extends Resource
{
    use RoleGatedActions;

    protected static ?string $model = ExpenseCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 13;

    protected static function permissionModule(): string
    {
        return 'expense_categories';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.expense_categories.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.expense_categories.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.expense_categories.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.general_ledger');
    }

    public static function form(Schema $schema): Schema
    {
        return ExpenseCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExpenseCategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenseCategories::route('/'),
            'create' => CreateExpenseCategory::route('/create'),
            'edit' => EditExpenseCategory::route('/{record}/edit'),
        ];
    }
}
