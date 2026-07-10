<?php

namespace App\Filament\Admin\Resources\VendorBills\Pages;

use App\Filament\Admin\Resources\VendorBills\VendorBillResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateVendorBill extends CreateRecord
{
    protected static string $resource = VendorBillResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Re-validate the client-supplied property against the user's visible set —
        // the asset_id Select is enabled in All-Properties mode (property isolation).
        VendorBillResource::assertAssetInScope($data['asset_id'] ?? null);

        // The UI always creates a DRAFT; the accountant reviews then Approves it.
        $data['created_by_user_id'] = Auth::id();

        return $data;
    }
}
