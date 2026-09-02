<?php

namespace App\Services\Reports;

use App\Services\Accounting\BudgetService;
use App\Services\Accounting\LedgerReportService;
use App\Support\StatementSection;
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

    /** The span immediately before this one, of the same length. Month vs last month. */
    public const PRIOR_PERIOD = 'prior_period';

    /** The SAME span one year earlier. March vs last March. */
    public const PRIOR_YEAR = 'prior_year';

    /**
     * The BUDGET for the same span — "is this what we planned?".
     *
     * Not a period at all, which is the whole reason it fits here without special-casing anything
     * downstream: `BudgetService::asIncomeStatement()` returns the same shape a prior period does,
     * so the change column, the percentages, the totals and the CSV all work unmodified.
     */
    public const BUDGET = 'budget';

    /** @var array<int, string> */
    public const BASES = [self::PRIOR_PERIOD, self::PRIOR_YEAR, self::BUDGET];

    /**
     * Which span to compare against.
     *
     * The two answer different questions and an accountant wants both at different moments.
     * **Prior period** asks "is this month normal?" — it catches a cost that has started running
     * away. **Prior year** asks "is this March normal?" — it is the only one that survives a
     * seasonal business, and a mall is seasonal: Ramadan and the back-to-school weeks move footfall
     * and therefore turnover rent, so comparing December to November says almost nothing.
     *
     * Prior period keeps the same LENGTH rather than the same calendar month, because comparing a
     * 31-day month against a 28-day one invents a variance that is really just February. Prior year
     * keeps the same calendar dates, because that is the point of it — and a leap day is a real
     * one-day difference rather than an artefact, so it is left alone.
     *
     * Public because `StatementSpread` derives a comparison column the same way. A second copy of
     * this derivation would let the spread and the plain comparison disagree about which months they
     * are looking at, which is the one thing a variance column may not do.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function priorSpan(CarbonImmutable $from, CarbonImmutable $to, string $basis): array
    {
        if ($basis === self::PRIOR_YEAR) {
            return [$from->subYear(), $to->subYear()];
        }

        // Same length, immediately before. `diffInDays` is inclusive-safe here because both ends
        // are start-of-day, and the prior span ends the day before this one begins.
        $priorTo = $from->subDay();

        return [$priorTo->subDays($from->startOfDay()->diffInDays($to->startOfDay())), $priorTo];
    }

    /**
     * @return array{
     *     basis: string, current: array<string, mixed>, prior: array<string, mixed>,
     *     prior_from: string, prior_to: string,
     *     rows: array<int, array{label: string, name_en: string, name_ar: string, account_id: ?int, code: ?string, section: string, current: float, prior: float, change: float, change_pct: ?float}>,
     *     totals: array<string, array{current: float, prior: float, change: float, change_pct: ?float}>,
     * }
     */
    public function incomeStatement(CarbonImmutable $from, CarbonImmutable $to, ?array $assetIds = null, string $basis = self::PRIOR_PERIOD): array
    {
        // The budget compares against THIS span, not an earlier one — the dates do not move, only
        // where the comparison figures come from.
        [$priorFrom, $priorTo] = $basis === self::BUDGET
            ? [$from, $to]
            : self::priorSpan($from, $to, $basis);

        $current = $this->reports->incomeStatement($assetIds, $from, $to);

        $prior = $basis === self::BUDGET
            ? app(BudgetService::class)->asIncomeStatement($from, $to, $assetIds)
            : $this->reports->incomeStatement($assetIds, $priorFrom, $priorTo);

        $rows = [];

        foreach (['revenue', 'expense'] as $section) {
            $priorBySection = collect($prior[$section])->keyBy(fn ($r) => $r['code'] ?? $r['label']);

            foreach ($current[$section] as $row) {
                $key = $row['code'] ?? $row['label'];
                $priorAmount = (float) ($priorBySection[$key]['amount'] ?? 0);

                $rows[] = self::line($section, $row, (float) $row['amount'], $priorAmount);
                $priorBySection->forget($key);
            }

            // An account that had activity LAST period and none this one. Dropping it would hide
            // exactly the change most worth seeing — a cost that stopped, or a revenue stream that
            // did.
            foreach ($priorBySection as $row) {
                $rows[] = self::line($section, $row, 0.0, (float) $row['amount']);
            }
        }

        return [
            'current' => $current,
            'prior' => $prior,
            'basis' => $basis,
            'prior_from' => $priorFrom->toDateString(),
            'prior_to' => $priorTo->toDateString(),
            'rows' => $rows,
            // Whether EITHER side has something below the NOI line. Either, not both: a cost that
            // ran last period and stopped is exactly the change a comparison exists to show, and
            // asking for both would drop it out of the statement entirely.
            'has_below_the_line' => (bool) ($current['has_below_the_line'] ?? false) || (bool) ($prior['has_below_the_line'] ?? false),
            'totals' => [
                'revenue' => self::delta((float) $current['total_revenue'], (float) $prior['total_revenue']),
                'expense' => self::delta((float) $current['total_expense'], (float) $prior['total_expense']),
                // `net_profit`, not `net` — the defensive `?? 0` this replaced would have compared 0 to 0
                // for ever, and silently: the row would have rendered as "no change" every month.
                'net' => self::delta((float) $current['net_profit'], (float) $prior['net_profit']),

                // ── The NOI split, compared the same way ──────────────────────────────────────
                // `noi` is the one an operator reads first: it says whether the MALL's trading
                // result moved, with the depreciation and interest that would otherwise muddy it
                // held out in their own rows.
                'operating_revenue' => self::delta((float) $current['total_operating_revenue'], (float) $prior['total_operating_revenue']),
                'operating_expense' => self::delta((float) $current['total_operating_expense'], (float) $prior['total_operating_expense']),
                'noi' => self::delta((float) $current['net_operating_income'], (float) $prior['net_operating_income']),
                'other_revenue' => self::delta((float) $current['total_other_revenue'], (float) $prior['total_other_revenue']),
                'other_expense' => self::delta((float) $current['total_other_expense'], (float) $prior['total_other_expense']),
            ],
        ];
    }

    /**
     * One comparative line, carrying the account it is about.
     *
     * It used to take a `$label` read as `$row['label']`, and **neither source emits that key** —
     * `LedgerReportService::statementRow()` and `BudgetService::asIncomeStatement()` both return
     * `name_en` / `name_ar`. So every row on the comparative income statement rendered with a code
     * and a BLANK account name, on all three bases, for the life of the screen. Found while giving
     * the statement its chart subtotals (EG-28) — the plain statement resolves the name by locale
     * and the comparative one, which is the same statement with two more columns, never did.
     *
     * `account_id` rides along for the same reason: the plain statement drills through to the
     * general ledger and the comparative one could not, because this method dropped the id rather
     * than because a comparison has nothing to open.
     *
     * @param  array<string, mixed>  $row
     * @return array{label: string, name_en: string, name_ar: string, account_id: ?int, code: ?string, section: string, current: float, prior: float, change: float, change_pct: ?float}
     */
    private static function line(string $section, array $row, float $current, float $prior): array
    {
        $nameEn = (string) ($row['name_en'] ?? $row['label'] ?? '');
        $nameAr = (string) ($row['name_ar'] ?? $row['label'] ?? '');

        return [
            // Kept, and now actually filled — one caller reads it, and a row with no name at all is
            // worse than one named in the wrong language.
            'label' => app()->getLocale() === 'ar' ? $nameAr : $nameEn,
            'name_en' => $nameEn,
            'name_ar' => $nameAr,
            'account_id' => isset($row['account_id']) ? (int) $row['account_id'] : null,
            'code' => $row['code'] ?? null,
            'section' => $section,
            // Which side of the net-operating-income line this account's result falls. Carried so a
            // comparison lays the statement out the SAME way the plain reading of it does — one
            // picker must not change what shape the statement is, only how many columns it has.
            // Floored here as well as at source, because a prior-only row can come from the budget.
            'statement_section' => StatementSection::for($row['statement_section'] ?? null, $section),
        ] + self::delta($current, $prior);
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
