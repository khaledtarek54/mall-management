<?php

namespace App\Filament\Admin\Resources\TenantRequestSubcategories\Pages;

use App\Filament\Admin\Resources\TenantRequestSubcategories\TenantRequestSubcategoryResource;
use Filament\Resources\Pages\EditRecord;

class EditTenantRequestSubcategory extends EditRecord
{
    protected static string $resource = TenantRequestSubcategoryResource::class;

    // No Delete action. `#[DeletableWhenUnused]` — a category that classified a posted cost stays in the catalogue,
    // because every document naming it reads its label. Deactivate it instead.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
