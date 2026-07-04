<?php

namespace App\Filament\Admin\Resources\MaintenancePlans\Pages;

use App\Filament\Admin\Resources\MaintenancePlans\MaintenancePlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMaintenancePlan extends CreateRecord
{
    protected static string $resource = MaintenancePlanResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        MaintenancePlanResource::assertAssetInScope($data['asset_id'] ?? null);

        return $data;
    }
}
