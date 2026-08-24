<?php

namespace App\Filament\Admin\Resources\Custodies\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\Custodies\CustodyResource;
use App\Models\CustodyTransaction;
use App\Support\ReportCsv;
use App\Support\StatusTabs;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustodies extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = CustodyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
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

    /** Whose عهدة is still open — the same split as the outstanding-only filter. */
    public function getTabs(): array
    {
        return StatusTabs::build(CustodyResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'outstanding' => [
                'label' => __('admin.custodies.fields.outstanding'),
                'badge' => true,
                'color' => 'warning',
                'query' => fn ($query) => $query->where(
                    'custodies.amount',
                    '>',
                    CustodyTransaction::query()
                        ->selectRaw('coalesce(sum(amount), 0)')
                        ->whereColumn('custody_id', 'custodies.id')
                ),
            ],
            'settled' => [
                'label' => __('admin.custodies.fields.settled'),
                'query' => fn ($query) => $query->where(
                    'custodies.amount',
                    '<=',
                    CustodyTransaction::query()
                        ->selectRaw('coalesce(sum(amount), 0)')
                        ->whereColumn('custody_id', 'custodies.id')
                ),
            ],
        ]);
    }
}
