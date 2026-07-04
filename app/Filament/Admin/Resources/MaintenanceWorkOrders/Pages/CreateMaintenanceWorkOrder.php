<?php

namespace App\Filament\Admin\Resources\MaintenanceWorkOrders\Pages;

use App\Filament\Admin\Resources\MaintenanceWorkOrders\MaintenanceWorkOrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMaintenanceWorkOrder extends CreateRecord
{
    protected static string $resource = MaintenanceWorkOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        MaintenanceWorkOrderResource::assertAssetInScope($data['asset_id'] ?? null);

        return $data;
    }
}
