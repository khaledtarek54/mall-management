<?php

namespace App\Filament\Admin\Resources\ExpenseCategories\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\ExpenseCategories\ExpenseCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExpenseCategories extends ListRecords
{
    protected static string $resource = ExpenseCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }
}
