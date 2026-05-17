<?php

namespace App\Filament\Admin\Resources\MaintenanceRequests\Pages;

use App\Filament\Admin\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Services\MaintenanceRequestService;
use Filament\Resources\Pages\CreateRecord;

class CreateMaintenanceRequest extends CreateRecord
{
    protected static string $resource = MaintenanceRequestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['submitted_at'] ??= now();

        if (empty($data['target_resolution_at'])) {
            $data['target_resolution_at'] = app(MaintenanceRequestService::class)
                ->defaultTargetResolution($data['priority'] ?? 'medium');
        }

        return $data;
    }
}
