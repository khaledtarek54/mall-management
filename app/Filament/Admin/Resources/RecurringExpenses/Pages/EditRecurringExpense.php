<?php

namespace App\Filament\Admin\Resources\RecurringExpenses\Pages;

use App\Filament\Admin\Resources\RecurringExpenses\RecurringExpenseResource;
use App\Support\Filament\RefreshesRecordState;
use Filament\Resources\Pages\EditRecord;

class EditRecurringExpense extends EditRecord
{
    use RefreshesRecordState;

    protected static string $resource = RecurringExpenseResource::class;
}
