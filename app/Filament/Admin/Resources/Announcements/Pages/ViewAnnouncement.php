<?php

namespace App\Filament\Admin\Resources\Announcements\Pages;

use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * The notice and its read receipts.
 *
 * A sent announcement has no edit page — it is evidence — so without this screen its recipient
 * list would have nowhere to live, and "has that store seen it" would stay unanswerable from the
 * panel. The Edit action appears only while the notice is still a draft or scheduled;
 * `AnnouncementResource::canEdit()` is the single predicate that decides, so the button and the
 * page agree by construction.
 */
class ViewAnnouncement extends ViewRecord
{
    protected static string $resource = AnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
