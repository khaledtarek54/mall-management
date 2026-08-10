<?php

namespace App\Filament\Admin\Resources\RentableItems\Pages;

use App\Filament\Admin\Resources\RentableItems\RentableItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRentableItems extends ListRecords
{
    protected static string $resource = RentableItemResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
