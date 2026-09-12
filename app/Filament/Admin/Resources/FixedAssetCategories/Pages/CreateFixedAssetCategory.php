<?php

namespace App\Filament\Admin\Resources\FixedAssetCategories\Pages;

use App\Filament\Admin\Resources\FixedAssetCategories\FixedAssetCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFixedAssetCategory extends CreateRecord
{
    protected static string $resource = FixedAssetCategoryResource::class;
}
