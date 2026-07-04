<?php

namespace App\Filament\Admin\Resources\MaintenanceWorkOrders\Pages;

use App\Filament\Admin\Resources\MaintenanceWorkOrders\MaintenanceWorkOrderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMaintenanceWorkOrder extends EditRecord
{
    protected static string $resource = MaintenanceWorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->visible(fn () => MaintenanceWorkOrderResource::canDelete($this->getRecord())),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Terminal (done/cancelled) work orders are immutable.
        abort_unless(! $this->getRecord()->isTerminal(), 403);
        MaintenanceWorkOrderResource::assertAssetInScope($data['asset_id'] ?? $this->getRecord()->asset_id);

        return $data;
    }
}
