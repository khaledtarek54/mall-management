<?php

namespace App\Filament\Admin\Resources\SlaPolicies\Pages;

use App\Filament\Admin\Resources\SlaPolicies\SlaPolicyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSlaPolicy extends EditRecord
{
    protected static string $resource = SlaPolicyResource::class;

    /**
     * Filament stamps asset_id on create only, never on update — an edit in All-Properties
     * mode could otherwise move a policy to a property the user cannot see.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        SlaPolicyResource::assertAssetInScope($data['asset_id'] ?? $this->record->asset_id);

        return $data;
    }

    /** Deleting a policy is how a property returns to the operator-wide default. */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
