<?php

namespace App\Filament\Admin\Pages;

use App\Services\Reports\ReportService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class ArAging extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingDown;

    protected static bool $shouldRegisterNavigation = false; // reached via the Reports page

    public static function canAccess(): bool
    {
        return \App\Support\Modules::enabled('reports');
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

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.reports');
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
