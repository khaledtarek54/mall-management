<?php

namespace App\Filament\Admin\Resources\TenantRequests\Pages;

use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditTenantRequest extends EditRecord
{
    protected static string $resource = TenantRequestResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Block re-homing via a tampered unit (property is derived from the unit).
        TenantRequestResource::assertUnitAssetInScope($data['unit_id'] ?? $this->record->unit_id);

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
