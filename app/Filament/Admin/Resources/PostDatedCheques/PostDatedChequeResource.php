<?php

namespace App\Filament\Admin\Resources\PostDatedCheques;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\PostDatedCheques\Pages\CreatePostDatedCheque;
use App\Filament\Admin\Resources\PostDatedCheques\Pages\EditPostDatedCheque;
use App\Filament\Admin\Resources\PostDatedCheques\Pages\ListPostDatedCheques;
use App\Filament\Admin\Resources\PostDatedCheques\Schemas\PostDatedChequeForm;
use App\Filament\Admin\Resources\PostDatedCheques\Tables\PostDatedChequesTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\PostDatedCheque;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Post-dated cheques (module 33) — the forward-instrument register. Property-scoped on a direct
 * asset_id via the AnnouncementResource pattern; gated on `post_dated_cheques.*`. Clearing records
 * a Payment (so it also requires `payments.create`).
 */
class PostDatedChequeResource extends Resource
{
    use BypassesFilamentTenantAutoScope;
    use GuardsAssetInScope;
    use RoleGatedActions;
    use SearchesNormalizedText;

    protected static ?string $model = PostDatedCheque::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'reference';

    /**
     * By our reference, by what is written on the cheque, or by tenant.
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
            'tenant.search_text',
        ];
    }

    protected static function permissionModule(): string
    {
        return 'post_dated_cheques';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.receivables');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.post_dated_cheques.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.post_dated_cheques.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.post_dated_cheques.plural');
    }

    /** Lifecycle transitions that just move status. */
    public static function canManage(): bool
    {
        return auth()->user()?->can('post_dated_cheques.edit') ?? false;
    }

    /** Clearing records a Payment — it's a money action, so it needs payments.create. */
    public static function canClear(): bool
    {
        return auth()->user()?->can('payments.create') ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if ($assetId = TenantScope::currentAssetId()) {
            $query->where('asset_id', $assetId);
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
            $query->whereIn('asset_id', $ids);
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return PostDatedChequeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PostDatedChequesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPostDatedCheques::route('/'),
            'create' => CreatePostDatedCheque::route('/create'),
            'edit' => EditPostDatedCheque::route('/{record}/edit'),
        ];
    }
    /**
     * Context under the title. A bare reference does not tell an operator whether the
     * row in front of them is the one they were hunting for.
     *
     * @param  PostDatedCheque  $record  Narrowed from Filament's Model signature so static analysis
     *                    can see the columns — the alternative was ten baseline entries.
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var \App\Models\Tenant|null $tenant */
        $tenant = $record->tenant;

        return [
            __('admin.tables.common.tenant') => $tenant?->name,
            __('admin.fields.cheque_number') => $record->cheque_number,
            __('admin.fields.amount') => 'EGP '.number_format((float) $record->amount, 2),
        ];
    }

    /**
     * Eager-load exactly what getGlobalSearchResultDetails() reaches for. Without this
     * the details above fire one query per row, per keystroke, on top of the search.
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['tenant']);
    }

}
