<?php

namespace App\Filament\Admin\Resources\RecurringExpenses;

use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
use App\Filament\Admin\Resources\RecurringExpenses\Pages\CreateRecurringExpense;
use App\Filament\Admin\Resources\RecurringExpenses\Pages\EditRecurringExpense;
use App\Filament\Admin\Resources\RecurringExpenses\Pages\ListRecurringExpenses;
use App\Filament\Admin\Resources\RecurringExpenses\Schemas\RecurringExpenseForm;
use App\Filament\Admin\Resources\RecurringExpenses\Tables\RecurringExpensesTable;
use App\Models\RecurringExpense;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * المصروفات الدورية — the costs that come round every period (EG-33).
 *
 * The screen is what makes this a capability rather than a table only a seeder could fill. A
 * schedule an operator cannot create is the failure `ServiceReachability` was written for and that
 * `BillUnitOwnershipsService` shipped: fully built, fully tested, and billing nobody.
 *
 * Property-owned, because a real-estate tax assessment is issued against a building.
 *
 * **That sentence used to end "…so this is tenant-scoped by the panel in the ordinary way and needs
 * no `BypassesFilamentTenantAutoScope`", and it was wrong on both counts.** The table read was not
 * scoped at all — a Mall B schedule was listed on Mall A's screen — and the form exposes an
 * editable `asset_id` through `PropertyField`, which is exactly the shape that clobbers the
 * operator's chosen mall to the ALL pseudo-asset (the "Announcements tenancy trap").
 * `ScopesToProperty` supplies both the scoped `getEloquentQuery()` and the auto-scope bypass;
 * `GuardsAssetInScope` is the write half, because the pin is a UI truth and the value still arrives
 * in the Livewire payload.
 */
class RecurringExpenseResource extends Resource
{
    use GuardsAssetInScope;
    use RoleGatedActions;
    use ScopesToProperty;

    protected static ?string $model = RecurringExpense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?int $navigationSort = 46;

    protected static function permissionModule(): string
    {
        return 'recurring_expenses';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.recurring_expenses.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.recurring_expenses.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.recurring_expenses.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.payables');
    }

    public static function form(Schema $schema): Schema
    {
        return RecurringExpenseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecurringExpensesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecurringExpenses::route('/'),
            'create' => CreateRecurringExpense::route('/create'),
            'edit' => EditRecurringExpense::route('/{record}/edit'),
        ];
    }
}
