<?php

namespace App\Filament\Admin\Resources\InventoryItems\Pages;

use App\Filament\Admin\Resources\InventoryItems\InventoryItemResource;
use App\Support\ReportCsv;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInventoryItems extends ListRecords
{
    protected static string $resource = InventoryItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
}
