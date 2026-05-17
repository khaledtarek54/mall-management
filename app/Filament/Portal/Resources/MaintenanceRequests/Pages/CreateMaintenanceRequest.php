<?php

namespace App\Filament\Portal\Resources\MaintenanceRequests\Pages;

use App\Filament\Portal\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Models\MaintenanceRequest;
use App\Models\Tenant;
use App\Services\MaintenanceRequestService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateMaintenanceRequest extends CreateRecord
{
    protected static string $resource = MaintenanceRequestResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Tenant $tenant */
        $tenant = Auth::guard('portal')->user();

        $request = app(MaintenanceRequestService::class)->create($data, $tenant);

        // Attach any uploaded media that Filament's SpatieMediaLibraryFileUpload
        // staged against the form: it normally writes when the model is saved by
        // Filament. We saved through a service, so we re-attach below by
        // letting Filament call its afterCreate media-sync as usual; passing the
        // already-created model into $this->record makes that work.

        return $request;
    }

    protected function getCreatedNotification(): ?Notification
    {
        /** @var MaintenanceRequest $record */
        $record = $this->record;

        return Notification::make()
            ->title(__('admin.maintenance.created_title'))
            ->body(__('admin.maintenance.created_body', ['ref' => $record->reference]))
            ->success();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
