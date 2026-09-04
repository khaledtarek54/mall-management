<?php

namespace App\Filament\Admin\Resources\AccountingPeriods;

use App\Filament\Admin\Resources\AccountingPeriods\Pages\ListAccountingPeriods;
use App\Filament\Admin\Resources\AccountingPeriods\Tables\AccountingPeriodsTable;
use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Models\AccountingPeriod;
use BackedEnum;
use Filament\Resources\Resource;
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

    // NO `form()` AT ALL — not an empty one, and the difference is the whole of SW-147.
    //
    // A period is seeded from the fiscal calendar and never typed, so there was nothing to put in
    // a form; what the empty declaration bought was a read-only View action rendered from it.
    // Filament resolves a resource ViewAction's schema as `infolist(form($schema))`
    // (ListRecords::getDefaultActionSchemaResolver), and both returned the schema untouched — so
    // the button opened a modal with a heading, a Close button and nothing between them.
    // `ViewActionCoverageTest` could not see it: it skips a resource only when `form()` is not
    // DECLARED, so a declared-but-empty one counted as having a form and was then required to
    // offer the very action it could not fill.
    //
    // Declaring nothing is the shape the three registers that legitimately have no form already
    // follow (disbursements, owner-statement runs, stock movements), and it is what the gate reads.

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
