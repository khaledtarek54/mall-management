<?php

namespace App\Filament\Admin\Resources\Custodies\Pages;

use App\Filament\Admin\Resources\Custodies\CustodyResource;
use App\Support\ReportCsv;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustodies extends ListRecords
{
    protected static string $resource = CustodyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The outstanding-custody schedule as a spreadsheet — grant, settled and cash still
            // in each custodian's hands + totals, the treasury's عهدة register.
            Action::make('export_csv')
                ->label(__('admin.reports.csv.export'))
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn () => CustodyResource::canViewAny())
                ->authorize(fn () => CustodyResource::canViewAny())
                ->action(function () {
                    $csv = CustodyResource::registerCsv();

                    return ReportCsv::stream('custody-register', $csv['headers'], $csv['rows']);
                }),
            CreateAction::make()->visible(fn () => CustodyResource::canCreate()),
        ];
    }
}
