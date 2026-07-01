<?php

namespace App\Filament\Admin\Resources\DepositTransactions\Pages;

use App\Filament\Admin\Resources\DepositTransactions\DepositTransactionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDepositTransactions extends ListRecords
{
    protected static string $resource = DepositTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
