<?php

namespace App\Filament\Admin\Resources\MarketingBudgets;

use App\Filament\Admin\Resources\Concerns\BypassesScopingOnAll;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\MarketingBudgets\Pages\CreateMarketingBudget;
use App\Filament\Admin\Resources\MarketingBudgets\Pages\EditMarketingBudget;
use App\Filament\Admin\Resources\MarketingBudgets\Pages\ListMarketingBudgets;
use App\Filament\Admin\Resources\MarketingBudgets\RelationManagers\MarketingSpendsRelationManager;
use App\Filament\Admin\Resources\MarketingBudgets\Schemas\MarketingBudgetForm;
use App\Filament\Admin\Resources\MarketingBudgets\Tables\MarketingBudgetsTable;
use App\Models\MarketingBudget;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MarketingBudgetResource extends Resource
{
    use BypassesScopingOnAll;
    use RoleGatedActions;

    protected static function permissionModule(): string
    {
        return 'marketing';
    }

    protected static ?string $model = MarketingBudget::class;

    protected static ?string $tenantOwnershipRelationshipName = 'asset';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 8;

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
            'create' => CreateMarketingBudget::route('/create'),
            'edit' => EditMarketingBudget::route('/{record}/edit'),
        ];
    }
}
