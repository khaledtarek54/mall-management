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
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            self::write($out, $headers, $rows);
            fclose($out);
        }, self::filename($filename), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * The same CSV as a string, for a report that is being EMAILED rather than downloaded.
     *
     * Scheduled delivery has no browser to stream to, and an attachment needs the bytes. This is
     * the same writer the download uses — BOM, quoting and all — because a report that arrives in
     * an inbox reading differently from the one an operator exported is a support ticket nobody can
     * reproduce.
     *
     * Held in memory, unlike {@see stream()}: a delivery runs on a queue against a bounded report,
     * and an attachment has to be materialised to be attached anyway.
     *
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, string|int|float|null>>  $rows
     */
    public static function toString(array $headers, iterable $rows): string
    {
        $out = fopen('php://temp', 'r+');
        self::write($out, $headers, $rows);
        rewind($out);
        $csv = stream_get_contents($out) ?: '';
        fclose($out);

        return $csv;
    }

    /** A filename that is safe on every platform and always ends in .csv. */
    public static function filename(string $filename): string
    {
        $safe = str_replace(['/', '\\', ' '], '-', $filename);

        return str_ends_with($safe, '.csv') ? $safe : $safe.'.csv';
    }

    /**
     * @param  resource  $out
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, string|int|float|null>>  $rows
     */
    private static function write($out, array $headers, iterable $rows): void
    {
        // BOM first — without it Excel reads UTF-8 Arabic as mojibake.
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, $headers);

        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
    }
}
