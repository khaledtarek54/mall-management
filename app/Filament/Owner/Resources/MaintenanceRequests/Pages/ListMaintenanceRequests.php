<?php

namespace App\Filament\Owner\Resources\MaintenanceRequests\Pages;

use App\Filament\Owner\Resources\MaintenanceRequests\MaintenanceRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListMaintenanceRequests extends ListRecords
{
    protected static string $resource = MaintenanceRequestResource::class;
}
