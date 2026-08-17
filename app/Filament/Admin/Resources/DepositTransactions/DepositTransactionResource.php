<?php

namespace App\Filament\Admin\Resources\DepositTransactions;

use App\Filament\Admin\RelationManagers\ActivitiesRelationManager;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
use App\Filament\Admin\Resources\DepositTransactions\Pages\CreateDepositTransaction;
use App\Filament\Admin\Resources\DepositTransactions\Pages\EditDepositTransaction;
use App\Filament\Admin\Resources\DepositTransactions\Pages\ListDepositTransactions;
use App\Filament\Admin\Resources\DepositTransactions\Schemas\DepositTransactionForm;
use App\Filament\Admin\Resources\DepositTransactions\Tables\DepositTransactionsTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\DepositTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * حركة تأمين — security-deposit transactions (receipt / refund / forfeit). Each is a
 * standalone GL posting; the tenant/asset are derived from the lease. Scoped by the
 * transaction's `asset_id` dimension, always also showing consolidated (null-asset)
 * company-level transactions.
 */
class DepositTransactionResource extends Resource
{
    use GuardsAssetInScope;
    use RoleGatedActions;
    use ScopesToProperty;
    use SearchesNormalizedText;

    protected static ?string $model = DepositTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.deposit_transactions');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.deposit_transaction.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.deposit_transaction.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.receivables');
    }

    public static function form(Schema $schema): Schema
    {
        return DepositTransactionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DepositTransactionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDepositTransactions::route('/'),
            'create' => CreateDepositTransaction::route('/create'),
            'edit' => EditDepositTransaction::route('/{record}/edit'),
        ];
    }

    /**
     * By transaction number or by the lease the deposit sits against.
     *
     * Every path ends in `search_text` on purpose — see
     * App\Filament\Concerns\SearchesNormalizedText.
     *
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'search_text',
            'lease.search_text',
        ];
    }
}
