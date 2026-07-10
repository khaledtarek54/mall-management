<?php

namespace App\Filament\Admin\Resources\CamExpensePools\Pages;

use App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCamExpensePool extends CreateRecord
{
    protected static string $resource = CamExpensePoolResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Filament auto-stamps the tenant on create; this also validates the
        // client-supplied property against the user's visible set (property isolation).
        CamExpensePoolResource::assertAssetInScope($data['asset_id'] ?? null);

        return $data;
    }
}
