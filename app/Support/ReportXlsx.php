<?php

namespace App\Support;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A report as a real spreadsheet, not a CSV wearing an .xlsx name (RP-07).
 *
 * Every report here already exports CSV, and an accountant re-does the same four things to every one
 * of them before it is usable: bold the header, freeze it, widen the columns, and set a number
 * format so 1234.5 reads as 1,234.50 instead of being right-aligned text. Yardi hands them a
 * workbook that has all four. This is why the CSV gets reformatted by hand today.
 *
 * ## What it actually does differently from CSV
 *
 *   - **Frozen, filtered header row** — a 400-line rent roll is unreadable when the header scrolls
 *     away, and the filter is how an accountant asks a question of it without writing a formula.
 *   - **Numbers stay numbers.** A CSV hands Excel a string and Excel guesses; it reads `01234` as
 *     1234 and `2026-03-01` as a date in whatever the machine's locale says. Here the type is
 *     declared, so a total is a number that sums and a code is text that keeps its leading zero.
 *   - **Money is formatted, not rounded.** `#,##0.00` is a display format — the stored value keeps
 *     its precision, so a column summed in Excel agrees with the report's own total instead of
 *     drifting by the rounding of each cell.
 *
 * ## The same contract as the CSV
 *
 * Takes `{filename, headers, rows}` — exactly what `App\Contracts\DeliverableReport::reportCsv()`
 * already returns. That is deliberate: every report that can already export gets Excel for free and
 * no report has to describe itself twice. A second description would drift, and the drift would be
 * two exports of one report disagreeing.
 */
class ReportXlsx
{
    /** Excel's own money mask. Display only — the stored value keeps full precision. */
    public const MONEY_FORMAT = '#,##0.00';

    /**
     * Stream the workbook as a download.
     *
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    public static function stream(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(
            fn () => self::write($headers, $rows),
            self::filename($filename),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /** `.xlsx`, once — a report whose own name already ends in it must not become `x.xlsx.xlsx`. */
    public static function filename(string $filename): string
    {
        return str_ends_with(strtolower($filename), '.xlsx') ? $filename : $filename.'.xlsx';
    }

    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    private static function write(array $headers, iterable $rows): void
    {
        $options = new Options;

        // Wide enough that a date or a formatted total is not rendered as ####. Excel's default is
        // narrow enough that most money columns arrive unreadable.
        $options->setColumnWidth(28, 1);
        $options->setColumnWidth(16, ...range(2, max(2, count($headers))));

        $writer = new Writer($options);
        $writer->openToFile('php://output');

        $sheet = $writer->getCurrentSheet();

        // A fresh sheet carries NO view (`getSheetView()` is nullable and starts null), so this is
        // constructed rather than chained off the getter — which would be a null-call fatal on the
        // very first export.
        $view = (new SheetView)
            // Frozen under the header. Row 1 is the header, so freezing at 2 means "everything
            // above row 2 stays put" — a 400-line rent roll is unreadable when the header scrolls
            // away.
            ->setFreezeRow(2)
            // Arabic reads right to left, and a workbook that opens left-aligned makes an Arabic
            // report read as if the first column were last. The sheet carries the direction the
            // report was RENDERED in, not the reader's machine locale.
            ->setRightToLeft(app()->getLocale() === 'ar');

        $sheet->setSheetView($view);

        if ($headers !== []) {
            $sheet->setAutoFilter(new AutoFilter(1, 1, count($headers), 1));
        }

        $writer->addRow(Row::fromValues($headers, self::headerStyle()));

        $money = (new Style)->setFormat(self::MONEY_FORMAT);

        foreach ($rows as $row) {
            $writer->addRow(self::row(array_values((array) $row), $money));
        }

        $writer->close();
    }

    /**
     * One row, with money cells formatted and everything else left alone.
     *
     * Typed per CELL rather than per column, because these reports interleave: a statement's
     * "amount" column carries floats on account lines and the same column carries a section total.
     * Deciding by value is what keeps a numeric cell numeric without a per-report column map that
     * every new report would have to remember to write.
     *
     * @param  array<int, mixed>  $values
     */
    private static function row(array $values, Style $money): Row
    {
        $row = Row::fromValues($values);

        foreach ($row->getCells() as $index => $cell) {
            // A float is money or a quantity; either way it should right-align and carry two
            // decimals. An INT is deliberately excluded — a count, a year or an id formatted as
            // 2,026.00 is worse than useless.
            if (is_float($values[$index] ?? null)) {
                $cell->setStyle($money);
            }
        }

        return $row;
    }

    private static function headerStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setBackgroundColor('EEEEEE')
            ->setCellAlignment(CellAlignment::LEFT);
    }
}
