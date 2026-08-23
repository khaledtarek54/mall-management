<?php

namespace App\Filament\Admin\Resources\RecurringExpenses\Pages;

use App\Filament\Admin\Resources\RecurringExpenses\RecurringExpenseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRecurringExpense extends CreateRecord
{
    protected static string $resource = RecurringExpenseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Re-validate the client-supplied property against the user's visible set. `PropertyField`
        // pins and disables the input, but a disabled field's value still arrives in the Livewire
        // payload — the pin is a UI truth, this is the gate.
        RecurringExpenseResource::assertAssetInScope($data['asset_id'] ?? null);

        return $data;
    }
}
