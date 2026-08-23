<?php

namespace App\Filament\Admin\Resources\RecurringExpenses\Pages;

use App\Filament\Admin\Resources\RecurringExpenses\RecurringExpenseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRecurringExpense extends CreateRecord
{
    protected static string $resource = RecurringExpenseResource::class;
}
