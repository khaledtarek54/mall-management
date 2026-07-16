<?php

namespace App\Filament\Admin\Resources\Equipment\Pages;

use App\Filament\Admin\Resources\Equipment\EquipmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEquipment extends CreateRecord
{
    protected static string $resource = EquipmentResource::class;

    /**
     * In "All Properties" mode the property Select is enabled and client-supplied, so
     * re-validate the submitted asset_id against the user's visible set.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        EquipmentResource::assertAssetInScope($data['asset_id'] ?? null);

        return $data;
    }
}
