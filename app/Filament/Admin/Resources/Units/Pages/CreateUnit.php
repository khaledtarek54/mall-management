<?php

namespace App\Filament\Admin\Resources\Units\Pages;

use App\Filament\Admin\Resources\Units\UnitResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUnit extends CreateRecord
{
    protected static string $resource = UnitResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Filament auto-stamps the tenant on create; this also validates the
        // client-supplied property against the user's visible set (property isolation).
        UnitResource::assertAssetInScope($data['asset_id'] ?? null);

        return $data;
    }
}
