<?php

namespace App\Filament\Portal\Resources\MarketingPosts;

use App\Filament\Portal\Resources\MarketingPosts\Pages\CreateMarketingPost;
use App\Filament\Portal\Resources\MarketingPosts\Pages\EditMarketingPost;
use App\Filament\Portal\Resources\MarketingPosts\Pages\ListMarketingPosts;
use App\Filament\Portal\Resources\MarketingPosts\Schemas\MarketingPostForm;
use App\Filament\Portal\Resources\MarketingPosts\Tables\MarketingPostsTable;
use App\Models\MarketingPost;
use App\Support\Modules;
use App\Support\Portal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The retailer's own offers, on /portal — the web twin of `/api/v1/me/marketing-posts`.
 *
 * A retailer composes here and sends it to the mall; nothing on this screen can publish. The two
 * things that enforce that:
 *
 *  - **`canEdit()` is state-dependent**, not a flat true. A post the retailer has already
 *    submitted, or that the mall has published, is theirs to read and not to change — otherwise
 *    they could swap the artwork of an approved offer for something nobody reviewed, and approval
 *    would mean nothing.
 *  - **`status` is never a form field.** The only transitions available are the Submit and
 *    Withdraw actions, both of which run through
 *    {@see \App\Services\MarketingPost\SubmitMarketingPostService}, which ends at `pending`.
 *
 * Reads are scoped to the signed-in tenant in `getEloquentQuery()`; writes additionally re-check
 * that the retailer trades in the chosen property (the service's `assertTenantTradesIn`), because
 * a Select's `options()` scope the rendering and not the payload.
 */
class MarketingPostResource extends Resource
{
    /**
     * Deliberately absent from global search — stated in `SearchPolicy::GLOBAL_SEARCH_EXEMPT`,
     * which the conformance gate reads. Do not flip without removing that entry.
     */
    protected static bool $isGloballySearchable = false;

    protected static ?string $model = MarketingPost::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string
    {
        return __('admin.marketing_posts.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.marketing_posts.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.marketing_posts.plural');
    }

    /** Hidden entirely when the operator has the shopper feed switched off. */
    public static function shouldRegisterNavigation(): bool
    {
        return Modules::enabled('marketing_posts');
    }

    public static function canViewAny(): bool
    {
        return Modules::enabled('marketing_posts');
    }

    /**
     * This tenant's posts and nothing else. `tenant_id` is a direct column here (unlike sales
     * declarations, which reach the tenant through the lease), so the scope is one clause.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', Portal::tenantId());
    }

    /** Only the tenant-admin composes; other portal logins are read-only (the portal's rule). */
    public static function canCreate(): bool
    {
        return Modules::enabled('marketing_posts') && Portal::isAdmin();
    }

    /**
     * Editable only while it is still the retailer's — a draft, or one the mall returned. Once
     * submitted or published it is read-only to them. See the class docblock.
     */
    public static function canEdit(Model $record): bool
    {
        return Modules::enabled('marketing_posts')
            && Portal::isAdmin()
            && $record instanceof MarketingPost
            && $record->isEditableByTenant();
    }

    /** Same window as editing: a retailer may bin their own draft, never a live offer. */
    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record);
    }

    public static function form(Schema $schema): Schema
    {
        return MarketingPostForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MarketingPostsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMarketingPosts::route('/'),
            'create' => CreateMarketingPost::route('/create'),
            'edit' => EditMarketingPost::route('/{record}/edit'),
        ];
    }
}
