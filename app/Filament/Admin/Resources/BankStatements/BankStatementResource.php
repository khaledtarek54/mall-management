<?php

namespace App\Filament\Admin\Resources\BankStatements;

use App\Filament\Admin\Resources\BankStatements\Pages\CreateBankStatement;
use App\Filament\Admin\Resources\BankStatements\Pages\EditBankStatement;
use App\Filament\Admin\Resources\BankStatements\Pages\ListBankStatements;
use App\Filament\Admin\Resources\BankStatements\RelationManagers\LinesRelationManager;
use App\Filament\Admin\Resources\BankStatements\Schemas\BankStatementForm;
use App\Filament\Admin\Resources\BankStatements\Tables\BankStatementsTable;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
use App\Models\BankStatement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * كشوف البنك — bank statements and the matching workspace (reconciliation slices 2–3).
 *
 * A statement is EVIDENCE: the only record in the system that comes from outside it.
 * `billing:reconcile` re-derives the books from the documents, so it agrees with a wrong document;
 * only the bank can disagree. Importing one posts nothing, and matching one posts nothing — the
 * whole screen changes no balance, which is what keeps it from being a back door into the GL.
 *
 * **Property-scoped through the account**, since a statement has no `asset_id` of its own — the
 * money belongs to the mall the account belongs to (classified that way in `PropertyIsolation`).
 *
 * **One permission with the accounts, deliberately.** Registering the bank you reconcile and
 * reconciling it are one job; a role that could add an account but not match its statement would be
 * an operator who cannot finish anything.
 */
class BankStatementResource extends Resource
{
    use RoleGatedActions;
    use ScopesToProperty;

    protected static ?string $model = BankStatement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?int $navigationSort = 10;

    protected static function permissionModule(): string
    {
        return 'bank_accounts';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.bank_statements');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.bank_statement.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.bank_statement.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.general_ledger');
    }

    /** Scoped through the account — a statement carries no asset_id of its own. */
    public static function getEloquentQuery(): Builder
    {
        // The `bankAccount` hop is BankStatement's own #[PropertyOwned(via: 'bankAccount')] — the
        // relation is named on the model, so this resource no longer states it a second time.
        return static::scopeToProperty(parent::getEloquentQuery());
    }

    public static function form(Schema $schema): Schema
    {
        return BankStatementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BankStatementsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [LinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankStatements::route('/'),
            'create' => CreateBankStatement::route('/create'),
            'edit' => EditBankStatement::route('/{record}/edit'),
        ];
    }
}
