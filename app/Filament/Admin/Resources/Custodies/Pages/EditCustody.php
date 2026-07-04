<?php

namespace App\Filament\Admin\Resources\Custodies\Pages;

use App\Filament\Admin\Resources\Custodies\CustodyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCustody extends EditRecord
{
    protected static string $resource = CustodyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->visible(fn () => CustodyResource::canDelete($this->getRecord())),
        ];
    }
}
