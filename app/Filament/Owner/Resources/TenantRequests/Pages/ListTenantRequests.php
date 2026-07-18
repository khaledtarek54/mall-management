<?php

namespace App\Filament\Owner\Resources\TenantRequests\Pages;

use App\Filament\Owner\Resources\TenantRequests\TenantRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListTenantRequests extends ListRecords
{
    protected static string $resource = TenantRequestResource::class;
}
