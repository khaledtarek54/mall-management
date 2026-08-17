<?php

namespace App\Filament\Portal\Resources\MarketingPosts\Tables;

use App\Filament\Portal\Resources\MarketingPosts\MarketingPostResource;
use App\Models\MarketingPost;
use App\Services\MarketingPost\RejectMarketingPostService;
use App\Services\MarketingPost\SubmitMarketingPostService;
use App\Support\Portal;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The retailer's own list. Two actions, both ending short of publication.
 *
 * The column that earns its place is `review_notes`: when the mall returns a post, the reason is
 * on the row rather than buried in a detail screen. A retailer who has to click to find out why
 * is a retailer who resubmits the same artwork — the loop the whole review workflow exists to
 * avoid, and the reason
 * {@see RejectMarketingPostService} refuses to run without a reason.
 */
class MarketingPostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
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

                // Why it came back. On the row, not one click away — see the class docblock.
                TextColumn::make('review_notes')
                    ->label(__('admin.marketing_posts.portal.mall_said'))
                    ->wrap()
                    ->placeholder('—')
                    ->color('danger'),

                TextColumn::make('asset.name')
                    ->label(__('admin.marketing_posts.fields.property'))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('ends_at')
                    ->label(__('admin.marketing_posts.fields.ends_at'))
                    ->dateTime('d/m/Y')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('view_count')
                    ->label(__('admin.marketing_posts.fields.views'))
                    ->numeric()
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.fields.status'))
                    ->options(fn () => collect(MarketingPost::STATUSES)
                        ->mapWithKeys(fn ($s) => [$s => __("admin.marketing_posts.statuses.{$s}")])),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (MarketingPost $r) => MarketingPostResource::canEdit($r)),

                Action::make('submit')
                    ->label(__('admin.marketing_posts.portal.submit'))
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.marketing_posts.portal.submit_confirm'))
                    // Double-gated: the portal has no permission model beyond `is_admin`, so the
                    // predicate is "may this login write" AND "is this post still mine to send".
                    ->visible(fn (MarketingPost $r) => Portal::isAdmin() && $r->isEditableByTenant())
                    ->authorize(fn (MarketingPost $r) => Portal::isAdmin() && $r->isEditableByTenant())
                    ->action(function (MarketingPost $r) {
                        try {
                            app(SubmitMarketingPostService::class)->handle($r, Portal::user());
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title(__('api.marketing_post_submitted'))->send();
                    }),

                Action::make('withdraw')
                    ->label(__('admin.marketing_posts.portal.withdraw'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (MarketingPost $r) => Portal::isAdmin() && $r->isAwaitingReview())
                    ->authorize(fn (MarketingPost $r) => Portal::isAdmin() && $r->isAwaitingReview())
                    ->action(function (MarketingPost $r) {
                        try {
                            app(SubmitMarketingPostService::class)->withdraw($r);
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title(__('api.marketing_post_withdrawn'))->send();
                    }),

                DeleteAction::make()
                    ->visible(fn (MarketingPost $r) => MarketingPostResource::canDelete($r)),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateIcon('heroicon-o-sparkles')
            ->emptyStateHeading(__('admin.marketing_posts.portal.empty_heading'))
            ->emptyStateDescription(__('admin.marketing_posts.portal.empty_description'));
    }
}
