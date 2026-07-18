<?php

namespace App\Filament\Admin\Resources\TenantRequests\Pages;

use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTenantRequests extends ListRecords
{
    protected static string $resource = TenantRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn () => TenantRequestResource::canCreate()),
        ];
    }
}
