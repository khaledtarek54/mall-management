<?php

namespace App\Filament\Admin\Resources\BankAccounts;

use App\Filament\Admin\Resources\BankAccounts\Pages\CreateBankAccount;
use App\Filament\Admin\Resources\BankAccounts\Pages\EditBankAccount;
use App\Filament\Admin\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Filament\Admin\Resources\BankAccounts\Schemas\BankAccountForm;
use App\Filament\Admin\Resources\BankAccounts\Tables\BankAccountsTable;
use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\BankAccount;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * الحسابات البنكية — the bank accounts the operator actually holds (bank-reconciliation slice 1).
 *
 * `bank` and `cash` are posting ROLES resolved one-per-property through `account_mappings`. That is
 * enough to post to a bank and not enough to RECONCILE one: a reconciliation is always of a single
 * named account, and once a property banks in two places the role cannot say which. So the account
 * comes first and the matcher comes later — see docs/accounting/BANK-RECONCILIATION-PLAN.md.
 *
 * **Nothing posts through this yet, deliberately.** The ledger resolves the `bank` role exactly as
 * it did before, so registering an account changes no balance and no entry. This is a register, and
 * on its own it is where the operator's banks stop being a posting-map row.
 *
 * Property-scoped like every property-owned module: reads filtered in `getEloquentQuery()`, the
 * client-supplied `asset_id` re-validated by `assertAssetInScope()` on create AND edit (Filament
 * stamps asset_id on create only, never on update).
 */
class BankAccountResource extends Resource
{
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;
    use SearchesNormalizedText;

    protected static ?string $model = BankAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 9;

    protected static ?string $recordTitleAttribute = 'name';

    protected static function permissionModule(): string
    {
        return 'bank_accounts';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.bank_accounts');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.bank_account.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.bank_account.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.accounting');
    }

    /** Property-scope the list ourselves — Filament auto-tenancy is off (see the trait note). */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if ($assetId = TenantScope::currentAssetId()) {
            $query->where('asset_id', $assetId);
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
            // A restricted user still sees only their own malls — never fall back to unscoped.
            $query->whereIn('asset_id', $ids);
        }

        return $query;
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['search_text'];
    }

    /** Server-side guard against a tampered `asset_id`; matches every property-scoped resource. */
    public static function assertAssetInScope(mixed $assetId): void
    {
        $visible = TenantScope::visibleAssetIds();
        if ($visible !== null && ! in_array((int) $assetId, $visible, true)) {
            abort(403);
        }
    }

    public static function form(Schema $schema): Schema
    {
        return BankAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BankAccountsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankAccounts::route('/'),
            'create' => CreateBankAccount::route('/create'),
            'edit' => EditBankAccount::route('/{record}/edit'),
        ];
    }
}
