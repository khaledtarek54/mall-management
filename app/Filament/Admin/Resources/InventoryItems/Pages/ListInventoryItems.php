<?php

namespace App\Filament\Admin\Resources\InventoryItems\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\InventoryItems\InventoryItemResource;
use App\Support\ReportCsv;
use App\Support\StatusTabs;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInventoryItems extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = InventoryItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
            CreateAction::make()->visible(fn () => InventoryItemResource::canCreate()),
            // The stock register in the accountant's format — on-hand × unit cost per item plus a
            // total valuation, exactly what the screen shows but pivotable / auditable in a sheet.
            Action::make('export_csv')
                ->label(__('admin.reports.csv.export'))
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn () => InventoryItemResource::canViewAny())
                ->authorize(fn () => InventoryItemResource::canViewAny())
                ->action(function () {
                    $csv = InventoryItemResource::stockRegisterCsv();

                    return ReportCsv::stream('inventory-stock-register', $csv['headers'], $csv['rows']);
                }),
        ];
    }

    public function getTabs(): array
    {
        return StatusTabs::build(InventoryItemResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'active' => ['label' => __('admin.tabs.active'), 'query' => fn ($query) => $query->where('is_active', true)],
            'inactive' => ['label' => __('admin.filters.inactive_only'), 'query' => fn ($query) => $query->where('is_active', false)],
        ]);
    }
}
