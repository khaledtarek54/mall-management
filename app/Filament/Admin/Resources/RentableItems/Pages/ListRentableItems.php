<?php

namespace App\Filament\Admin\Resources\RentableItems\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\RentableItems\RentableItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRentableItems extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = RentableItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
            CreateAction::make()];
    }
}
