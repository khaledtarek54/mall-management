<?php

namespace App\Filament\Admin\Resources\ServicePlans\Pages;

use App\Filament\Admin\Resources\ServicePlans\ServicePlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreateServicePlan extends CreateRecord
{
    protected static string $resource = ServicePlanResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        ServicePlanResource::assertAssetInScope($data['asset_id'] ?? null);

        return $data;
    }
}
