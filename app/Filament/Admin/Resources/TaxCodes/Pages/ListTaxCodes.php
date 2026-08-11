<?php

namespace App\Filament\Admin\Resources\TaxCodes\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\TaxCodes\TaxCodeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTaxCodes extends ListRecords
{
    protected static string $resource = TaxCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(TaxCodeResource::class),
            CreateAction::make(),
        ];
    }
}
