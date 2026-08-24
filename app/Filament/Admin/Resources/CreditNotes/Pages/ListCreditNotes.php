<?php

namespace App\Filament\Admin\Resources\CreditNotes\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\CreditNotes\CreditNoteResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCreditNotes extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = CreditNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }

    /** Issued-but-unapplied credit is money owed back to a tenant that nobody has settled. */
    public function getTabs(): array
    {
        return StatusTabs::build(CreditNoteResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'draft' => ['label' => __('admin.tabs.draft'), 'statuses' => ['draft'], 'badge' => true, 'color' => 'gray'],
            'issued' => ['label' => __('admin.tabs.issued'), 'statuses' => ['issued'], 'badge' => true, 'color' => 'warning'],
            'applied' => ['label' => __('admin.tabs.applied'), 'statuses' => ['applied']],
            'void' => ['label' => __('admin.tabs.void'), 'statuses' => ['void']],
        ]);
    }
}
