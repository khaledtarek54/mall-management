<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a report as CSV — the format an accountant actually works in (pivot, reconcile, import,
 * hand to an auditor), unlike a PDF which only presents. One place so every report exports the same
 * way: a leading UTF-8 BOM (Excel needs it to render Arabic/UTF-8 correctly), `fputcsv` quoting, and
 * a streamed response so a large General Ledger never has to be held in memory.
 */
class ReportCsv
{
    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, string|int|float|null>>  $rows
     */
    public static function stream(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        $safe = str_replace(['/', '\\', ' '], '-', $filename);
        if (! str_ends_with($safe, '.csv')) {
            $safe .= '.csv';
        }

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');

            // BOM first — without it Excel reads UTF-8 Arabic as mojibake.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $safe, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
