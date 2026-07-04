<?php

namespace App\Filament\Admin\Resources\FixedAssets\Pages;

use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFixedAsset extends CreateRecord
{
    protected static string $resource = FixedAssetResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Re-validate the target property server-side (All-Properties tamper guard).
        FixedAssetResource::assertAssetInScope($data['asset_id'] ?? null);

        return $data;
    }
}
