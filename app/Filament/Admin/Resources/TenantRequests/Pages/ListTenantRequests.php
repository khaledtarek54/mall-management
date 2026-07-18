<?php

namespace App\Filament\Admin\Resources\MaintenanceRequests\Pages;

use App\Filament\Admin\Resources\MaintenanceRequests\MaintenanceRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMaintenanceRequests extends ListRecords
{
    protected static string $resource = MaintenanceRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn () => MaintenanceRequestResource::canCreate()),
        ];
    }
}
