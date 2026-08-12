<?php

namespace App\Filament\Admin\Pages\Concerns;

use App\Support\ReportCsv;
use App\Support\ReportXlsx;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Auth;

/**
 * The two ways a report leaves the screen (RP-07).
 *
 * Every deliverable report already offered CSV, and each of the fourteen declared its own copy of
 * the same action — five slightly different copies of it, in fact, which is what a hand-repeated
 * block becomes. Adding Excel to each would have made that twenty-eight.
 *
 * ## Why Excel is not just CSV with a different extension
 *
 * An accountant re-does the same four things to every CSV before it is usable: bold the header,
 * freeze it, widen the columns, and set a number format so 1234.5 reads as 1,234.50 rather than as
 * right-aligned text. Yardi hands them a workbook that already has all four, and that is the whole
 * of why the CSV gets reformatted by hand here today. See `App\Support\ReportXlsx`.
 *
 * ## One description, two formats
 *
 * Both actions read `DeliverableReport::reportCsv()` — the report describes its columns and rows
 * once. A separate `reportXlsx()` would drift, and the drift would be two exports of one report
 * disagreeing about what the report says.
 */
trait ExportsReport
{
    /**
     * CSV and Excel, gated identically.
     *
     * `reports.view` on both: the export IS the report, so anyone who may read it on screen may
     * take it away, and anyone who may not must not get a second door to it. Gated in `visible()`
     * AND `authorize()` because a `visible()`-only write is a stated intent rather than a check.
     *
     * @return array<int, Action>
     */
    protected function exportActions(): array
    {
        return [
            Action::make('export_csv')
                ->label(__('admin.reports.csv.export'))
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn (): bool => static::mayExport())
                ->authorize(fn (): bool => static::mayExport())
                ->action(function () {
                    $report = $this->reportCsv();

                    return ReportCsv::stream($report['filename'], $report['headers'], $report['rows']);
                }),
            Action::make('export_xlsx')
                ->label(__('admin.reports.xlsx.export'))
                ->icon('heroicon-o-document-chart-bar')
                ->color('gray')
                ->visible(fn (): bool => static::mayExport())
                ->authorize(fn (): bool => static::mayExport())
                ->action(function () {
                    $report = $this->reportCsv();

                    return ReportXlsx::stream($report['filename'], $report['headers'], $report['rows']);
                }),
        ];
    }

    /**
     * Named once so the two gates on each action cannot drift apart.
     *
     * Public like `canAccess()`, because a permission predicate is something a caller may
     * legitimately ask about — and a test that has to reach past `protected` to check a gate is a
     * test that will be written against the wrong thing instead.
     */
    public static function mayExport(): bool
    {
        return Auth::user()?->can('reports.view') ?? false;
    }
}
