<?php

namespace App\Filament\Admin\Resources\JournalEntries\Pages;

use App\Filament\Admin\Resources\JournalEntries\JournalEntryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateJournalEntry extends CreateRecord
{
    protected static string $resource = JournalEntryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The UI always creates a DRAFT; the accountant reviews then Posts it.
        // (is_manual is derived from the absent source link in the model.)
        $data['status'] = 'draft';

        return $data;
    }
}
