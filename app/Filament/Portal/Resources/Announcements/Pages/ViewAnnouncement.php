<?php

namespace App\Filament\Portal\Resources\Announcements\Pages;

use App\Filament\Portal\Resources\Announcements\AnnouncementResource;
use App\Models\Announcement;
use App\Services\Announcements\MarkAnnouncementReadAction;
use App\Support\Portal;
use Filament\Resources\Pages\ViewRecord;

/**
 * Reading the notice IS the read receipt, on the web.
 *
 * The portal differs from the mobile app here on purpose. The app posts an explicit
 * `POST …/{id}/read`, because it also opens the detail endpoint to render a push preview and a
 * prefetch must not count as somebody having seen the notice. Opening this page cannot be
 * anything but a person looking at it, so there is nothing to distinguish and no reason to make
 * the retailer click a second time to say so.
 *
 * The action is idempotent and records the FIRST read, so revisiting does not reset the timestamp
 * the operator is reading. `Portal::user()` is recorded — the portal knows which login is looking,
 * which the mobile API never does.
 *
 * Scoping is the resource's `getEloquentQuery()` (`liveFor` the signed-in tenant), so a record key
 * typed into the URL for another retailer's notice resolves to nothing before this runs.
 */
class ViewAnnouncement extends ViewRecord
{
    protected static string $resource = AnnouncementResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var Announcement $announcement */
        $announcement = $this->record;
        $tenant = Portal::tenant();

        if ($tenant !== null) {
            app(MarkAnnouncementReadAction::class)->handle($announcement, $tenant, Portal::user());
        }
    }
}
