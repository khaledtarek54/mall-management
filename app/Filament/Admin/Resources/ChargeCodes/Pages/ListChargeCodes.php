<?php

namespace App\Filament\Admin\Resources\ChargeCodes\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\ChargeCodes\ChargeCodeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChargeCodes extends ListRecords
{
    protected static string $resource = ChargeCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }
}
