<?php

namespace App\Filament\Admin\Resources\LedgerAccounts\Pages;

use App\Filament\Admin\Resources\LedgerAccounts\LedgerAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLedgerAccounts extends ListRecords
{
    protected static string $resource = LedgerAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
