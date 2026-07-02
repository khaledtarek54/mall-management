<?php

namespace App\Filament\Admin\Resources\JournalEntries\Pages;

use App\Filament\Admin\Concerns\PostsToLedger;
use App\Filament\Admin\Resources\JournalEntries\JournalEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListJournalEntries extends ListRecords
{
    use PostsToLedger;

    protected static string $resource = JournalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->postToLedgerAction(),
            CreateAction::make(),
        ];
    }

    public function getSubheading(): ?string
    {
        return $this->ledgerLastSyncedSubheading();
    }
}
