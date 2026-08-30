<?php

namespace App\Filament\Admin\Actions;

use App\Filament\Admin\Resources\MarketingPosts\MarketingPostResource;
use App\Models\MarketingPost;
use App\Services\MarketingPost\ApproveMarketingPostService;
use App\Services\MarketingPost\ArchiveMarketingPostService;
use App\Services\MarketingPost\PublishMarketingPostService;
use App\Services\MarketingPost\RejectMarketingPostService;
use App\Support\RowActionPolicy;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * **Everything you can DO to a marketing post, defined once.**
 *
 * `approve`, `reject`, `publish` and `archive` lived inline in `MarketingPostsTable`,
 * so they were reachable from the LIST and the record's
 * own page carried Delete and little else — backwards from the record-hub architecture this
 * project took from Yardi: **the list finds, the record acts**. Defined here, composed onto the
 * record page, so the two surfaces can never drift.
 *
 * Safe to move, and measured rather than assumed: every role that can perform these acts can open
 * the page it moved to. Four resources failed that check — an act held by a role that
 * deliberately lacks `{module}.edit` — and kept their verbs on the row; see
 * {@see RowActionPolicy}.
 */
class MarketingPostActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
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
        ];
    }
}
