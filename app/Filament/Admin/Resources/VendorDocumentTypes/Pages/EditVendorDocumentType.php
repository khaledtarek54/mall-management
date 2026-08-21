<?php

namespace App\Filament\Admin\Resources\VendorDocumentTypes\Pages;

use App\Filament\Admin\Resources\VendorDocumentTypes\VendorDocumentTypeResource;
use Filament\Resources\Pages\EditRecord;

class EditVendorDocumentType extends EditRecord
{
    protected static string $resource = VendorDocumentTypeResource::class;

    // No Delete action. `#[DeletableWhenUnused]` — a type that classified a filed certificate stays
    // in the catalogue, because the vendor record, the renewal chase and the expiry notice all read
    // its label. Deactivate it instead.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
