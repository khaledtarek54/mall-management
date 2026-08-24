<?php

namespace App\Filament\Admin\Resources\Expenses\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\Expenses\ExpenseResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExpenses extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }

    /** Cancelled expenses stay for the audit trail; keep them off the default view's way. */
    public function getTabs(): array
    {
        return StatusTabs::build(ExpenseResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'recorded' => ['label' => __('admin.statuses.expense.recorded'), 'statuses' => ['recorded']],
            'cancelled' => ['label' => __('admin.statuses.expense.cancelled'), 'statuses' => ['cancelled']],
        ]);
    }
}
