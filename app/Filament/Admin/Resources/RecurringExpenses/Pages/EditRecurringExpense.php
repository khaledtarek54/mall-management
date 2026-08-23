<?php

namespace App\Filament\Admin\Resources\RecurringExpenses\Pages;

use App\Filament\Admin\Resources\RecurringExpenses\RecurringExpenseResource;
use App\Support\Filament\RefreshesRecordState;
use Filament\Resources\Pages\EditRecord;

class EditRecurringExpense extends EditRecord
{
    use RefreshesRecordState;

    protected static string $resource = RecurringExpenseResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Block re-homing the schedule into a property outside the user's visible set. Filament
        // only stamps `asset_id` on CREATE, never on update, so without this the edit form is the
        // unguarded half.
        RecurringExpenseResource::assertAssetInScope($data['asset_id'] ?? $this->record->asset_id);

        return $data;
    }
}
