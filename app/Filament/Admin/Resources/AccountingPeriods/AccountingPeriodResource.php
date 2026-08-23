<?php

namespace App\Filament\Admin\Resources\AccountingPeriods;

use App\Filament\Admin\Resources\AccountingPeriods\Pages\ListAccountingPeriods;
use App\Filament\Admin\Resources\AccountingPeriods\Tables\AccountingPeriodsTable;
use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Models\AccountingPeriod;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * الفترات المحاسبية — accounting periods (one month each) and fiscal-year close.
 * Periods are seeded by the fiscal calendar, never user-created, so this resource
 * is LIST + ACTIONS only (close/reopen a period, post/undo the year-end close);
 * there is no create or edit form. Periods are global (no `asset_id` dimension),
 * so the resource opts out of Filament's tenant auto-scoping like the chart of
 * accounts.
 */
class AccountingPeriodResource extends Resource
{
    /**
     * Deliberately absent from global search — the reason is stated in
     * App\Support\SearchPolicy::GLOBAL_SEARCH_EXEMPT, which the conformance
     * gate reads. Do not flip this without removing that entry.
     */
    protected static bool $isGloballySearchable = false;

    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = AccountingPeriod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static ?string $recordTitleAttribute = 'period_no';

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.accounting_periods');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.accounting_period.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.accounting_period.plural');
    }

    public static function form(Schema $schema): Schema
    {
        // Periods are seeded, not editable — no form.
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return AccountingPeriodsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountingPeriods::route('/'),
        ];
    }
}
