<?php

namespace App\Filament\Admin\Resources\BankStatements\Pages;

use App\Filament\Admin\Resources\BankStatements\BankStatementResource;
use Filament\Resources\Pages\ListRecords;

class ListBankStatements extends ListRecords
{
    protected static string $resource = BankStatementResource::class;
}
