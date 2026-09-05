<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Lease;
use Carbon\CarbonImmutable;

/**
 * What will this lease be invoiced, period by period, from here to the end of the window?
 *
 * **The gap it fills.** Four screens described a lease's money and none of them answered this one.
 * The Charge schedule holds the RULES (bill 72,000 a month from 1 October — one row, not thirty-six,
 * because storing the months as well as the rule is storing the same fact twice). Rent Roll is a
 * snapshot of today. Billing Run Preview covers one period across every lease. The Invoices tab is
 * history. So the question an operator, an owner and a leasing negotiator all actually ask —
 * *"what does this tenancy bill next year?"* — had no screen at all, which is why the schedule kept
 * being read as if it were a payment plan and found wanting.
 *
 * **It computes nothing itself.** Every row comes from `MonthlyBillingService::planInvoiceForLease()`
 * — the same method the real run persists verbatim and the preview renders. A forecast with its own
 * arithmetic is a forecast that can disagree with the invoice it predicts, and would do so first on
 * exactly the cases that matter: a proration edge, a cycle boundary, an escalation step. Here a
 * change to billing changes the forecast in the same commit, or the forecast is wrong loudly rather
 * than quietly.
 *
 * Periods already invoiced are shown as what they ACTUALLY billed, not as what they would have —
 * otherwise a re-priced lease would forecast a past month at today's rent and read as a discrepancy
 * that isn't one.
 */
class LeaseBillingForecastService
{
    /** How far ahead to look. Two years covers an escalation step on any annual clause. */
    public const HORIZON_MONTHS = 24;

    /**
     * A hard stop on rows, not months: a monthly lease produces one row per month, and a modal
     * listing sixty of them is a wall rather than an answer. The window is reported as truncated so
     * the reader knows the schedule continues.
     */
    public const MAX_ROWS = 24;

    public function __construct(private readonly MonthlyBillingService $billing) {}

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     from: CarbonImmutable,
     *     to: CarbonImmutable,
     *     total: float,
     *     cycle_months: int,
     *     truncated: bool,
     *     lease_is_active: bool,
     * }
     */
    public function forecast(Lease $lease, ?CarbonImmutable $from = null, int $horizonMonths = self::HORIZON_MONTHS): array
    {
        // ── THE FORECAST MUST NOT SHOW A CHARGE THE BILLING RUN WILL NOT RAISE (2026-08-28) ──
        //
        // `MonthlyBillingService` narrows to `is_active` charges with `loadMissing()`, which means
        // "load it IF it is not loaded" — so whoever loads the relation FIRST decides what the
        // planner sees. This loaded it unfiltered, one call earlier, and the planner then reused
        // that collection: a stopped charge went on appearing in the forecast for ever, while the
        // billing run correctly ignored it.
        //
        // Reported from the panel: a one-off ended through "End charge" (is_active = 0) kept
        // showing in October's forecast. The screen said the tenant would be billed for something
        // the run would never raise — which is worse than a wrong figure, because it is a figure
        // nobody can reconcile against the invoice when it arrives.
        //
        // Filtered here rather than by making the planner re-load: `loadMissing` is right in the
        // planner (it must not re-query for every one of a thousand leases in a run), so the fix
        // belongs where the unfiltered load happens.
        $lease->loadMissing(['charges' => fn ($query) => $query->where('is_active', true)]);

        // Start at whichever comes later: this month, or the month the lease first bills. A forecast
        // that opens on a month already behind us is a report, and there is one of those already.
        $firstBillable = $lease->firstBillableMonth();
        $cursor = $from?->startOfMonth() ?? CarbonImmutable::now()->startOfMonth();

        if ($firstBillable !== null && $firstBillable->greaterThan($cursor)) {
            $cursor = $firstBillable;
        }

        $windowStart = $cursor;
        $windowEnd = $cursor->addMonths(max($horizonMonths, 1) - 1)->endOfMonth();

        // Never forecast past the term. A holdover lease is the exception its own flag makes: its
        // expiry is deliberately in the past and `holdover_from` is what keeps it billing.
        $lastMonth = $windowEnd;

        if (filled($lease->expiry_date) && $lease->holdover_from === null) {
            $expiryMonthEnd = CarbonImmutable::instance($lease->expiry_date)->endOfMonth();

            if ($expiryMonthEnd->lessThan($lastMonth)) {
                $lastMonth = $expiryMonthEnd;
            }
        }

        $billed = $this->invoicesByPeriodMonth($lease);

        $rows = [];
        $hitRowCap = false;

        while (! $cursor->greaterThan($lastMonth)) {
            if (count($rows) >= self::MAX_ROWS) {
                $hitRowCap = true;
                break;
            }

            $plan = $this->billing->planInvoiceForLease(
                $lease,
                $cursor,
                $cursor->endOfMonth(),
                // Matches what the scheduled run passes, so the first row of a mid-month
                // commencement shows the part-month it will actually be billed.
                prorate: true,
            );

            // A mid-cycle month on a quarterly lease is not a gap in the schedule — the cycle-start
            // row above it already covers those months. Listing it as "nothing bills" would read as
            // a hole in the tenant's obligations.
            if (! $plan['billable'] && $plan['reason'] === 'off_cycle') {
                $cursor = $cursor->addMonth();

                continue;
            }

            $invoice = $billed[self::periodKey(
                CarbonImmutable::instance($plan['period_start']),
                CarbonImmutable::instance($plan['period_end']),
            )] ?? null;

            $rows[] = [
                'period_start' => $plan['period_start'],
                'period_end' => $plan['period_end'],
                'cycle_months' => $plan['cycle_months'],
                'billable' => $plan['billable'],
                'reason' => $plan['reason'],
                // ── AN INVOICED PERIOD READS FROM ITS INVOICE, IN EVERY COLUMN (2026-08-28) ──
                //
                // The reasoning below was already right and was applied to ONE figure: `total` came
                // from the invoice while the lines, the net and the VAT beside it stayed the plan.
                // So a period whose charge was corrected AFTER it was billed rendered a row of four
                // columns from two different truths — reported from the panel as a service charge
                // reading 14,000 against an invoice total of 58,740 that was raised at 11,000.
                //
                // The plan is a prediction; once the document exists there is nothing left to
                // predict. Reading the whole row from it also makes the difference VISIBLE, which is
                // the useful part: the operator sees what was actually billed and can decide whether
                // the shortfall needs collecting.
                ...($invoice
                    ? self::actuals($invoice)
                    : [
                        'items' => $plan['items'],
                        'subtotal' => $plan['subtotal'],
                        'vat_amount' => $plan['vat_amount'],
                        'total' => $plan['total'],
                    ]),
                'invoice_number' => $invoice?->number,
                // So the screen can link the figure to the document that produced it.
                'invoice_id' => $invoice?->getKey(),
            ];

            // Skip the months this row already covers rather than re-planning each one to be told
            // it is off-cycle.
            $cursor = $cursor->addMonths(max((int) $plan['cycle_months'], 1));
        }

        // "Truncated" means THE SCHEDULE CONTINUES PAST WHAT IS SHOWN — which is a different question
        // from "did we hit the row cap". A 60-month lease whose 24 monthly rows exactly fill the
        // 24-month horizon hits no cap and is still cut short by three years; reporting that as a
        // complete forecast would let someone read the last row as the end of the tenancy.
        $lastShown = $rows === [] ? null : end($rows)['period_end'];
        $termEnd = filled($lease->expiry_date) && $lease->holdover_from === null
            ? CarbonImmutable::instance($lease->expiry_date)->endOfMonth()
            : null;

        return [
            'rows' => $rows,
            'from' => $windowStart,
            'to' => $lastMonth,
            'total' => round(array_sum(array_column($rows, 'total')), 2),
            'cycle_months' => $lease->billingCycleMonths(),
            'truncated' => $hitRowCap
                // An open-ended lease (or a holdover) never reaches a term end, so it always runs on.
                || ($lastShown !== null && ($termEnd === null || $termEnd->greaterThan($lastShown))),
            'lease_is_active' => $lease->status === 'active',
        ];
    }

    /**
     * Invoices already raised, keyed by the MONTH their period starts in — which is the cycle-start
     * month for a quarterly lease, so it lines up with the forecast's own cursor.
     *
     * Cancelled invoices are excluded: a cancelled period is one that still needs billing.
     *
     * @return array<string, Invoice>
     */
    /** One period, written the same way on both sides of the match. */
    private static function periodKey(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return $start->toDateString().'|'.$end->toDateString();
    }

    /**
     * What an invoiced period ACTUALLY carries, shaped exactly like a plan so the row builder above
     * cannot tell the two apart — one place decides the shape, so a new column added to the plan
     * cannot quietly go on reading the forecast for periods that have already been billed.
     *
     * @return array<string, mixed>
     */
    private static function actuals(Invoice $invoice): array
    {
        return [
            'items' => $invoice->items->map(fn ($item): array => [
                'type' => $item->type,
                'description' => $item->narrative(),
                'amount' => (float) $item->amount,
                'vat_amount' => (float) $item->vat_amount,
            ])->all(),
            'subtotal' => (float) $invoice->subtotal,
            'vat_amount' => (float) $invoice->vat_amount,
            'total' => (float) $invoice->total,
        ];
    }

    private function invoicesByPeriodMonth(Lease $lease): array
    {
        return $lease->invoices()
            ->whereNotIn('status', ['cancelled'])
            ->whereNotNull('period_start')
            // The LINES too, not just the header — the forecast row reads every figure from the
            // invoice once one exists, so the columns cannot disagree with each other.
            // `description_key` and `description_data` MUST be in this select. Without them
            // `narrative()` reads two nulls and falls silently to the frozen prose — no error, no
            // strict-mode complaint, just the old behaviour wearing the new call. Found by review.
            ->with('items:id,invoice_id,type,description,description_key,description_data,amount,vat_amount')
            ->whereNotNull('period_end')
            ->get(['id', 'number', 'total', 'subtotal', 'vat_amount', 'period_start', 'period_end', 'status'])
            // ── KEYED BY THE WHOLE PERIOD, NOT THE MONTH IT STARTS IN (2026-08-28) ──────────
            //
            // A month can carry more than one invoice, and `keyBy` keeps the LAST. A SECURITY
            // DEPOSIT invoice takes the lease's own term as its period — commencement to expiry —
            // so its `period_start` falls in the commencement month and it was matched as that
            // month's billing. Measured: a month forecasting 59,960 of rent and service charge
            // showed 132,000 of deposit and nothing else, so the period read as INVOICED while the
            // rent had not been billed at all.
            //
            // Matching BOTH ends is exact: the billing run raises an invoice whose period is the
            // row's own period, and a document covering three years is not one of them. It also
            // does the right thing for a quarterly cycle, where the row and its invoice both span
            // three months.
            ->keyBy(fn (Invoice $invoice) => self::periodKey(
                CarbonImmutable::instance($invoice->period_start),
                CarbonImmutable::instance($invoice->period_end),
            ))
            ->all();
    }
}
