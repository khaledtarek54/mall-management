<?php

namespace App\Filament\Admin\Resources\OwnerStatementRuns;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\OwnerStatementRuns\Pages\ListOwnerStatementRuns;
use App\Filament\Admin\Resources\OwnerStatementRuns\Tables\OwnerStatementRunsTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\OwnerStatementRun;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Owner statements + disbursements (module 32) — the operator-for-owner deliverable. A run is
 * one property's statement for one accounting period; finalising it accrues what the property
 * owes the owner, and a payout clears it. v1: one owner per mall who gets 100% of the net.
 *
 * Property-scoped; gated on `owner_statements.*`. NOT Filament auto-tenancy — the run's asset is
 * chosen in the Generate action, so `BypassesFilamentTenantAutoScope` turns the clobber hook off
 * and `getEloquentQuery()` scopes reads (the AnnouncementResource pattern).
 */
class OwnerStatementRunResource extends Resource
{
    use BypassesFilamentTenantAutoScope;
    use GuardsAssetInScope;
    use RoleGatedActions;
    use SearchesNormalizedText;

    protected static ?string $model = OwnerStatementRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'reference';

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

    protected static function permissionModule(): string
    {
        return 'owner_statements';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.general_ledger');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.owner_statements.run_plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.owner_statements.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.owner_statements.run_plural');
    }

    public static function canGenerate(): bool
    {
        return auth()->user()?->can('owner_statements.generate') ?? false;
    }

    public static function canFinalise(): bool
    {
        return auth()->user()?->can('owner_statements.finalise') ?? false;
    }

    public static function canRevise(): bool
    {
        return auth()->user()?->can('owner_statements.revise') ?? false;
    }

    public static function canSchedule(): bool
    {
        return auth()->user()?->can('disbursements.schedule') ?? false;
    }

    public static function canSend(): bool
    {
        return auth()->user()?->can('owner_statements.send') ?? false;
    }

    public static function canViewStatements(): bool
    {
        return auth()->user()?->can('owner_statements.view') ?? false;
    }

    /** Property-scope the list ourselves (Filament auto-tenancy is off). */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if ($assetId = TenantScope::currentAssetId()) {
            $query->where('asset_id', $assetId);
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
            // All-Properties mode: a restricted user still only sees their own.
            $query->whereIn('asset_id', $ids);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return OwnerStatementRunsTable::configure($table);
    }

    /**
     * The run's OUTPUT. It could be generated, finalised, revised, PDF'd and sent while the
     * per-owner statements were never listed anywhere — the question the run exists to answer had
     * no screen.
     */
    public static function getRelations(): array
    {
        return [
            \App\Filament\Admin\RelationManagers\OwnerStatementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOwnerStatementRuns::route('/'),
            'view' => Pages\ViewOwnerStatementRun::route('/{record}'),
        ];
    }
}
