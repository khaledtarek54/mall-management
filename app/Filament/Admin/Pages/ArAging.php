<?php

namespace App\Filament\Admin\Pages;

use App\Services\Reports\ReportCsvExporter;
use App\Services\Reports\ReportService;
use App\Support\ReportCsv;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ArAging extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingDown;

    protected static bool $shouldRegisterNavigation = false; // reached via the Reports page

    public static function canAccess(): bool
    {
        // Module flag AND per-user permission (audit M18 F-68 / D-53).
        return \App\Support\Modules::enabled('reports')
            && \Illuminate\Support\Facades\Auth::user()?->can('reports.view');
    }

    protected string $view = 'filament.pages.ar-aging';

    protected static string $routePath = 'ar-aging';

    public string $bucket = 'd_1_30';

    public function mount(): void
    {
        $this->bucket = request()->query('bucket', 'd_1_30');
    }

    public function updatedBucket(): void
    {
        // Livewire re-renders with the new bucket
    }

    public function getTitle(): string
    {
        return __('admin.reports.ar_aging_page_title');
    }

    protected function getHeaderActions(): array
    {
        return [
            // AR aging is the collections worklist — who owes what, how late. It had no export;
            // now it exports the current bucket's invoices to CSV so an operator can chase them.
            Action::make('export_csv')
                ->label(__('admin.reports.csv.export'))
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn () => Auth::user()?->can('reports.view') ?? false)
                ->authorize(fn () => Auth::user()?->can('reports.view') ?? false)
                ->action(function () {
                    $invoices = app(ReportService::class)->arAgingDrilldown($this->bucket);
                    $csv = app(ReportCsvExporter::class)->arAging($invoices);

                    return ReportCsv::stream("ar-aging-{$this->bucket}", $csv['headers'], $csv['rows']);
                }),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.accounting');
    }

    protected function getViewData(): array
    {
        $invoices = app(ReportService::class)->arAgingDrilldown($this->bucket);

        $buckets = [
            'current' => __('admin.widgets.ar_aging.current'),
            'd_1_30' => __('admin.widgets.ar_aging.d_1_30'),
            'd_31_60' => __('admin.widgets.ar_aging.d_31_60'),
            'd_61_90' => __('admin.widgets.ar_aging.d_61_90'),
            'd_90_plus' => __('admin.widgets.ar_aging.d_90_plus'),
        ];

        return [
            'invoices' => $invoices,
            'bucket' => $this->bucket,
            'buckets' => $buckets,
            'totalBalance' => round((float) $invoices->sum('balance'), 2),
        ];
    }
}
