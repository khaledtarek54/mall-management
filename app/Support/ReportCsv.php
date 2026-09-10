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
     * Split one CSV line the way {@see put()} wrote it — the READ side of the same control.
     *
     * The escape character has to agree at both ends or a round trip is not one, and it did not:
     * two importers hard-coded PHP's backslash default while two more passed nothing at all (a
     * PHP 8.4 deprecation, and a silent behaviour change in PHP 9). Escape-off is right for both
     * sources these importers actually read — a file this system exported, and a file Excel wrote,
     * since Excel has never used backslash escaping either.
     *
     * One function so the pair cannot drift, which is the whole reason the writer has one too.
     *
     * @return array<int, string>
     */
    public static function parse(string $line): array
    {
        return array_map(
            fn ($cell): string => trim((string) $cell),
            str_getcsv($line, ',', '"', ''),
        );
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

        self::put($out, $headers);

        foreach ($rows as $row) {
            self::put($out, $row);
        }
    }

    /**
     * One `fputcsv` call, with the escape character switched OFF.
     *
     * PHP's default `$escape` is a backslash, which is not RFC 4180: measured, the field `a\"b`
     * came out as `"a\"b"` with the enclosure left UNDOUBLED, because the backslash was taken as
     * escaping it. Read by anything that follows the standard — Excel, or {@see parse()} — that
     * quote closes the field and every column after it shifts. (Read by `fgetcsv` at its own
     * backslash default it comes back correctly, which is precisely why the defect hides: the
     * writer and the matching reader agree with each other and with nobody else.)
     *
     * Passing `''` disables it, so the enclosure is doubled the way every spreadsheet expects and
     * the same values round-trip byte-identically. It also settles the PHP 8.4 deprecation, whose
     * own message says only that "its default value will change" in PHP 9 without saying to what —
     * so stating it here is what makes this independent of that change either way, rather than a
     * bet on the direction.
     *
     * @param  resource  $out
     * @param  array<int, string|int|float|null>  $row
     */
    private static function put($out, array $row): void
    {
        fputcsv($out, $row, ',', '"', '');
    }
}
