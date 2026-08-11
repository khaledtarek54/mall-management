<?php

namespace App\Filament\Admin\Resources\BankAccounts\Pages;

use App\Filament\Admin\Resources\BankAccounts\BankAccountResource;
use Filament\Resources\Pages\EditRecord;

class EditBankAccount extends EditRecord
{
    protected static string $resource = BankAccountResource::class;

    /**
     * Re-validate the client-supplied `asset_id`. Filament stamps it on create only and never on
     * update, so an edit is the path a tampered value actually reaches.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        BankAccountResource::assertAssetInScope($data['asset_id'] ?? null);

        return $data;
    }
}
