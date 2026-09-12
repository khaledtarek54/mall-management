<?php

namespace App\Filament\Admin\Resources\FixedAssetCategories\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\FixedAssetCategories\FixedAssetCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFixedAssetCategories extends ListRecords
{
    protected static string $resource = FixedAssetCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }
}
