<?php

namespace App\Filament\Admin\Resources\MaintenancePlans\Pages;

use App\Filament\Admin\Resources\MaintenancePlans\MaintenancePlanResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMaintenancePlan extends EditRecord
{
    protected static string $resource = MaintenancePlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->visible(fn () => MaintenancePlanResource::canDelete($this->getRecord())),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        MaintenancePlanResource::assertAssetInScope($data['asset_id'] ?? $this->getRecord()->asset_id);

        return $data;
    }
}
