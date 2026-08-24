<?php

namespace App\Filament\Admin\Resources\Announcements\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Models\Announcement;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAnnouncements extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = AnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
            CreateAction::make()
                ->label(__('admin.announcements.compose'))
                ->visible(fn () => AnnouncementResource::canCreate()),
        ];
    }

    /**
     * The three states a notice can be in, plus the one that needs chasing.
     *
     * `scheduled` carries the badge rather than `draft`: a draft is somebody's unfinished work and
     * nobody is waiting on it, while a scheduled notice is a commitment with a clock on it — and
     * if the queue worker is down, this is the count that says so.
     */
    public function getTabs(): array
    {
        return StatusTabs::build(AnnouncementResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'sent' => [
                'label' => __('admin.announcements.statuses.sent'),
                'query' => fn ($query) => $query->where('status', Announcement::STATUS_SENT),
            ],
            'scheduled' => [
                'label' => __('admin.announcements.statuses.scheduled'),
                'query' => fn ($query) => $query->where('status', Announcement::STATUS_SCHEDULED),
                'badge' => true,
                'color' => 'info',
            ],
            'draft' => [
                'label' => __('admin.announcements.statuses.draft'),
                'query' => fn ($query) => $query->where('status', Announcement::STATUS_DRAFT),
            ],
        ]);
    }
}
