<?php

namespace App\Filament\Owner\Resources\OwnerRequests\Pages;

use App\Filament\Owner\Resources\OwnerRequests\OwnerRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOwnerRequests extends ListRecords
{
    protected static string $resource = OwnerRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
