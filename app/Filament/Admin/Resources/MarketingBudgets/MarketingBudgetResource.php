<?php

namespace App\Filament\Admin\Resources\MarketingBudgets;

use App\Filament\Admin\Resources\Concerns\BypassesScopingOnAll;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\MarketingBudgets\Pages\EditMarketingBudget;
use App\Filament\Admin\Resources\MarketingBudgets\Pages\ListMarketingBudgets;
use App\Filament\Admin\Resources\MarketingBudgets\RelationManagers\MarketingSpendsRelationManager;
use App\Filament\Admin\Resources\MarketingBudgets\Schemas\MarketingBudgetForm;
use App\Filament\Admin\Resources\MarketingBudgets\Tables\MarketingBudgetsTable;
use App\Models\MarketingBudget;
use App\Models\MarketingSpend;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MarketingBudgetResource extends Resource
{
    /**
     * Deliberately absent from global search — the reason is stated in
     * App\Support\SearchPolicy::GLOBAL_SEARCH_EXEMPT, which the conformance
     * gate reads. Do not flip this without removing that entry.
     */
    protected static bool $isGloballySearchable = false;

    use BypassesScopingOnAll;
    use RoleGatedActions;

    protected static function permissionModule(): string
    {
        return 'marketing';
    }

    /**
     * Budgets are an auto-provisioned ledger — one per property per year, funded
     * by the levy + ensured by `marketing:ensure-budgets`. Users record spends
     * against them; they never hand-create (or delete) a budget.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    protected static ?string $model = MarketingBudget::class;

    protected static ?string $tenantOwnershipRelationshipName = 'asset';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'period_year';

    public static function getNavigationLabel(): string
    {
        return __('admin.resources.marketing_budget.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.marketing_budget.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.marketing_budget.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.marketing');
    }

    public static function form(Schema $schema): Schema
    {
        return MarketingBudgetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MarketingBudgetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MarketingSpendsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMarketingBudgets::route('/'),
            'edit' => EditMarketingBudget::route('/{record}/edit'),
        ];
    }

    /**
     * One budget's marketing spend register as CSV rows — where the fund went, item by item, the
     * record the owner (Jawad) reviews to see the operator stayed within the collected levy. Reads
     * the budget's live spends (soft-deleted excluded, so it ties to `spent_amount`) and closes with
     * a spend total that ties to the fund panel.
     *
     * @return array{headers: array<int,string>, rows: array<int, array<int, string|float>>}
     */
    public static function spendRegisterCsv(MarketingBudget $budget): array
    {
        $rows = [];
        $total = 0.0;

        /** @var MarketingSpend $spend */
        foreach ($budget->spends()->orderByDesc('spent_on')->get() as $spend) {
            $amount = round((float) $spend->amount, 2);
            $total += $amount;

            $rows[] = [
                // spent_on is a NOT-NULL date column; paid_from is NOT-NULL (coerced to 'cash').
                $spend->spent_on->format('Y-m-d'),
                __('admin.enums.marketing_spend_category.'.$spend->category),
                (string) $spend->description,
                $amount,
                __('admin.enums.expense_paid_from.'.$spend->paid_from),
                $spend->receipt_reference ?? '',
            ];
        }

        $rows[] = ['', __('admin.reports.csv.total'), '', round($total, 2), '', ''];

        return [
            'headers' => [
                __('admin.tables.marketing_spend.spent_on'), __('admin.tables.marketing_spend.category'),
                __('admin.tables.marketing_spend.description'), __('admin.tables.marketing_spend.amount'),
                __('admin.fields.paid_from'), __('admin.tables.marketing_spend.receipt'),
            ],
            'rows' => $rows,
        ];
    }
}
