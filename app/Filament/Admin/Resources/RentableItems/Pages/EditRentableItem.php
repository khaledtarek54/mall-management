<?php

namespace App\Filament\Admin\Resources\RentableItems\Pages;

use App\Filament\Admin\Resources\RentableItems\RentableItemResource;
use Filament\Resources\Pages\EditRecord;

class EditRentableItem extends EditRecord
{
    protected static string $resource = RentableItemResource::class;

    /** Filament stamps asset_id on create only — an edit can still move a row out of scope. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        RentableItemResource::assertAssetInScope($data['asset_id'] ?? null);

        return $data;
    }
}
