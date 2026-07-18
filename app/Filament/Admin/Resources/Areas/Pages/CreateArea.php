<?php

namespace App\Filament\Admin\Resources\Areas\Pages;

use App\Filament\Admin\Resources\Areas\AreaResource;
use App\Models\Area;
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

    /**
     * Supervisors is a relationship field — it syncs from component state AFTER the model saves, so
     * re-validate the attached staff against the zone's property here (the mutate hooks can't see
     * it). Strips + 403s any out-of-scope attach.
     */
    protected function afterCreate(): void
    {
        /** @var Area $area */
        $area = $this->record;

        AreaResource::assertSupervisorsInScope($area);
    }
}
