<?php

namespace App\Filament\Owner\Resources\CamAllocations\Pages;

use App\Filament\Owner\Resources\CamAllocations\CamAllocationResource;
use Filament\Resources\Pages\ListRecords;

class ListCamAllocations extends ListRecords
{
    protected static string $resource = CamAllocationResource::class;
}
