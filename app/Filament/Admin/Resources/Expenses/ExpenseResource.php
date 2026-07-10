<?php

namespace App\Filament\Admin\Resources\Expenses;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Admin\Resources\Expenses\Pages\EditExpense;
use App\Filament\Admin\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Admin\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Admin\Resources\Expenses\Tables\ExpensesTable;
use App\Models\Expense;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * مصروف مباشر — direct / petty-cash expenses paid immediately from cash or bank
 * (no vendor-payable stage). Scoped by the expense's `asset_id` dimension, always
 * also showing consolidated (null-asset) company-level expenses.
 */
class ExpenseResource extends Resource
{
    use BypassesFilamentTenantAutoScope;
    use GuardsAssetInScope;
    use RoleGatedActions;

    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 27;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.expenses');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.expense.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.expense.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.accounting');
    }

    public static function form(Schema $schema): Schema
    {
        return ExpenseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExpensesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Admin\RelationManagers\ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'edit' => EditExpense::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if ($assetId = \App\Support\TenantScope::currentAssetId()) {
            // Property-level expenses for this asset OR consolidated company expenses.
            $query->where(fn ($q) => $q->where('asset_id', $assetId)->orWhereNull('asset_id'));
        } elseif (($ids = \App\Support\TenantScope::visibleAssetIds()) !== null) {
            $query->where(fn ($q) => $q->whereIn('asset_id', $ids)->orWhereNull('asset_id'));
        }

        return $query;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'reference'];
    }
}
