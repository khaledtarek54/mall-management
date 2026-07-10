<?php

namespace App\Filament\Admin\Resources\Leases\Pages;

use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Models\Lease;
use App\Services\LeaseCreationService;
use Filament\Resources\Pages\CreateRecord;

class CreateLease extends CreateRecord
{
    protected static string $resource = LeaseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The lease's property is its master unit's; every additional unit must
        // share it. Re-validate against the user's visible set (property isolation).
        LeaseResource::assertUnitAssetInScope($data['unit_id'] ?? null);
        LeaseResource::assertUnitsAssetInScope($this->data['additional_unit_ids'] ?? []);

        return $data;
    }

    /**
     * Standard Filament form creates the Lease row via Eloquent's default
     * flow. LeaseObserver handles the unit-status flip (active → occupied).
     * Charges aren't part of the form, so seed them here using the same
     * Egypt-VAT defaults the Quick New Lease wizard produces.
     */
    protected function afterCreate(): void
    {
        /** @var Lease $lease */
        $lease = $this->record;

        LeaseCreationService::seedStandardCharges(
            $lease,
            rent: (float) $lease->base_rent_monthly,
            service: (float) $lease->service_charge_monthly,
        );

        // Multi-unit lease: attach any additional units, keeping unit_id as the
        // master. The observer already created the master pivot row.
        $additional = $this->data['additional_unit_ids'] ?? [];
        if (! empty($additional)) {
            $lease->syncUnits([$lease->unit_id, ...$additional], $lease->unit_id);
        }
    }
}
