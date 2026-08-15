<?php

namespace App\Filament\Portal\Resources\Announcements\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Portal\Resources\Announcements\AnnouncementResource;
use Filament\Resources\Pages\ListRecords;

class ListAnnouncements extends ListRecords
{
    protected static string $resource = AnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
        ];
    }
}
