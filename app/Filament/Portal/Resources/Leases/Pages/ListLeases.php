<?php

namespace App\Filament\Portal\Resources\Leases\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Portal\Resources\Leases\LeaseResource;
use Filament\Resources\Pages\ListRecords;

class ListLeases extends ListRecords
{
    protected static string $resource = LeaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
        ];
    }
}
