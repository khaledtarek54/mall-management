<?php

namespace App\Filament\Admin\Resources\ViolationCategories\Pages;

use App\Filament\Admin\Resources\ViolationCategories\ViolationCategoryResource;
use Filament\Resources\Pages\EditRecord;

class EditViolationCategory extends EditRecord
{
    protected static string $resource = ViolationCategoryResource::class;

    // No Delete action. `#[DeletableWhenUnused]` — a rule that classified a recorded breach stays in
    // the book, because the violation, the notice served on the tenant and every repeat-offender
    // report read its label. Deactivate it instead.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
