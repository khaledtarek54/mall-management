<?php

namespace App\Filament\Admin\Resources\FixedAssets\Pages;

use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFixedAsset extends EditRecord
{
    protected static string $resource = FixedAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->visible(fn () => FixedAssetResource::canDelete($this->getRecord())),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Terminal-record immutability: a disposed asset is written off — editing it
        // (e.g. its acquisition_cost) would re-derive the acquisition entry but strand
        // the disposal's offsetting Furniture credit. Block it (defence for a direct URL).
        abort_unless($this->getRecord()->status === 'active', 403);

        // Re-validate the target property server-side (can't re-home into another mall).
        FixedAssetResource::assertAssetInScope($data['asset_id'] ?? $this->getRecord()->asset_id);

        return $data;
    }
}
