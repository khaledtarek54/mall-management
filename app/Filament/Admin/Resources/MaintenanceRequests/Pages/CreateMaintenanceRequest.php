<?php

namespace App\Filament\Admin\Resources\MaintenanceRequests\Pages;

use App\Enums\TenantRequestType;
use App\Filament\Admin\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Models\Department;
use App\Services\MaintenanceRequestService;
use Filament\Resources\Pages\CreateRecord;

class CreateMaintenanceRequest extends CreateRecord
{
    protected static string $resource = MaintenanceRequestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['submitted_at'] ??= now();

        $type = TenantRequestType::tryFrom($data['request_type'] ?? '') ?? TenantRequestType::default();

        // Only types governed by an SLA get a resolution deadline; an inquiry /
        // billing query / document request carries none. Maintenance keeps the
        // operator-tunable target from the settings-backed service.
        if (empty($data['target_resolution_at'])) {
            $data['target_resolution_at'] = $type->hasSla()
                ? app(MaintenanceRequestService::class)->defaultTargetResolution($data['priority'] ?? 'medium')
                : null;
        }

        // Auto-route to the type's default team when staff left it unassigned.
        if (empty($data['department_id']) && ($slug = $type->defaultDepartmentSlug())) {
            $data['department_id'] = Department::query()->where('slug', $slug)->value('id');
        }

        return $data;
    }
}
