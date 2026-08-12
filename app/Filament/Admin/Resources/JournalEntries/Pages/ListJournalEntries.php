<?php

namespace App\Filament\Admin\Resources\JournalEntries\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Concerns\PostsToLedger;
use App\Filament\Admin\Resources\JournalEntries\JournalEntryResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListJournalEntries extends ListRecords
{
    use PostsToLedger;

    protected static string $resource = JournalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            $this->postToLedgerAction(),
            CreateAction::make(),
        ];
    }

    public function getSubheading(): ?string
    {
        return $this->ledgerLastSyncedSubheading();
    }

    /** Unposted drafts are the accountant's queue; void stays visible for the audit trail. */
    public function getTabs(): array
    {
        return StatusTabs::build(JournalEntryResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'draft' => ['label' => __('admin.tabs.draft'), 'statuses' => ['draft'], 'badge' => true, 'color' => 'warning'],
            'posted' => ['label' => __('admin.tabs.posted'), 'statuses' => ['posted']],
            'void' => ['label' => __('admin.tabs.void'), 'statuses' => ['void']],
        ]);
    }
}
