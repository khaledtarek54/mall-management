<?php

namespace App\Filament\Admin\Resources\Leases\Pages;

use App\Filament\Admin\Resources\Leases\LeaseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLease extends CreateRecord
{
    protected static string $resource = LeaseResource::class;
}
