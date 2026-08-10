<?php

namespace App\Filament\Admin\Resources\MarketingPosts\Tables;

use App\Filament\Admin\Resources\MarketingPosts\MarketingPostResource;
use App\Models\MarketingPost;
use App\Services\MarketingPost\ApproveMarketingPostService;
use App\Services\MarketingPost\ArchiveMarketingPostService;
use App\Services\MarketingPost\PublishMarketingPostService;
use App\Services\MarketingPost\RejectMarketingPostService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * The register + the review queue.
 *
 * **Every write action is gated TWICE** — once in `visible()` (the UI) and once in `authorize()`
 * (the gate) — and the predicate is named once so the two cannot drift. On the Filament version
 * shipped here a hidden action IS refused at dispatch, so `visible()` alone would in fact hold
 * today; the double gate is there because that is an upstream implementation detail that a release
 * can change, and if it does, every `visible()`-only write reopens at once. See the note in
 * CLAUDE.md and `FilamentActionDispatchContractTest`.
 */
class MarketingPostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // SpatieMediaLibraryImageColumn, not ImageColumn — the plain column has no
                // `collection()` and renders the raw attribute, which for a media-backed field is
                // nothing at all. It fatals on render, so only a table test WITH ROWS catches it.
                SpatieMediaLibraryImageColumn::make('hero')
                    ->label('')
                    ->collection(MarketingPost::HERO_COLLECTION)
                    ->height(40)
                    ->extraImgAttributes(['class' => 'rounded object-cover']),

                TextColumn::make('title')
                    ->label(__('admin.marketing_posts.fields.title'))
                    ->weight('bold')
                    ->searchable()
                    ->description(fn (MarketingPost $r) => $r->discount_label ?: str($r->summary ?? '')->limit(60)),

                TextColumn::make('tenant.name')
                    ->label(__('admin.marketing_posts.fields.store'))
                    // The sign above the door, not the billing entity — the same name the shopper
                    // sees, so the operator is reading one vocabulary rather than two.
                    ->formatStateUsing(fn (MarketingPost $r) => $r->tenant?->storeName())
                    ->placeholder(__('admin.marketing_posts.mall_wide'))
                    ->searchable(),

                TextColumn::make('type')
                    ->label(__('admin.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? __("admin.marketing_posts.types.{$state}") : '—')
                    ->color('gray'),

                TextColumn::make('status')
                    ->label(__('admin.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? __("admin.marketing_posts.statuses.{$state}") : '—')
                    ->color(fn (?string $state) => match ($state) {
                        MarketingPost::STATUS_PUBLISHED => 'success',
                        MarketingPost::STATUS_PENDING => 'warning',
                        MarketingPost::STATUS_REJECTED => 'danger',
                        MarketingPost::STATUS_ARCHIVED => 'gray',
                        default => 'info',
                    }),

                // "Published" is not the same as "on screen": a published post can be waiting for
                // its display window or sitting past it until the hourly sweep files it. The
                // operator's real question is the second one, so it gets its own column.
                TextColumn::make('live')
                    ->label(__('admin.marketing_posts.fields.live'))
                    ->badge()
                    ->state(fn (MarketingPost $r) => $r->isPublished() && ! $r->hasExpired()
                        && (($r->display_from ?? $r->starts_at) === null || ($r->display_from ?? $r->starts_at)->lte(now()))
                            ? __('admin.marketing_posts.live_yes')
                            : __('admin.marketing_posts.live_no'))
                    ->color(fn (MarketingPost $r) => $r->isPublished() && ! $r->hasExpired() ? 'success' : 'gray'),

                TextColumn::make('ends_at')
                    ->label(__('admin.marketing_posts.fields.ends_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('asset.name')
                    ->label(__('admin.marketing_posts.fields.property'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                TextColumn::make('view_count')
                    ->label(__('admin.marketing_posts.fields.views'))
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('click_count')
                    ->label(__('admin.marketing_posts.fields.clicks'))
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.fields.status'))
                    ->options(fn () => collect(MarketingPost::STATUSES)
                        ->mapWithKeys(fn ($s) => [$s => __("admin.marketing_posts.statuses.{$s}")])),
                SelectFilter::make('type')
                    ->label(__('admin.fields.type'))
                    ->options(fn () => collect(MarketingPost::TYPES)
                        ->mapWithKeys(fn ($t) => [$t => __("admin.marketing_posts.types.{$t}")])),
                TernaryFilter::make('is_featured')
                    ->label(__('admin.marketing_posts.fields.is_featured')),
                // "What is actually on screen right now" — through the SAME predicate the public
                // API uses, so the operator's answer and the shopper's cannot disagree.
                TernaryFilter::make('live')
                    ->label(__('admin.marketing_posts.filters.live'))
                    ->placeholder(__('admin.tabs.all'))
                    ->queries(
                        true: fn ($query) => $query->liveFor(null),
                        false: fn ($query) => $query->whereNotIn(
                            'id',
                            MarketingPost::query()->liveFor(null)->select('id')
                        ),
                        blank: fn ($query) => $query,
                    ),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (MarketingPost $r) => MarketingPostResource::canEdit($r)),

                // ---- The review queue's two verdicts.
                Action::make('approve')
                    ->label(__('admin.marketing_posts.actions.approve'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.marketing_posts.actions.approve_confirm'))
                    ->visible(fn (MarketingPost $r) => $r->isAwaitingReview() && MarketingPostResource::canApprove())
                    ->authorize(fn (MarketingPost $r) => $r->isAwaitingReview() && MarketingPostResource::canApprove())
                    ->action(function (MarketingPost $r) {
                        // The refusals inside the service (no artwork, window already over) are
                        // DomainExceptions — they render as a toast via bootstrap/app.php rather
                        // than an error page. Caught here only to keep the operator on the queue.
                        try {
                            app(ApproveMarketingPostService::class)->handle($r, Auth::user());
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title(__('admin.marketing_posts.notices.approved'))->send();
                    }),

                Action::make('reject')
                    ->label(__('admin.marketing_posts.actions.reject'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (MarketingPost $r) => $r->isAwaitingReview() && MarketingPostResource::canApprove())
                    ->authorize(fn (MarketingPost $r) => $r->isAwaitingReview() && MarketingPostResource::canApprove())
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('admin.marketing_posts.actions.reject_reason'))
                            ->helperText(__('admin.marketing_posts.actions.reject_reason_hint'))
                            // Required at the form layer AND in the service. The service's copy is
                            // the one that binds (the API and the portal reach it too); this one
                            // just fails faster and in the right place.
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (MarketingPost $r, array $data) {
                        try {
                            app(RejectMarketingPostService::class)->handle($r, Auth::user(), $data['reason'] ?? '');
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title(__('admin.marketing_posts.notices.rejected'))->send();
                    }),

                // ---- Operator-composed content goes live without a queue step.
                Action::make('publish')
                    ->label(__('admin.marketing_posts.actions.publish'))
                    ->icon('heroicon-o-megaphone')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (MarketingPost $r) => ! $r->isPublished() && ! $r->isAwaitingReview()
                        && MarketingPostResource::canApprove())
                    ->authorize(fn (MarketingPost $r) => ! $r->isPublished() && ! $r->isAwaitingReview()
                        && MarketingPostResource::canApprove())
                    ->action(function (MarketingPost $r) {
                        try {
                            app(PublishMarketingPostService::class)->handle($r, Auth::user());
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title(__('admin.marketing_posts.notices.published'))->send();
                    }),

                // ---- The retirement path. Offered far more prominently than delete, because it
                // keeps the campaign and its numbers (see ArchiveMarketingPostService).
                Action::make('archive')
                    ->label(__('admin.marketing_posts.actions.archive'))
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.marketing_posts.actions.archive_confirm'))
                    ->visible(fn (MarketingPost $r) => $r->isPublished() && MarketingPostResource::canEdit($r))
                    ->authorize(fn (MarketingPost $r) => $r->isPublished() && MarketingPostResource::canEdit($r))
                    ->action(function (MarketingPost $r) {
                        try {
                            app(ArchiveMarketingPostService::class)->handle($r, Auth::user());
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title(__('admin.marketing_posts.notices.archived'))->send();
                    }),

                DeleteAction::make()
                    ->visible(fn (MarketingPost $r) => MarketingPostResource::canDelete($r)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => MarketingPostResource::canDeleteAny()),
                ]),
            ])
            // Featured first, then priority, then newest — the SAME order the shopper's feed uses,
            // so what the operator sees at the top is what a shopper sees at the top.
            ->modifyQueryUsing(fn ($query) => $query->feedOrder())
            ->emptyStateIcon('heroicon-o-sparkles')
            ->emptyStateHeading(__('admin.empty.marketing_posts.heading'))
            ->emptyStateDescription(__('admin.empty.marketing_posts.description'));
    }
}
