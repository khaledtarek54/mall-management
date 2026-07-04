<?php

namespace App\Filament\Admin\Resources\Custodies\Pages;

use App\Filament\Admin\Resources\Custodies\CustodyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustodies extends ListRecords
{
    protected static string $resource = CustodyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->visible(fn () => CustodyResource::canCreate()),
        ];
    }
}
