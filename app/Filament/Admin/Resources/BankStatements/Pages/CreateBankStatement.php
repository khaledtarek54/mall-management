<?php

namespace App\Filament\Admin\Resources\BankStatements\Pages;

use App\Filament\Admin\Resources\BankStatements\BankStatementResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBankStatement extends CreateRecord
{
    protected static string $resource = BankStatementResource::class;
}
