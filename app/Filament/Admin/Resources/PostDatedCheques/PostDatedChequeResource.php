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
use App\Models\PostDatedCheque;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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

    protected static ?string $model = PostDatedCheque::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static ?int $navigationSort = 44;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static function permissionModule(): string
    {
        return 'post_dated_cheques';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.accounting');
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
}
