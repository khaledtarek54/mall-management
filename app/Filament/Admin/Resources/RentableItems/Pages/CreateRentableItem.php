<?php

namespace App\Filament\Admin\Resources\RentableItems\Pages;

use App\Filament\Admin\Resources\RentableItems\RentableItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRentableItem extends CreateRecord
{
    protected static string $resource = RentableItemResource::class;

    /** The submitted asset_id is client-supplied — re-validate it against the user's scope. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        RentableItemResource::assertAssetInScope($data['asset_id'] ?? null);

        return $data;
    }
}
