<?php

namespace App\Filament\Admin\Resources\Payrolls\Pages;

use App\Filament\Admin\Resources\Payrolls\PayrollResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreatePayroll extends CreateRecord
{
    protected static string $resource = PayrollResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Re-validate the client-supplied property against the user's visible set —
        // the asset_id Select is enabled in All-Properties mode (property isolation).
        PayrollResource::assertAssetInScope($data['asset_id'] ?? null);

        // The UI always creates a DRAFT; the accountant reviews then Approves it.
        $data['created_by_user_id'] = Auth::id();

        return $data;
    }
}
