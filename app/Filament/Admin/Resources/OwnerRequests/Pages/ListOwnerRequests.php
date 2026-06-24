<?php

namespace App\Filament\Admin\Resources\OwnerRequests\Pages;

use App\Filament\Admin\Resources\OwnerRequests\OwnerRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListOwnerRequests extends ListRecords
{
    protected static string $resource = OwnerRequestResource::class;
}
