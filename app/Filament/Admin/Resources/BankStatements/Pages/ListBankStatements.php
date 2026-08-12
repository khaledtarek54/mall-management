<?php

namespace App\Filament\Admin\Resources\BankStatements\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\BankStatements\BankStatementResource;
use Filament\Resources\Pages\ListRecords;

class ListBankStatements extends ListRecords
{
    protected static string $resource = BankStatementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
        ];
    }
}
