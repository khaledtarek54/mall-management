<?php

namespace App\Filament\Admin\Resources\Announcements\Pages;

use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use App\Filament\Admin\Resources\Announcements\Pages\Concerns\TranslatesDeliveryChoice;
use App\Models\Announcement;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateAnnouncement extends CreateRecord
{
    use TranslatesDeliveryChoice;

    protected static string $resource = AnnouncementResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Re-validate the target property server-side (All-Properties tamper guard),
        // then stamp the author for the audit trail.
        AnnouncementResource::assertAssetInScope($data['asset_id'] ?? null);
        $data['created_by'] = Auth::id();

        $data = $this->applyDeliveryChoice($data);

        // Broadcasting is its own authority now that notices have a draft state — an assistant may
        // compose without being the person who pushes it to every retailer's phone. Refused in the
        // ACTION, not only in the form: `visible()` shapes the UI, `abort_unless` is the gate.
        abort_unless(
            ! $this->shouldBroadcastAfterSave || AnnouncementResource::canSend(),
            403
        );

        return $data;
    }

    protected function afterCreate(): void
    {
        if (! $this->shouldBroadcastAfterSave) {
            return;
        }

        /** @var Announcement $record */
        $record = $this->record;

        // **REFUSE HERE, WHERE THE OPERATOR IS.** The broadcast itself goes off the request thread
        // — a property can have many tenants (see `BroadcastAnnouncement`) — and that job is
        // `tries = 1` on the `database` queue, so a refusal inside it becomes a `failed_jobs` row
        // the operator never sees: the record is created, the success toast shows, `sent_at` stays
        // null, and nothing on screen says the broadcast was refused.
        //
        // The window check is cheap and needs no tenants, so it is asked on the request. The header
        // *Send* action already gets its refusal as a toast because it runs inline; this is the
        // create-and-send path, which did not.
        try {
            $record->assertSendable();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        $record->broadcast();
    }
}
