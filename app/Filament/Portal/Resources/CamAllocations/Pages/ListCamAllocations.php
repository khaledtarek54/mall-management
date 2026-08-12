<?php

namespace App\Filament\Portal\Resources\CamAllocations\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Portal\Resources\CamAllocations\CamAllocationResource;
use Filament\Resources\Pages\ListRecords;

class ListCamAllocations extends ListRecords
{
    protected static string $resource = CamAllocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
        ];
    }
}
