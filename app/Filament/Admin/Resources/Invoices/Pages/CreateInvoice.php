<?php

namespace App\Filament\Admin\Resources\Invoices\Pages;

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The invoice's property is derived from its lease — re-validate the
        // submitted lease is within the user's visible set (property isolation).
        InvoiceResource::assertLeaseAssetInScope($data['lease_id'] ?? null);

        return $data;
    }
}
