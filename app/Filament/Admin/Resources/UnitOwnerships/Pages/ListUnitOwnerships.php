<?php

namespace App\Filament\Admin\Resources\UnitOwnerships\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\UnitOwnerships\UnitOwnershipResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUnitOwnerships extends ListRecords
{
    protected static string $resource = UnitOwnershipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }
}
