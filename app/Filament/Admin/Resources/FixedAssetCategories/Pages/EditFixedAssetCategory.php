<?php

namespace App\Filament\Admin\Resources\FixedAssetCategories\Pages;

use App\Filament\Admin\Resources\FixedAssetCategories\FixedAssetCategoryResource;
use Filament\Resources\Pages\EditRecord;

class EditFixedAssetCategory extends EditRecord
{
    protected static string $resource = FixedAssetCategoryResource::class;

    // No Delete action. `#[DeletableWhenUnused]` — a class that has numbered an asset stays in the
    // register, because every asset under it stores the code itself and its tag carries the series
    // prefix. Deactivate it instead.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
