<?php

namespace App\Filament\Admin\Resources\Leases\Pages;

use App\Filament\Admin\Resources\Leases\LeaseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLeases extends ListRecords
{
    protected static string $resource = LeaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
