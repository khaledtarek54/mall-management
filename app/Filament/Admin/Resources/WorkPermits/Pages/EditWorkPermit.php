<?php

namespace App\Filament\Admin\Resources\WorkPermits\Pages;

use App\Filament\Admin\Actions\WorkPermitActions;
use App\Filament\Admin\Resources\WorkPermits\WorkPermitResource;
use App\Support\Filament\RefreshesRecordState;
use Filament\Resources\Pages\EditRecord;

class EditWorkPermit extends EditRecord
{
    use RefreshesRecordState;

    /**
     * The columns these acts rewrite underneath the form. Only fields the form actually RENDERS
     * belong here; the re-read itself still happens either way, which is what keeps a render-time
     * state closure honest.
     */
    protected function derivedStatePaths(): array
    {
        return ['status'];
    }

    protected static string $resource = WorkPermitResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Filament stamps asset_id on create only, never on update — so the edit path needs its
        // own guard or a crafted payload could move a permit to another mall.
        WorkPermitResource::assertAssetInScope((int) ($data['asset_id'] ?? 0));

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            // The record hub: what you can DO to this record lives here, not on the list.
            ...WorkPermitActions::all(),
        ];
    }
}
