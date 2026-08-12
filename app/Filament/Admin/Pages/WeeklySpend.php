<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Services\Reports\ReportService;
use App\Support\Modules;
use App\Support\ReportCsv;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * FR-FIN-02 — the weekly operating-cost report, split fixed vs variable.
 *
 * A management view of where the money goes week to week: committed (fixed) costs that land
 * regardless of activity vs variable spend that tracks it. Fed by ReportService::weeklySpend()
 * (Expense + VendorBill, ex-VAT, ISO weeks, property-scoped), so the same numbers drive the table,
 * the column totals and the CSV. No weekly period existed anywhere before this — every other report
 * is monthly / as-of.
 */
class WeeklySpend extends Page implements HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;
    use SavesReportViews;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 52;

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'weekly-spend';

    /** The reporting window (Y-m-d). Defaults to the last 12 ISO weeks. */
    public string $from;

    public string $to;

    public static function canAccess(): bool
    {
        return Modules::enabled('reports')
            && Auth::user()?->can('reports.view');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.accounting');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.reports.weekly_spend_nav');
    }

    public function getTitle(): string
    {
        return __('admin.reports.weekly_spend_title');
    }

    public function mount(): void
    {
        $to = CarbonImmutable::now()->endOfWeek(CarbonInterface::SUNDAY);
        $this->to = $to->toDateString();
        $this->from = $to->subWeeks(11)->startOfWeek(CarbonInterface::MONDAY)->toDateString();
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(['sm' => 2])
                ->schema([
                    DatePicker::make('from')->label(__('admin.reports.from'))->native(false)->live(),
                    DatePicker::make('to')->label(__('admin.reports.to'))->native(false)->live(),
                ]),
        ]);
    }

    /** @return array{from:string,to:string,weeks:array<int,array<string,mixed>>,totals:array<string,float>} */
    protected function report(): array
    {
        return app(ReportService::class)->weeklySpend(
            CarbonImmutable::parse($this->from),
            CarbonImmutable::parse($this->to),
        );
    }

    public function getSubheading(): ?string
    {
        $t = $this->report()['totals'];

        return __('admin.reports.weekly_spend_summary', [
            'fixed' => 'EGP '.number_format($t['fixed'], 2),
            'variable' => 'EGP '.number_format($t['variable'], 2),
            'total' => 'EGP '.number_format($t['total'], 2),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->saveViewAction(),
            Action::make('export_csv')
                ->label(__('admin.reports.csv.export'))
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn () => Auth::user()?->can('reports.view') ?? false)
                ->authorize(fn () => Auth::user()?->can('reports.view') ?? false)
                ->action(function () {
                    $report = $this->report();
                    $headers = [
                        __('admin.reports.week'),
                        __('admin.enums.cost_nature.fixed'),
                        __('admin.enums.cost_nature.variable'),
                        __('admin.reports.totals'),
                    ];
                    $rows = array_map(fn (array $w) => [
                        $w['week_start'].' ('.$w['label'].')',
                        number_format((float) $w['fixed'], 2, '.', ''),
                        number_format((float) $w['variable'], 2, '.', ''),
                        number_format((float) $w['total'], 2, '.', ''),
                    ], $report['weeks']);

                    return ReportCsv::stream("weekly-spend-{$report['from']}-to-{$report['to']}", $headers, $rows);
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => collect($this->report()['weeks'])->keyBy('week_start'))
            ->columns([
                TextColumn::make('label')
                    ->label(__('admin.reports.week'))
                    ->description(fn (array $record): string => $record['week_start']),
                TextColumn::make('fixed')
                    ->label(__('admin.enums.cost_nature.fixed'))
                    ->money('EGP')
                    ->alignEnd()
                    ->color('info')
                    ->summarize(Summarizer::make('t')->label(__('admin.reports.totals'))->money('EGP')
                        ->using(fn (): float => (float) $this->report()['totals']['fixed'])),
                TextColumn::make('variable')
                    ->label(__('admin.enums.cost_nature.variable'))
                    ->money('EGP')
                    ->alignEnd()
                    ->color('warning')
                    ->summarize(Summarizer::make('t')->label(__('admin.reports.totals'))->money('EGP')
                        ->using(fn (): float => (float) $this->report()['totals']['variable'])),
                TextColumn::make('total')
                    ->label(__('admin.reports.totals'))
                    ->money('EGP')
                    ->alignEnd()
                    ->weight('bold')
                    ->summarize(Summarizer::make('t')->label(__('admin.reports.totals'))->money('EGP')
                        ->using(fn (): float => (float) $this->report()['totals']['total'])),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('admin.reports.no_spend_in_range'));
    }
}
