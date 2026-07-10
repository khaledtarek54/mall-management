<?php

namespace App\Filament\Admin\Resources\CamExpensePools\Pages;

use App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditCamExpensePool extends EditRecord
{
    protected static string $resource = CamExpensePoolResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Block re-homing into a property outside the user's visible set — asset_id is
        // editable in All-Properties mode and is NOT re-stamped by Filament on update.
        CamExpensePoolResource::assertAssetInScope($data['asset_id'] ?? $this->record->asset_id);

        return $data;
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
