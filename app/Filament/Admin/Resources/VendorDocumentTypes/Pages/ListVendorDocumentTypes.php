<?php

namespace App\Filament\Admin\Resources\VendorDocumentTypes\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\VendorDocumentTypes\VendorDocumentTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVendorDocumentTypes extends ListRecords
{
    protected static string $resource = VendorDocumentTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }
}
