<?php

namespace App\Filament\Portal\Resources\MaintenanceRequests\Pages;

use App\Filament\Portal\Resources\MaintenanceRequests\MaintenanceRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMaintenanceRequests extends ListRecords
{
    protected static string $resource = MaintenanceRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('admin.maintenance.new_request'))
                ->icon('heroicon-o-plus'),
        ];
    }
}
