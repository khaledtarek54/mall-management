<?php

namespace App\Filament\Admin\Resources\VendorDocumentTypes\Pages;

use App\Filament\Admin\Resources\VendorDocumentTypes\VendorDocumentTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVendorDocumentType extends CreateRecord
{
    protected static string $resource = VendorDocumentTypeResource::class;
}
