<?php

namespace App\Services;

use App\Support\OpsLog;
use Carbon\CarbonImmutable;

/**
 * Which lease-months the term covered were never invoiced — the failure that is an ABSENT row.
 *
 * **Why this needs a sweep of its own.** `RunMonthlyBilling` bills exactly one period:
 * `now()->startOfMonth()`, and no caller loops over missed ones — deliberately, because posting a
 * burst of back-dated entries into possibly-closed periods is the trap `expenses:generate-recurring`
 * already states in writing ("ONE period per run"). So every route that leaves a month behind leaves
 * it behind silently:
 *
 *   - a renewal signed after the old term ended, dated back to the day after it (the common one —
 *     the tenancy has no gap, and the elapsed months are nobody's job);
 *   - a lease keyed in with a back-dated commencement;
 *   - a billing night that failed, where the catch-up was run for one period and not the others;
 *   - a draft or cancelled invoice, which `alreadyBilledForMonth()` correctly does NOT count as
 *     billed, leaving that month open with nothing pointing at it.
 *
 * Nothing reports any of it. The register looks healthy, every invoice on it is correct, and the
 * money for those months simply never appears — the same shape as `pdc:scan-coverage`, whose
 * docblock says it first: a scan of what EXISTS cannot see what does not.
 *
 * **It reports; it does not bill.** Raising the invoices here would post into periods that may be
 * closed, on a schedule, with no operator deciding the posting date — and `Invoice` carries
 * `#[PostingDateGuardedBy]` precisely so that decision is a person's. The operator raises them from
 * the lease's Billing forecast tab, one period at a time, which is the act that already exists.
 * Yardi is the same: Voyager posts charges per period and a back-dated lease means running that
 * post, not a silent catch-up.
 *
 * **Every figure comes from `MonthlyBillingService::previewForPeriod()`** — the real run's own dry
 * run, using the same `billableForPeriod()` scope, the same `alreadyBilledForMonth()` probe and the
 * same `planInvoiceForLease()` planner. A second definition of "should this month have billed"
 * would eventually disagree with the run, and then this scan would be reporting on a rule the
 * system does not follow.
 *
 * The CURRENT month is excluded and that is not an off-by-one: it has not been billed YET. Its
 * billing day may still be ahead (`App\Support\BillingDay` — a property may bill on the 25th), and
 * a scan that flagged it would fire on the whole portfolio for most of every month.
 */
class ScanUnbilledPeriodsService
{
    /** How far back to look when the caller does not say. */
    public const DEFAULT_LOOKBACK_MONTHS = 12;

    public function __construct(private readonly MonthlyBillingService $billing) {}

    /**
     * @return array{
     *     months_scanned:int,
     *     gaps:int,
     *     total:float,
     *     rows:list<array{period:string, lease_id:int, lease_reference:?string, tenant_name:?string, unit_code:?string, total:float}>
     * }
     */
    public function run(int $lookbackMonths = self::DEFAULT_LOOKBACK_MONTHS, ?CarbonImmutable $today = null): array
    {
        $lookbackMonths = max(1, $lookbackMonths);
        $thisMonth = ($today ?? CarbonImmutable::now())->startOfMonth();

        $rows = [];
        $total = 0.0;
        $monthsScanned = 0;

        // Oldest first, so the output reads forward like a ledger rather than backwards from today.
        for ($back = $lookbackMonths; $back >= 1; $back--) {
            $period = $thisMonth->subMonths($back);
            $preview = $this->billing->previewForPeriod($period);
            $monthsScanned++;

            foreach ($preview['rows'] as $row) {
                // `billable === true` for a month that has already run means the planner would
                // raise an invoice today and none exists. A skipped row is either already billed
                // or refused for a stated reason, and neither is a gap.
                if (($row['billable'] ?? false) !== true) {
                    continue;
                }

                $rows[] = [
                    'period' => $preview['period'],
                    'lease_id' => (int) $row['lease_id'],
                    'lease_reference' => $row['lease_reference'] ?? null,
                    'tenant_name' => $row['tenant_name'] ?? null,
                    'unit_code' => $row['unit_code'] ?? null,
                    'total' => round((float) ($row['total'] ?? 0), 2),
                ];

                $total += (float) ($row['total'] ?? 0);
            }
        }

        // Recorded BEFORE anything else is done with it, on the lesson `ScanChequeCoverageService`
        // learned the hard way: on a scan whose finding is the absence of a row, the durable record
        // must not be able to be lost to a failure further down.
        if ($rows !== []) {
            OpsLog::warning('billing.unbilled_periods', [
                'count' => count($rows),
                'total' => round($total, 2),
                'months_scanned' => $monthsScanned,
                'as_of' => $thisMonth->toDateString(),
            ]);
        }

        return [
            'months_scanned' => $monthsScanned,
            'gaps' => count($rows),
            'total' => round($total, 2),
            'rows' => $rows,
        ];
    }
}
