<?php

namespace App\Support\Assistant;

use App\Support\ReportParameters;
use App\Support\Search\SearchText;

/**
 * "Is revenue better than last year?" — two runs of one report, and THE TOOL DOES THE SUBTRACTION.
 *
 * ## Why the arithmetic lives here and not in the prompt
 *
 * A model shown two tables can be asked to say which is larger, and it will usually be right. It
 * will also, sooner or later, be confidently wrong about a figure somebody is about to act on —
 * and a wrong delta is worse than a wrong sentence, because it looks like a result rather than an
 * opinion. Every other tier in this assistant refuses to let the model compute; a comparison is
 * exactly the moment that rule is most tempting to break, so it is the moment it is enforced
 * hardest. The differences below are computed in PHP, from figures the report produced, and the
 * model is handed the answer rather than the ingredients.
 *
 * ## It compares the same report against itself
 *
 * Both sides are the SAME report with a different year, so the two columns are commensurable by
 * construction. Comparing one report to another — revenue here, occupancy there — is a different
 * feature and a much harder one, and pretending otherwise is how a chart ends up subtracting
 * square metres from money.
 *
 * ## Rows are matched on their own first column
 *
 * That is the account code or line label the report itself put there. A row present in one year and
 * not the other is reported as NEW or GONE rather than as a change from zero — "revenue up 100%"
 * and "this line did not exist last year" are different statements and only one of them is true.
 */
final class PeriodCompare
{
    public const MAX_ROWS = 15;

    /**
     * Does this question ask for a comparison?
     *
     * Intent only, never scored — the same rule the create and count verbs follow.
     *
     * @param  array<int, string>  $words
     */
    public static function isComparing(array $words): bool
    {
        $verbs = SearchText::words((string) __('admin.assistant.compare.verbs'));

        return array_intersect($words, $verbs) !== [];
    }

    /**
     * The two years to compare, read from the question.
     *
     * Two named years wins; one named year compares against the year before it; naming none
     * compares this year with last. Never invented beyond that — a question about "the last three
     * quarters" gets no comparison rather than a guess at which two it meant.
     *
     * @param  array<int, string>  $words
     * @return array{0: int, 1: int}
     */
    public static function years(array $words): array
    {
        $found = [];

        foreach ($words as $word) {
            if (preg_match('/^20\d{2}$/', $word) && (int) $word <= 2100) {
                $found[] = (int) $word;
            }
        }

        $found = array_values(array_unique($found));

        return match (count($found)) {
            0 => [(int) now()->year - 1, (int) now()->year],
            1 => [$found[0] - 1, $found[0]],
            default => [min($found[0], $found[1]), max($found[0], $found[1])],
        };
    }

    /**
     * @param  array<int, string>  $words
     * @return array{title: string, body: string}|null
     */
    public static function for(string $page, array $words): ?array
    {
        // Only a report that actually takes a year can be compared by year. Anything else would be
        // two identical runs presented as a trend, which is worse than no answer.
        if (! array_key_exists('year', ReportParameters::parametersOf($page))) {
            return null;
        }

        [$earlier, $later] = self::years($words);

        $a = ReportRunner::run($page, ['year' => $earlier]);
        $b = ReportRunner::run($page, ['year' => $later]);

        if ($a === null || $b === null) {
            return null;
        }

        $lines = self::diff($a, $b, $earlier, $later);

        if ($lines === []) {
            return null;
        }

        return [
            'title' => __('admin.assistant.compare.title', ['a' => $earlier, 'b' => $later]),
            'body' => implode("\n", $lines),
        ];
    }

    /**
     * @param  array{headers: array<int, string>, rows: array<int, array<int, mixed>>, total: int, truncated: bool}  $a
     * @param  array{headers: array<int, string>, rows: array<int, array<int, mixed>>, total: int, truncated: bool}  $b
     * @return array<int, string>
     */
    /**
     * PUBLIC because the arithmetic is the thing that has to be right, and it should be provable
     * without seeding a ledger. Given two report results it returns the sentences — including the
     * differences — so a test can hand it known figures and check the subtraction.
     */
    public static function diff(array $a, array $b, int $earlier, int $later): array
    {
        $index = static function (array $result): array {
            $byKey = [];

            foreach ($result['rows'] as $row) {
                $key = (string) ($row[0] ?? '');

                if ($key !== '') {
                    $byKey[$key] = $row;
                }
            }

            return $byKey;
        };

        $left = $index($a);
        $right = $index($b);
        $lines = [];

        foreach ($right as $key => $row) {
            if (count($lines) >= self::MAX_ROWS) {
                break;
            }

            if (! isset($left[$key])) {
                $lines[] = __('admin.assistant.compare.new_line', ['label' => $key, 'year' => $later]);

                continue;
            }

            // The LAST numeric cell is the figure a financial statement row carries; earlier cells
            // are the code, the name and the type. Matched by position in both runs, which holds
            // because it is the same report.
            $was = self::figure($left[$key]);
            $now = self::figure($row);

            if ($was === null || $now === null) {
                continue;
            }

            $lines[] = __('admin.assistant.compare.line', [
                'label' => $key,
                'a_year' => $earlier,
                'a' => self::money($was),
                'b' => self::money($now),
                // COMPUTED HERE. The model is handed this number, never the two that made it.
                'change' => ($now - $was >= 0 ? '+' : '').self::money($now - $was),
            ]);
        }

        foreach ($left as $key => $row) {
            if (! isset($right[$key]) && count($lines) < self::MAX_ROWS) {
                $lines[] = __('admin.assistant.compare.gone_line', ['label' => $key, 'year' => $earlier]);
            }
        }

        return $lines;
    }

    /** The last numeric cell in a row, or null when the row carries no figure. */
    private static function figure(array $row): ?float
    {
        foreach (array_reverse($row) as $cell) {
            $clean = str_replace([',', ' '], '', (string) $cell);

            if ($clean !== '' && is_numeric($clean)) {
                return (float) $clean;
            }
        }

        return null;
    }

    /** Two places, because that is what every money figure in this system is rendered at. */
    private static function money(float $value): string
    {
        return number_format($value, 2, '.', ',');
    }
}
