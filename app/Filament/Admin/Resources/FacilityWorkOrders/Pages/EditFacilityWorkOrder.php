<?php

namespace App\Filament\Admin\Resources\FacilityWorkOrders\Pages;

use App\Filament\Admin\Resources\FacilityWorkOrders\FacilityWorkOrderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFacilityWorkOrder extends EditRecord
{
    protected static string $resource = FacilityWorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->visible(fn () => FacilityWorkOrderResource::canDelete($this->getRecord())),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Terminal (done/cancelled) work orders are immutable.
        abort_unless(! $this->getRecord()->isTerminal(), 403);
        FacilityWorkOrderResource::assertAssetInScope($data['asset_id'] ?? $this->getRecord()->asset_id);

        return $data;
    }
}
