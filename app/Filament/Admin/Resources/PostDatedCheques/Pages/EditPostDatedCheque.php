<?php

namespace App\Filament\Admin\Resources\PostDatedCheques\Pages;

use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\PostDatedCheques\PostDatedChequeResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPostDatedCheque extends EditRecord
{
    use GuardsAssetInScope;

    protected static string $resource = PostDatedChequeResource::class;

    // Filament only stamps asset_id on create, never on update — re-check on edit.
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->assertAssetInScope($data['asset_id'] ?? $this->record->asset_id);

        // Editing a cheque ONTO a number another live cheque holds is the same duplicate as
        // creating one. Toast + keep the form, rather than the model's redirecting refusal.
        try {
            (clone $this->record)->forceFill($data)->assertChequeNumberNotAlreadyLodged();
        } catch (\DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $this->halt();
        }

        return $data;
    }
}
