<?php

namespace App\Filament\Admin\Resources\Employees\Pages;

use App\Filament\Admin\Actions\EmployeeActions;
use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Support\Filament\RefreshesRecordState;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEmployee extends EditRecord
{
    use RefreshesRecordState;

    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The record hub: what you can DO to this record lives here, not on the list.
            ...EmployeeActions::all(),
            DeleteAction::make()->visible(fn () => EmployeeResource::canDelete($this->getRecord())),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Re-validate the target property server-side (can't re-home into another mall).
        EmployeeResource::assertAssetInScope($data['asset_id'] ?? $this->getRecord()->asset_id);

        return $data;
    }
}
