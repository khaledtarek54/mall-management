<?php

namespace App\Filament\Admin\Resources\RentIndices\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\RentIndices\RentIndexResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRentIndices extends ListRecords
{
    protected static string $resource = RentIndexResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }
}
