<?php

namespace App\Filament\Admin\Resources\VendorBills\Pages;

use App\Filament\Admin\Resources\VendorBills\VendorBillResource;
use App\Support\PostingDate;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateVendorBill extends CreateRecord
{
    protected static string $resource = VendorBillResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Re-validate the client-supplied property against the user's visible set —
        // the asset_id Select is enabled in All-Properties mode (property isolation).
        VendorBillResource::assertAssetInScope($data['asset_id'] ?? null);

        // bill_date becomes the GL entry_date. A date in a closed period would commit the
        // bill but leave its journal entry unpostable — the sync job rejects the closed
        // period and only logs it (the F-89/F-93 class). Refuse it here, rendered on the
        // field rather than 500-ing. bill_date is required and defaults to today.
        try {
            PostingDate::assertOpen($data['bill_date'] ?? null, __('admin.fields.bill_date'));
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['data.bill_date' => $e->getMessage()]);
        }

        // The UI always creates a DRAFT; the accountant reviews then Approves it.
        $data['created_by_user_id'] = Auth::id();

        return $data;
    }
}
