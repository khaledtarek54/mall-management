<?php

namespace App\Filament\Admin\Resources\MaintenanceWorkOrders\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\MaintenanceWorkOrders\MaintenanceWorkOrderResource;
use App\Models\Asset;
use App\Services\FacilityWorkLogPdfService;
use App\Support\StatusTabs;
use App\Support\TenantScope;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\ListRecords;

class ListMaintenanceWorkOrders extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = MaintenanceWorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            ...$this->savedViewActions(),
            // Facility work-log report (RPT-1) — a bilingual PDF of the work orders for the
            // current property over a date range. Scoped to what the user can see.
            Action::make('work_log')
                ->label(__('admin.preventive_maintenance.report.action'))
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn () => auth()->user()?->can('preventive_maintenance.view') ?? false)
                ->authorize(fn () => auth()->user()?->can('preventive_maintenance.view') ?? false)
                ->schema([
                    DatePicker::make('from')
                        ->label(__('admin.preventive_maintenance.report.from'))
                        ->default(now()->startOfMonth())
                        ->required()
                        ->native(false),
                    DatePicker::make('to')
                        ->label(__('admin.preventive_maintenance.report.to'))
                        ->default(now())
                        ->required()
                        ->native(false),
                ])
                ->action(function (array $data) {
                    abort_unless(auth()->user()?->can('preventive_maintenance.view') ?? false, 403);

                    // Scope the report to what the user may see (never leaks another mall).
                    if ($assetId = TenantScope::currentAssetId()) {
                        $assetIds = [$assetId];
                        $label = Asset::find($assetId)?->name ?? '';
                    } else {
                        $assetIds = TenantScope::visibleAssetIds(); // null = portfolio (all)
                        $label = __('admin.preventive_maintenance.report.all_properties');
                    }

                    $svc = app(FacilityWorkLogPdfService::class);
                    $pdf = $svc->build($data['from'], $data['to'], $assetIds, $label);

                    return response()->streamDownload(
                        fn () => print ($pdf),
                        $svc->filename($data['from'], $data['to']),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
            CreateAction::make()->visible(fn () => MaintenanceWorkOrderResource::canCreate()),
        ];
    }

    /** The facility team's board: what is queued, what is being worked, what is finished. */
    public function getTabs(): array
    {
        return StatusTabs::build(MaintenanceWorkOrderResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'open' => ['label' => __('admin.tabs.open'), 'statuses' => ['open'], 'badge' => true, 'color' => 'warning'],
            'in_progress' => ['label' => __('admin.tabs.in_progress'), 'statuses' => ['in_progress'], 'badge' => true, 'color' => 'info'],
            'done' => ['label' => __('admin.tabs.done'), 'statuses' => ['done']],
            'cancelled' => ['label' => __('admin.tabs.cancelled'), 'statuses' => ['cancelled']],
        ]);
    }
}
