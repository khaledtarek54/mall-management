<?php

namespace App\Filament\Admin\Resources\MarketingPosts\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\MarketingPosts\MarketingPostResource;
use App\Models\MarketingPost;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMarketingPosts extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = MarketingPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
            CreateAction::make()
                ->label(__('admin.marketing_posts.compose'))
                ->visible(fn () => MarketingPostResource::canCreate()),
        ];
    }

    /**
     * The review queue is the first tab after "all" and carries a badge, because it is the one
     * state with something like an SLA: a retailer's Ramadan offer left unreviewed for a week is a
     * campaign they have lost, and nothing else on this screen degrades with time.
     *
     * "Live" uses `liveFor(null)` — the same predicate the shopper's feed uses, with the audience
     * filter off so the operator sees everything currently running rather than only the public
     * half.
     */
    public function getTabs(): array
    {
        return StatusTabs::build(MarketingPostResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'pending' => [
                'label' => __('admin.marketing_posts.tabs.pending'),
                'query' => fn ($query) => $query->where('status', MarketingPost::STATUS_PENDING),
                'badge' => true,
                'color' => 'warning',
            ],
            'live' => [
                'label' => __('admin.marketing_posts.tabs.live'),
                'query' => fn ($query) => $query->liveFor(null),
                'badge' => true,
                'color' => 'success',
            ],
            'draft' => [
                'label' => __('admin.marketing_posts.tabs.draft'),
                'query' => fn ($query) => $query->whereIn('status', [
                    MarketingPost::STATUS_DRAFT, MarketingPost::STATUS_REJECTED,
                ]),
            ],
            'archived' => [
                'label' => __('admin.marketing_posts.tabs.archived'),
                'query' => fn ($query) => $query->where('status', MarketingPost::STATUS_ARCHIVED),
            ],
        ]);
    }
}
