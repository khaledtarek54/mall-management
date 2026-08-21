<?php

namespace App\Filament\Admin\Resources\TenantRequests\Pages;

use App\Enums\TenantRequestType;
use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Models\Department;
use App\Models\Unit;
use App\Services\TenantRequestService;
use App\Support\SlaResolver;
use Filament\Resources\Pages\CreateRecord;

class CreateTenantRequest extends CreateRecord
{
    protected static string $resource = TenantRequestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The request's property comes from its unit — re-validate the submitted
        // unit is within the user's visible set (property isolation).
        TenantRequestResource::assertUnitAssetInScope($data['unit_id'] ?? null);

        $data['submitted_at'] ??= now();

        $type = TenantRequestType::tryFrom($data['request_type'] ?? '') ?? TenantRequestType::default();

        // Per-type SLA via the SAME helper the service uses: maintenance gets the
        // operator-tunable window, complaint/access get their own, and inquiry /
        // billing / document get no deadline. (Previously this used the maintenance
        // window for every SLA type.)
        // Module 11 has TWO intake roads — the portal/API goes through the service, this is the
        // admin one — and both must resolve the SLA the same way, on the same clock (EG-38).
        $priority = $data['priority'] ?? 'medium';
        $assetId = isset($data['unit_id'])
            ? Unit::withTrashed()->whereKey($data['unit_id'])->value('asset_id')
            : null;
        $clock = SlaResolver::clockFor($assetId, $priority);

        if (empty($data['target_resolution_at'])) {
            $data['target_resolution_at'] = app(TenantRequestService::class)
                ->targetResolutionFor($type, $priority, $assetId, $clock);
        }

        // Frozen even when the operator typed their own deadline: the clock is what the BREACH is
        // later measured against, and a hand-set target is still measured against something.
        $data['sla_clock'] = $clock;

        // Auto-route to the type's default team when staff left it unassigned.
        if (empty($data['department_id']) && ($slug = $type->defaultDepartmentSlug())) {
            $data['department_id'] = Department::query()->where('slug', $slug)->value('id');
        }

        return $data;
    }
}
