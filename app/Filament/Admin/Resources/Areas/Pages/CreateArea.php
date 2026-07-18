<?php

namespace App\Filament\Admin\Resources\Areas\Pages;

use App\Filament\Admin\Resources\Areas\AreaResource;
use Filament\Resources\Pages\CreateRecord;

class CreateArea extends CreateRecord
{
    protected static string $resource = AreaResource::class;

    /**
     * In "All Properties" mode the property Select is enabled and client-supplied,
     * so re-validate the submitted asset_id against the user's visible set.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        AreaResource::assertAssetInScope($data['asset_id'] ?? null);

        return $data;
    }
}
