<?php

namespace App\Filament\Portal\Resources\Leases\Pages;

use App\Filament\Portal\Actions\LeaseActions;
use App\Filament\Portal\Resources\Leases\LeaseResource;
use Filament\Resources\Pages\ViewRecord;

class ViewLease extends ViewRecord
{
    protected static string $resource = LeaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...LeaseActions::all(),
        ];
    }
}
