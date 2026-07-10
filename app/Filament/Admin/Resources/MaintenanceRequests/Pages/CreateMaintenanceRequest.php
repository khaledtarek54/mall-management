<?php

namespace App\Filament\Admin\Resources\MaintenanceRequests\Pages;

use App\Enums\TenantRequestType;
use App\Filament\Admin\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Models\Department;
use App\Services\TenantRequestService;
use Filament\Resources\Pages\CreateRecord;

class CreateMaintenanceRequest extends CreateRecord
{
    protected static string $resource = MaintenanceRequestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The request's property comes from its unit — re-validate the submitted
        // unit is within the user's visible set (property isolation).
        MaintenanceRequestResource::assertUnitAssetInScope($data['unit_id'] ?? null);

        $data['submitted_at'] ??= now();

        $type = TenantRequestType::tryFrom($data['request_type'] ?? '') ?? TenantRequestType::default();

        // Per-type SLA via the SAME helper the service uses: maintenance gets the
        // operator-tunable window, complaint/access get their own, and inquiry /
        // billing / document get no deadline. (Previously this used the maintenance
        // window for every SLA type.)
        if (empty($data['target_resolution_at'])) {
            $data['target_resolution_at'] = app(TenantRequestService::class)
                ->targetResolutionFor($type, $data['priority'] ?? 'medium');
        }

        // Auto-route to the type's default team when staff left it unassigned.
        if (empty($data['department_id']) && ($slug = $type->defaultDepartmentSlug())) {
            $data['department_id'] = Department::query()->where('slug', $slug)->value('id');
        }

        return $data;
    }
}
