<?php

namespace App\Filament\Admin\Resources\MaintenanceWorkOrders\Pages;

use App\Filament\Admin\Resources\MaintenanceWorkOrders\MaintenanceWorkOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMaintenanceWorkOrders extends ListRecords
{
    protected static string $resource = MaintenanceWorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->visible(fn () => MaintenanceWorkOrderResource::canCreate()),
        ];
    }
}
