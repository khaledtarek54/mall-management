<?php

namespace App\Filament\Portal\Resources\TenantRequests\Pages;

use App\Filament\Portal\Resources\TenantRequests\TenantRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTenantRequests extends ListRecords
{
    protected static string $resource = TenantRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('admin.maintenance.new_request'))
                ->icon('heroicon-o-plus'),
        ];
    }
}
