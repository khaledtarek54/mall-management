<?php

namespace App\Filament\Admin\Pages;

use App\Services\Reports\MonthlyCloseReportPdfService;
use App\Services\Reports\ReportService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

class Reports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.pages.reports';

    public string $period;

    public function mount(): void
    {
        $this->period = request()->query('period', Carbon::now()->format('Y-m'));
    }

    public function updatedPeriod(): void
    {
        // Livewire re-renders with the new period
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.reports.nav_label');
    }

    public function getTitle(): string
    {
        return __('admin.reports.page_title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.accounting');
    }

    public static function canAccess(): bool
    {
        // Module flag AND per-user permission (audit M18 F-68 / D-53).
        return \App\Support\Modules::enabled('reports')
            && \Illuminate\Support\Facades\Auth::user()?->can('reports.view');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function downloadMonthlyClose()
    {
        abort_unless(
            \Illuminate\Support\Facades\Auth::user()?->can('reports.download') ?? false,
            403,
        );

        $period = $this->resolvePeriod();
        $svc = app(MonthlyCloseReportPdfService::class);
        $pdf = $svc->build($period);

        return response()->streamDownload(
            fn () => print($pdf),
            $svc->filename($period),
            ['Content-Type' => 'application/pdf'],
        );
    }

    protected function getViewData(): array
    {
        $period = $this->resolvePeriod();
        $report = app(ReportService::class)->monthlyClose($period);

        return [
            'period' => $period,
            'report' => $report,
            'recentPeriods' => $this->lastNPeriods(12),
        ];
    }

    private function resolvePeriod(): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('Y-m', $this->period)->startOfMonth();
        } catch (\Throwable) {
            return CarbonImmutable::now()->startOfMonth();
        }
    }

    /** @return array<string,string> */
    private function lastNPeriods(int $n): array
    {
        $out = [];
        $cur = CarbonImmutable::now()->startOfMonth();
        for ($i = 0; $i < $n; $i++) {
            $key = $cur->format('Y-m');
            $out[$key] = $cur->locale(app()->getLocale())->isoFormat('MMMM YYYY');
            $cur = $cur->subMonth();
        }
        return $out;
    }
}
