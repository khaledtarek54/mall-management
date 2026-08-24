<?php

namespace App\Filament\Admin\Resources\RecurringExpenses\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\RecurringExpenses\RecurringExpenseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecurringExpenses extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = RecurringExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }
}
