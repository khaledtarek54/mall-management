<?php

namespace App\Filament\Admin\Resources\LedgerAccounts;

use App\Filament\Admin\RelationManagers\ActivitiesRelationManager;
use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\LedgerAccounts\Pages\CreateLedgerAccount;
use App\Filament\Admin\Resources\LedgerAccounts\Pages\EditLedgerAccount;
use App\Filament\Admin\Resources\LedgerAccounts\Pages\ListLedgerAccounts;
use App\Filament\Admin\Resources\LedgerAccounts\Schemas\LedgerAccountForm;
use App\Filament\Admin\Resources\LedgerAccounts\Tables\LedgerAccountsTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\LedgerAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * دليل الحسابات — the chart of accounts (one shared chart; property is a
 * dimension on entries, not on the chart itself, so this resource is NOT
 * property-scoped).
 */
class LedgerAccountResource extends Resource
{
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;
    use SearchesNormalizedText;

    protected static ?string $model = LedgerAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.ledger_accounts');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.ledger_account.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.ledger_account.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.general_ledger');
    }

    public static function form(Schema $schema): Schema
    {
        return LedgerAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LedgerAccountsTable::configure($table);
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
            'index' => ListLedgerAccounts::route('/'),
            'create' => CreateLedgerAccount::route('/create'),
            'edit' => EditLedgerAccount::route('/{record}/edit'),
        ];
    }

    /**
     * Searched through the fold-normalized blob, never a raw column.
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
        ];
    }
}
