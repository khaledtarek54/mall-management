<?php

namespace App\Filament\Admin\Resources\OwnerRequests;

use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\OwnerRequests\Pages\CreateOwnerRequest;
use App\Filament\Admin\Resources\OwnerRequests\Pages\ListOwnerRequests;
use App\Filament\Admin\Resources\OwnerRequests\Schemas\OwnerRequestForm;
use App\Filament\Admin\Resources\OwnerRequests\Tables\OwnerRequestsTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\OwnerRequest;
use App\Support\AssignedAssets;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class OwnerRequestResource extends Resource
{
    use GuardsAssetInScope;
    use RoleGatedActions;
    use SearchesNormalizedText;

    protected static ?string $model = OwnerRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static bool $isScopedToTenant = false;

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

    public static function getNavigationLabel(): string
    {
        return __('admin.resources.owner_request.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.owner_request.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.owner_request.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return OwnerRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OwnerRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOwnerRequests::route('/'),
            'create' => CreateOwnerRequest::route('/create'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();

        // Operators (who can respond) see the operator inbox; Jawad owners see
        // only the requests they raised.
        if ($user && $user->can('owner_requests.edit')) {
            // A property-restricted operator only sees requests for their assigned
            // properties (idsForCurrentUser returns null for super_admin = all).
            $ids = AssignedAssets::idsForCurrentUser();

            return parent::getEloquentQuery()
                ->where('recipient', 'operator')
                // **A NULL `asset_id` means "no single mall", and it is the form's own default.**
                // `whereIn` never matches NULL, so a property-restricted operator's inbox silently
                // omitted every general request — the ones addressed to the operator rather than to
                // a property — and the owner's message sat unanswered with nothing on any screen to
                // say it existed. `PropertyField::PORTFOLIO_LEVEL` records why this screen offers
                // "All properties" in the first place; the read had never been told.
                //
                // Grouped, because `(recipient AND asset_id IN …) OR asset_id IS NULL` binds
                // AND-before-OR and the OR branch would escape the recipient filter — putting every
                // OWNER-directed request into the operator inbox.
                ->when($ids !== null, fn (Builder $q) => $q->where(
                    fn (Builder $w) => $w->whereIn('asset_id', $ids)->orWhereNull('asset_id')
                ))
                ->with(['creator', 'asset']);
        }

        return parent::getEloquentQuery()
            ->where('created_by_user_id', $user?->id)
            ->with(['creator', 'assignee', 'asset']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->whereIn('status', OwnerRequest::OPEN_STATUSES)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'info';
    }
}
