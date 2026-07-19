<?php

namespace App\Filament\Admin\Resources\PostDatedCheques\Pages;

use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\PostDatedCheques\PostDatedChequeResource;
use Filament\Resources\Pages\EditRecord;

class EditPostDatedCheque extends EditRecord
{
    use GuardsAssetInScope;

    protected static string $resource = PostDatedChequeResource::class;

    // Filament only stamps asset_id on create, never on update — re-check on edit.
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->assertAssetInScope($data['asset_id'] ?? $this->record->asset_id);

        return $data;
    }
}
