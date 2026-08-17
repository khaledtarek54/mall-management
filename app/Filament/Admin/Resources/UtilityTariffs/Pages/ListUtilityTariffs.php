<?php

namespace App\Filament\Admin\Resources\UtilityTariffs\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\UtilityTariffs\UtilityTariffResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUtilityTariffs extends ListRecords
{
    protected static string $resource = UtilityTariffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }
}
