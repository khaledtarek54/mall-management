<?php

namespace App\Filament\Admin\Resources\MarketingPosts;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\MarketingPosts\Pages\CreateMarketingPost;
use App\Filament\Admin\Resources\MarketingPosts\Pages\EditMarketingPost;
use App\Filament\Admin\Resources\MarketingPosts\Pages\ListMarketingPosts;
use App\Filament\Admin\Resources\MarketingPosts\Schemas\MarketingPostForm;
use App\Filament\Admin\Resources\MarketingPosts\Tables\MarketingPostsTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\MarketingPost;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The mall's shopper-facing feed: offers, events and news (module 36).
 *
 * Two populations share this screen — content the marketing team composed, and submissions
 * retailers sent in for review. The review queue is a TAB rather than a separate resource, because
 * they are the same record at different points in one workflow, and splitting them would mean two
 * places to look for "what are we running".
 *
 * Like {@see \App\Filament\Admin\Resources\Announcements\AnnouncementResource}, the target property
 * is CLIENT-SUPPLIED (the operator picks which mall a post runs in), so Filament's tenancy
 * ownership is deliberately off — its `creating` hook would force-associate `asset_id` with the
 * current panel tenant and silently move the post. Reads are scoped in `getEloquentQuery()`;
 * writes are re-validated by `assertAssetInScope()` on BOTH create and edit (Filament stamps
 * asset_id on create only, so an edit could otherwise relocate a post to another mall).
 */
class MarketingPostResource extends Resource
{
    use BypassesFilamentTenantAutoScope;
    use GuardsAssetInScope;
    use RoleGatedActions;
    use SearchesNormalizedText;

    protected static ?string $model = MarketingPost::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'title';

    protected static function permissionModule(): string
    {
        return 'marketing_posts';
    }

    /**
     * Deciding what the mall says to the public is its own authority, separate from editing a
     * draft. Named here so the table's actions and their `visible()` twins cannot drift — the
     * project's rule is to name the predicate once and call it from both.
     */
    public static function canApprove(): bool
    {
        return static::hasPermission('approve');
    }

    /** Property-scope the list ourselves (Filament's auto-tenancy is off — see class docblock). */
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

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.marketing');
    }

    /**
     * How many retailer submissions are waiting on a human. The queue is the one thing on this
     * screen with an SLA of sorts — a retailer whose Ramadan offer sits unreviewed for a week has
     * lost the campaign — so it earns a badge rather than being a tab someone remembers to open.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! Auth::check() || ! static::canViewAny()) {
            return null;
        }

        $count = static::getEloquentQuery()
            ->where('status', MarketingPost::STATUS_PENDING)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
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

    /**
     * Searched through the fold-normalized blob, never a raw column — an operator hunting «رمضان»
     * must match whichever spelling was typed. `tenant.search_text` (not `tenant.name`) for the
     * same reason: a path pointed at an unfolded column compares a folded query against raw text
     * and silently misses exactly the Arabic spellings the fold exists to fix.
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

    /**
     * @param  MarketingPost  $record  Narrowed from Filament's Model signature so static analysis
     *                                 can see the columns.
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            __('admin.marketing_posts.fields.store') => $record->tenant?->storeName() ?? __('admin.marketing_posts.mall_wide'),
            __('admin.fields.status') => __("admin.marketing_posts.statuses.{$record->status}"),
            __('admin.fields.description') => Str::limit((string) $record->summary, 60),
        ];
    }
}
