<?php

namespace App\Support\Assistant;

use App\Contracts\DeliverableReport;
use App\Filament\Admin\Pages\ActivityLog;
use App\Filament\Admin\Pages\ClauseRegister;
use App\Support\ReportParameters;
use DomainException;

/**
 * Runs a catalogued report and hands back its actual figures.
 *
 * ## The model does not choose the report, and that is the whole design
 *
 * Retrieval already ranked it — "who owes us money" lands on AR aging, and
 * `TheAssistantAnswersTheseQuestionsTest` pins that. So there is no tool-calling loop here, no
 * function-calling dialect to keep working across providers, and nothing for the model to get wrong
 * about WHICH numbers to look at. It is handed the rows and asked to read them.
 *
 * ## It runs as the reader, so it cannot show what they could not open
 *
 * Same seam `DeliverSavedReportService` uses for scheduled delivery: check the reader's own
 * `canAccess()`, mount, apply parameters, take `reportCsv()`. The report's own query carries the
 * property scope and the permissions, so the figures are exactly the ones on screen — which is the
 * point. An assistant that quietly disagrees with the AR aging page is worse than one that cannot
 * count.
 *
 * ## Two reports genuinely cannot run
 *
 * `ClauseRegister` and `ActivityLog` are table pages whose `$table` only initialises inside a
 * mounted Livewire component, so `reportCsv()` fatals anywhere else — see
 * `EveryDeliverableReportCanActuallyRenderTest`. They are skipped by NAME rather than by catching
 * the fatal, because catching `Error` to paper over a known structural limit is how it stops being
 * known.
 */
final class ReportRunner
{
    /** Rows carried into the answer. Beyond this the reader is sent to the report itself. */
    public const MAX_ROWS = 25;

    /**
     * Cannot render outside a mounted Livewire component. Fixing that is real work; pretending it
     * is a transient error is not.
     *
     * @var array<int, class-string>
     */
    public const CANNOT_RUN_HEADLESS = [
        ClauseRegister::class,
        ActivityLog::class,
    ];

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{headers: array<int, string>, rows: array<int, array<int, mixed>>, total: int, truncated: bool}|null
     */
    public static function run(string $page, array $parameters = []): ?array
    {
        if (! is_a($page, DeliverableReport::class, true) || in_array($page, self::CANNOT_RUN_HEADLESS, true)) {
            return null;
        }

        // The reader's own permission, asked before anything is computed.
        if (! rescue(fn (): bool => (bool) $page::canAccess(), false, report: false)) {
            return null;
        }

        return rescue(function () use ($page, $parameters): ?array {
            $instance = app($page);

            // Not every page defines `mount()` — `Filament\Pages\Page` declares none.
            if (method_exists($instance, 'mount')) {
                $instance->mount();
            }

            if ($parameters !== []) {
                ReportParameters::apply($instance, $parameters);
            }

            $csv = $instance->reportCsv();

            $rows = $csv['rows'] ?? [];
            $total = count($rows);

            return [
                'headers' => $csv['headers'] ?? [],
                'rows' => array_slice($rows, 0, self::MAX_ROWS),
                'total' => $total,
                'truncated' => $total > self::MAX_ROWS,
            ];
        }, function (\Throwable $e): ?array {
            // A DomainException is a REFUSAL — the general ledger declines until an account is
            // chosen — and the honest response is no figures rather than a guess at what it meant.
            return null;
        }, report: false);
    }

    /**
     * The figures as text the model can read, with the tail stated rather than silently cut.
     *
     * "The top 25 debtors" and "your debtors" are different claims, and a truncation the reader
     * cannot see turns the first into the second.
     *
     * @param  array{headers: array<int, string>, rows: array<int, array<int, mixed>>, total: int, truncated: bool}  $result
     */
    public static function asText(array $result): string
    {
        if ($result['rows'] === []) {
            return __('admin.assistant.report.empty');
        }

        $lines = [implode(' | ', $result['headers'])];

        foreach ($result['rows'] as $row) {
            $lines[] = implode(' | ', array_map(fn ($cell): string => (string) $cell, $row));
        }

        if ($result['truncated']) {
            $lines[] = __('admin.assistant.report.truncated', [
                'shown' => count($result['rows']),
                'total' => $result['total'],
            ]);
        }

        return implode("\n", $lines);
    }
}
