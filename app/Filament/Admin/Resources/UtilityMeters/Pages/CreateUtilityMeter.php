<?php

namespace App\Filament\Admin\Resources\UtilityMeters\Pages;

use App\Filament\Admin\Resources\UtilityMeters\UtilityMeterResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUtilityMeter extends CreateRecord
{
    protected static string $resource = UtilityMeterResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Filament auto-stamps the tenant on create; this also validates the
        // client-supplied property against the user's visible set (property isolation).
        UtilityMeterResource::assertAssetInScope($data['asset_id'] ?? null);

        return $data;
    }
}
