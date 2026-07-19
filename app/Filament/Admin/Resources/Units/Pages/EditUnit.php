<?php

namespace App\Filament\Admin\Resources\Units\Pages;

use App\Filament\Admin\Resources\Units\UnitResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditUnit extends EditRecord
{
    protected static string $resource = UnitResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Block re-homing into a property outside the user's visible set — asset_id is
        // editable in All-Properties mode and is NOT re-stamped by Filament on update.
        $assetId = $data['asset_id'] ?? $this->record->asset_id;
        UnitResource::assertAssetInScope($assetId);

        // The facility zone must belong to this same property (checked against the FINAL
        // asset_id, so it also catches an edit that re-homes the unit).
        UnitResource::assertAreaInScope($data['area_id'] ?? null, $assetId);

        return $data;
    }

    protected function afterSave(): void
    {
        // Re-project status from the unit's leases — an operator-set 'occupied'/'reserved' with no
        // backing lease self-heals to 'vacant' (and 'maintenance' is preserved). See CreateUnit.
        $this->record->recomputeStatus();
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
