<?php

namespace App\Filament\Admin\Resources\ExpenseCategories\Pages;

use App\Filament\Admin\Resources\ExpenseCategories\ExpenseCategoryResource;
use Filament\Resources\Pages\EditRecord;

class EditExpenseCategory extends EditRecord
{
    protected static string $resource = ExpenseCategoryResource::class;

    // No Delete action. `#[DeletableWhenUnused]` — a category that classified a posted cost stays in the catalogue,
    // because every document naming it reads its label. Deactivate it instead.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
