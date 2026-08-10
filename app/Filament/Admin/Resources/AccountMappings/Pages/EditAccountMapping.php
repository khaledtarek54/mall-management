<?php

namespace App\Filament\Admin\Resources\AccountMappings\Pages;

use App\Filament\Admin\Resources\AccountMappings\AccountMappingResource;
use App\Models\AccountMapping;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAccountMapping extends EditRecord
{
    protected static string $resource = AccountMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Only an override may be removed — it falls back to the global default. The global
            // default itself has nothing behind it, and the model refuses to delete one; hidden here
            // so the operator is not offered a button that can only fail.
            DeleteAction::make()
                ->visible(fn (AccountMapping $record) => $record->asset_id !== null),
        ];
    }
}
