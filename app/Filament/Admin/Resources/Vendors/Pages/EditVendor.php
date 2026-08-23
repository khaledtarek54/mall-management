<?php

namespace App\Filament\Admin\Resources\Vendors\Pages;

use App\Filament\Admin\Resources\Concerns\FillsCustomFields;
use App\Filament\Admin\Resources\Vendors\VendorResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVendor extends EditRecord
{
    use FillsCustomFields;

    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
