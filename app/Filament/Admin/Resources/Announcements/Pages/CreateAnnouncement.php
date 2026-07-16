<?php

namespace App\Filament\Admin\Resources\Announcements\Pages;

use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use App\Jobs\BroadcastAnnouncement;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateAnnouncement extends CreateRecord
{
    protected static string $resource = AnnouncementResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Re-validate the target property server-side (All-Properties tamper guard),
        // then stamp the author for the audit trail.
        AnnouncementResource::assertAssetInScope($data['asset_id'] ?? null);
        $data['created_by'] = Auth::id();

        return $data;
    }

    protected function afterCreate(): void
    {
        // Composing IS sending — fan out to the property's active tenants off the
        // request thread (see BroadcastAnnouncement).
        BroadcastAnnouncement::dispatch($this->record);
    }
}
