<?php

namespace App\Filament\Admin\Resources\TaxCodes\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\TaxCodes\TaxCodeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTaxCodes extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = TaxCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }
}
