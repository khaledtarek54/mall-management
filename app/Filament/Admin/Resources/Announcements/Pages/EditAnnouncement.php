<?php

namespace App\Filament\Admin\Resources\Announcements\Pages;

use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use App\Filament\Admin\Resources\Announcements\Pages\Concerns\TranslatesDeliveryChoice;
use App\Models\Announcement;
use Filament\Resources\Pages\EditRecord;

/**
 * Editing exists only for notices that have NOT been broadcast.
 *
 * `AnnouncementResource::canEdit()` answers false for a sent one, so Filament never offers this
 * page for it — but the record key arrives from the browser, so the page re-checks the record's
 * own state as well. A sent notice is evidence: tenants hold a push quoting its text and
 * `announcement_recipients` records who received it. It is corrected by sending another notice,
 * which is the only correction a tenant can actually see.
 */
class EditAnnouncement extends EditRecord
{
    use TranslatesDeliveryChoice;

    protected static string $resource = AnnouncementResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->restoreDeliveryChoice($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Announcement $record */
        $record = $this->record;

        // The gate, not the decoration: a sent notice is immutable whatever the URL says.
        abort_unless($record->isEditable(), 403);

        // Filament only stamps asset_id on create, never on update — so an edited asset_id has to
        // be re-checked here or the property scope is a create-time formality.
        AnnouncementResource::assertAssetInScope($data['asset_id'] ?? $record->asset_id);

        $data = $this->applyDeliveryChoice($data);

        abort_unless(
            ! $this->shouldBroadcastAfterSave || AnnouncementResource::canSend(),
            403
        );

        return $data;
    }

    protected function afterSave(): void
    {
        if (! $this->shouldBroadcastAfterSave) {
            return;
        }

        /** @var Announcement $record */
        $record = $this->record;

        $record->broadcast();
    }
}
