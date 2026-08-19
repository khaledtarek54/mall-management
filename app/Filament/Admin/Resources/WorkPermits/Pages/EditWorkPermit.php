<?php

namespace App\Filament\Admin\Resources\WorkPermits\Pages;

use App\Filament\Admin\Resources\WorkPermits\WorkPermitResource;
use Filament\Resources\Pages\EditRecord;

class EditWorkPermit extends EditRecord
{
    protected static string $resource = WorkPermitResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Filament stamps asset_id on create only, never on update — so the edit path needs its
        // own guard or a crafted payload could move a permit to another mall.
        WorkPermitResource::assertAssetInScope((int) ($data['asset_id'] ?? 0));

        return $data;
    }
}
