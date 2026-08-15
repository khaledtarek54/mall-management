<?php

namespace App\Filament\Admin\Resources\FacilityWorkOrders\Pages;

use App\Filament\Admin\Resources\FacilityWorkOrders\FacilityWorkOrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFacilityWorkOrder extends CreateRecord
{
    protected static string $resource = FacilityWorkOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        FacilityWorkOrderResource::assertAssetInScope($data['asset_id'] ?? null);

        return $data;
    }
}
