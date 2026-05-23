<?php

namespace App\Filament\Admin\Resources\CamExpensePools\Pages;

use App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCamExpensePools extends ListRecords
{
    protected static string $resource = CamExpensePoolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn () => CamExpensePoolResource::canCreate()),
        ];
    }
}
