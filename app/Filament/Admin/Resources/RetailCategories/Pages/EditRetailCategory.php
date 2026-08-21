<?php

namespace App\Filament\Admin\Resources\RetailCategories\Pages;

use App\Filament\Admin\Resources\RetailCategories\RetailCategoryResource;
use Filament\Resources\Pages\EditRecord;

class EditRetailCategory extends EditRecord
{
    protected static string $resource = RetailCategoryResource::class;

    // No Delete action. `#[DeletableWhenUnused]` — a category that classified a posted cost stays in the catalogue,
    // because every document naming it reads its label. Deactivate it instead.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
