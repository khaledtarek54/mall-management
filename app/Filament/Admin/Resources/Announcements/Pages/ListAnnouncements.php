<?php

namespace App\Filament\Admin\Resources\Announcements\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAnnouncements extends ListRecords
{
    protected static string $resource = AnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make()
                ->label(__('admin.announcements.compose'))
                ->visible(fn () => AnnouncementResource::canCreate()),
        ];
    }

    /** Delivered vs. still queued — sent_at is null until the broadcast lands. */
    public function getTabs(): array
    {
        return StatusTabs::build(AnnouncementResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'sent' => ['label' => __('admin.announcements.filters.sent_only'), 'query' => fn ($query) => $query->whereNotNull('sent_at')],
            'pending' => ['label' => __('admin.announcements.filters.pending_only'), 'query' => fn ($query) => $query->whereNull('sent_at'), 'badge' => true, 'color' => 'warning'],
        ]);
    }
}
