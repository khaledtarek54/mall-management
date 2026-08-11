<?php

namespace App\Services\Reports;

use App\Services\Accounting\LedgerReportService;
use Carbon\CarbonImmutable;

/**
 * An income statement beside the period before it — the first thing an accountant asks for once the
 * books are real.
 *
 * A single period's P&L says what happened; it cannot say whether that is normal. 180,000 of
 * maintenance is unremarkable next to 175,000 last month and alarming next to 40,000, and the
 * statement as it stood could not tell those apart.
 *
 * **The comparison period is derived, not asked for.** It is the immediately preceding span of the
 * SAME length, ending the day before this one starts — so a month compares to the month before and
 * a quarter to the quarter before, without anyone choosing dates twice and getting them subtly
 * wrong. Comparing a 31-day month against a 28-day one is the classic way to invent a variance that
 * is really just February.
 *
 * Built ON `LedgerReportService::incomeStatement()` rather than beside it: one definition of what
 * revenue and expense mean, queried twice. A second implementation would drift, and the drift would
 * show up as a variance nobody could explain.
 */
class ComparativeStatementService
{
    public function __construct(private LedgerReportService $reports) {}

    /**
     * @return array{
     *     current: array<string, mixed>, prior: array<string, mixed>,
     *     prior_from: string, prior_to: string,
     *     rows: array<int, array{label: string, code: ?string, section: string, current: float, prior: float, change: float, change_pct: ?float}>,
     *     totals: array<string, array{current: float, prior: float, change: float, change_pct: ?float}>,
     * }
     */
    public function incomeStatement(CarbonImmutable $from, CarbonImmutable $to, ?array $assetIds = null): array
    {
        // Same length, immediately before. `diffInDays` is inclusive-safe here because both ends are
        // start-of-day, and the prior span ends the day before this one begins.
        $length = $from->startOfDay()->diffInDays($to->startOfDay());
        $priorTo = $from->subDay();
        $priorFrom = $priorTo->subDays($length);

        $current = $this->reports->incomeStatement($assetIds, $from, $to);
        $prior = $this->reports->incomeStatement($assetIds, $priorFrom, $priorTo);

        $rows = [];

        foreach (['revenue', 'expense'] as $section) {
            $priorBySection = collect($prior[$section])->keyBy(fn ($r) => $r['code'] ?? $r['label']);

            foreach ($current[$section] as $row) {
                $key = $row['code'] ?? $row['label'];
                $priorAmount = (float) ($priorBySection[$key]['amount'] ?? 0);

                $rows[] = self::line($section, $row['label'] ?? '', $row['code'] ?? null, (float) $row['amount'], $priorAmount);
                $priorBySection->forget($key);
            }

            // An account that had activity LAST period and none this one. Dropping it would hide
            // exactly the change most worth seeing — a cost that stopped, or a revenue stream that
            // did.
            foreach ($priorBySection as $row) {
                $rows[] = self::line($section, $row['label'] ?? '', $row['code'] ?? null, 0.0, (float) $row['amount']);
            }
        }

        return [
            'current' => $current,
            'prior' => $prior,
            'prior_from' => $priorFrom->toDateString(),
            'prior_to' => $priorTo->toDateString(),
            'rows' => $rows,
            'totals' => [
                'revenue' => self::delta((float) $current['total_revenue'], (float) $prior['total_revenue']),
                'expense' => self::delta((float) $current['total_expense'], (float) $prior['total_expense']),
                // `net_profit`, not `net` — the defensive `?? 0` this replaced would have compared 0 to 0
                // for ever, and silently: the row would have rendered as "no change" every month.
                'net' => self::delta((float) $current['net_profit'], (float) $prior['net_profit']),
            ],
        ];
    }

    /** @return array{label: string, code: ?string, section: string, current: float, prior: float, change: float, change_pct: ?float} */
    private static function line(string $section, string $label, ?string $code, float $current, float $prior): array
    {
        return ['label' => $label, 'code' => $code, 'section' => $section] + self::delta($current, $prior);
    }

    /**
     * @return array{current: float, prior: float, change: float, change_pct: ?float}
     */
    private static function delta(float $current, float $prior): array
    {
        return [
            'current' => round($current, 2),
            'prior' => round($prior, 2),
            'change' => round($current - $prior, 2),
            // Null rather than infinity when the prior period was zero. "New this period" is a real
            // answer and a percentage cannot express it; rendering ∞ or 100% would both be lies.
            'change_pct' => abs($prior) < 0.005 ? null : round(($current - $prior) / abs($prior) * 100, 1),
        ];
    }
}
