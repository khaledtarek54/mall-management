<?php

namespace App\Filament\Admin\Resources\Expenses\Pages;

use App\Filament\Admin\Resources\Expenses\ExpenseResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Re-validate the client-supplied property against the user's visible set —
        // the asset_id Select is enabled in All-Properties mode (property isolation).
        ExpenseResource::assertAssetInScope($data['asset_id'] ?? null);

        // An expense is paid immediately — recorded on create, no approval stage.
        $data['created_by_user_id'] = Auth::id();

        return $data;
    }
}
