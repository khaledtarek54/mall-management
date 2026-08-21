<?php

namespace App\Filament\Admin\Resources\RetailCategories\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\RetailCategories\RetailCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRetailCategories extends ListRecords
{
    protected static string $resource = RetailCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }
}
