<?php

namespace App\Filament\Admin\Resources\UnitOwnerships\Pages;

use App\Filament\Admin\Resources\UnitOwnerships\UnitOwnershipResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUnitOwnership extends CreateRecord
{
    protected static string $resource = UnitOwnershipResource::class;

    /**
     * The property Select is client-supplied when the panel is not scoped to one mall, so
     * re-validate the submitted asset_id against the user's visible set.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        UnitOwnershipResource::assertAssetInScope($data['asset_id'] ?? null);

        return $data;
    }
}
