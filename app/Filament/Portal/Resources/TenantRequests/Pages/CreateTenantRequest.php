<?php

namespace App\Filament\Portal\Resources\TenantRequests\Pages;

use App\Filament\Portal\Resources\TenantRequests\TenantRequestResource;
use App\Models\TenantRequest;
use App\Models\Tenant;
use App\Services\TenantRequestService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateTenantRequest extends CreateRecord
{
    protected static string $resource = TenantRequestResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Tenant $tenant */
        $tenant = \App\Support\Portal::tenant();

        $request = app(TenantRequestService::class)->create($data, $tenant);

        // Attach any uploaded media that Filament's SpatieMediaLibraryFileUpload
        // staged against the form: it normally writes when the model is saved by
        // Filament. We saved through a service, so we re-attach below by
        // letting Filament call its afterCreate media-sync as usual; passing the
        // already-created model into $this->record makes that work.

        return $request;
    }

    protected function getCreatedNotification(): ?Notification
    {
        /** @var TenantRequest $record */
        $record = $this->record;

        return Notification::make()
            ->title(__('admin.tenant_requests.created_title'))
            ->body(__('admin.tenant_requests.created_body', ['ref' => $record->reference]))
            ->success();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
