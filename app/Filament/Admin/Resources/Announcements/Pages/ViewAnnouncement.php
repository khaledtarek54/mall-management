<?php

namespace App\Filament\Admin\Resources\Announcements\Pages;

use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
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
        return [
            EditAction::make(),
            // A SOFT-DELETED NOTICE WAS A DEAD END. The list ships a `TrashedFilter`, so a deleted
            // announcement can be FOUND — and nothing anywhere could put it back. Measured at HEAD:
            // `grep -rl TrashedFilter app/Filament` returns 33 files and `grep -rl RestoreAction
            // app/Filament` returns 15 Edit pages — this resource is in the first list and not the
            // second.
            //
            // It belongs on the VIEW page rather than beside those fifteen: they restore from an
            // Edit page because their records stay editable, and `AnnouncementResource::canEdit()`
            // refuses a SENT notice outright — which is exactly the one worth recovering. A notice
            // is read by tenants through the portal and `/api/v1/me/announcements`, both of which
            // apply the ordinary soft-delete scope, so deleting one silently retracts a broadcast
            // from every retailer's feed with no way back.
            //
            // `RestoreAction` hides itself on a live record (its own `visible()` asks
            // `$record->trashed()`), so it costs nothing on the ordinary page, and
            // `AnnouncingRestoreAction` gates it on `canRestore()` = the `announcements.edit` right.
            //
            // NO `ForceDeleteAction`, and that is the deliberate departure from those peers:
            // `announcement_recipients.announcement_id` is `cascadeOnDelete`
            // (2026_08_15_190100_create_announcement_recipients_table.php:47), so purging a sent
            // notice destroys the record of who received it — the evidence this very screen exists
            // to show. Refusing rather than offering it is the house rule for a record with history.
            RestoreAction::make(),
        ];
    }
}
