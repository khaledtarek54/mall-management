<?php

namespace App\Filament\Admin\Resources\Violations\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\Violations\ViolationResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListViolations extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = ViolationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }

    /** Open violations are the enforcement worklist. */
    public function getTabs(): array
    {
        return StatusTabs::build(ViolationResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'open' => ['label' => __('admin.tabs.open'), 'statuses' => ['open'], 'badge' => true, 'color' => 'danger'],
            'resolved' => ['label' => __('admin.tabs.resolved'), 'statuses' => ['resolved']],
        ]);
    }
}
