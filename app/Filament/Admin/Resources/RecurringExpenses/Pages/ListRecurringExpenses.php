<?php

namespace App\Filament\Admin\Resources\RecurringExpenses\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\RecurringExpenses\RecurringExpenseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecurringExpenses extends ListRecords
{
    protected static string $resource = RecurringExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }
}
