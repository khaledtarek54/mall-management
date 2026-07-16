<?php

namespace App\Filament\Admin\Resources\PurchaseRequests\Pages;

use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseRequests extends ListRecords
{
    protected static string $resource = PurchaseRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->visible(fn () => PurchaseRequestResource::canCreate()),
        ];
    }
}
