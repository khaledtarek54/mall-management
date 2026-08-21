<?php

namespace App\Filament\Admin\Resources\ViolationCategories\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\ViolationCategories\ViolationCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListViolationCategories extends ListRecords
{
    protected static string $resource = ViolationCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }
}
