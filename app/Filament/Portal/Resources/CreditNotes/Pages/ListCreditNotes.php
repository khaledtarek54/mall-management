<?php

namespace App\Filament\Portal\Resources\CreditNotes\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Portal\Resources\CreditNotes\CreditNoteResource;
use Filament\Resources\Pages\ListRecords;

class ListCreditNotes extends ListRecords
{
    protected static string $resource = CreditNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
        ];
    }
}
