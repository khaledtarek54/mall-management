<?php

namespace App\Filament\Admin\Resources\Leases\Pages;

use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Models\Lease;
use App\Services\LeaseCreationService;
use Filament\Resources\Pages\CreateRecord;

class CreateLease extends CreateRecord
{
    protected static string $resource = LeaseResource::class;

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
    }
}
