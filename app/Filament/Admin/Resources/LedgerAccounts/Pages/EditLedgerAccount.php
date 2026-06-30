<?php

namespace App\Filament\Admin\Resources\LedgerAccounts\Pages;

use App\Filament\Admin\Resources\LedgerAccounts\LedgerAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLedgerAccount extends EditRecord
{
    protected static string $resource = LedgerAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
