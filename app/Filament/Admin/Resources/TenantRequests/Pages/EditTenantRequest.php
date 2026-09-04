<?php

namespace App\Filament\Admin\Resources\TenantRequests\Pages;

use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Support\Filament\RefreshesRecordState;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditTenantRequest extends EditRecord
{
    use RefreshesRecordState;

    protected static string $resource = TenantRequestResource::class;

    /**
     * `area_id` is DERIVED from the unit and rendered disabled, with the placeholder
     * `admin.fields.area_auto` — "auto". Correcting the unit re-inherits the zone in the model
     * (InheritsAreaFromUnit), and without this the disabled field goes on showing the OLD zone
     * under a success toast until the operator reloads: stale under a confirmation, which is the
     * shape RefreshesRecordState exists for.
     *
     * @return array<int, string>
     */
    protected function derivedStatePaths(): array
    {
        return ['area_id'];
    }

    protected function afterSave(): void
    {
        $this->refreshFormData($this->derivedStatePaths());
    }

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
