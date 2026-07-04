<?php

namespace App\Filament\Admin\Resources\Warehouses\Pages;

use App\Filament\Admin\Resources\Warehouses\WarehouseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWarehouse extends CreateRecord
{
    protected static string $resource = WarehouseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Re-validate the target property server-side (All-Properties tamper guard).
        WarehouseResource::assertAssetInScope($data['asset_id'] ?? null);

        return $data;
    }
}
