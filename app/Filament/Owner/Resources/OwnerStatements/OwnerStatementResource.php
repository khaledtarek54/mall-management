<?php

namespace App\Filament\Owner\Resources\OwnerStatements;

use App\Filament\Owner\Resources\OwnerStatements\Pages\ListOwnerStatements;
use App\Filament\Owner\Resources\OwnerStatements\Tables\OwnerStatementsTable;
use App\Models\OwnerStatement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The owner's own statements, in the owner portal (module 32).
 *
 * The operator produces the owner statement FOR the owner, but until now the owner could not see it
 * — they had to be emailed a PDF by hand. This exposes the deliverable to its recipient, read-only:
 * only their OWN statements (`user_id` = the signed-in owner) and only ones that have been
 * **finalised or sent** — a draft or superseded version is the operator's working state, never the
 * owner's to see.
 */
class OwnerStatementResource extends Resource
{
    protected static ?string $model = OwnerStatement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('admin.owner_statements.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.owner_statements.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.owner_statements.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.portfolio');
    }

    public static function table(Table $table): Table
    {
        return OwnerStatementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOwnerStatements::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['run.asset', 'asset'])
            // The signed-in owner's own statements only — never another owner's.
            ->where('user_id', Auth::id())
            // Drafts and superseded versions are operator working state, not the owner's.
            ->whereIn('status', [OwnerStatement::STATUS_FINALISED, OwnerStatement::STATUS_SENT]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
