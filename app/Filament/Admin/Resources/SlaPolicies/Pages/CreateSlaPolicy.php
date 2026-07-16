<?php

namespace App\Filament\Admin\Resources\SlaPolicies\Pages;

use App\Filament\Admin\Resources\SlaPolicies\SlaPolicyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSlaPolicy extends CreateRecord
{
    protected static string $resource = SlaPolicyResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        SlaPolicyResource::assertAssetInScope($data['asset_id'] ?? null);

        return $data;
    }
}
