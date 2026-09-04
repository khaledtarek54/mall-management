<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Lease;
use App\Support\OpsLog;
use App\Support\PostingDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Bill the FINAL period a terminated lease consumed but was never invoiced for (SW-050).
 *
 * One period — the billing cycle the termination falls in — and deliberately not the unbilled
 * backlog. A lease that ran for months with nothing billed still has those months uninvoiced after
 * this, and that is a different problem (nobody ran the billing) with a different answer (the
 * catch-up run). Stated because the document this produces LOOKS like a complete final bill.
 *
 * **The defect this closes moves money OUTBOUND.** A charge billed IN ARREARS is invoiced one cycle
 * behind — September's service charge appears on October's invoice, because September's service is
 * not knowable until October. When a lease ends, no October invoice is ever raised, so the days the
 * tenant genuinely occupied are billed by nothing. And `MoveOutStatementService` computes
 * `net = depositHeld + tenantCredit − openAr` **from existing invoices only**, so an invoice nobody
 * raised is not open AR: the refund cheque is LARGER by exactly the unbilled amount, capped by the
 * deposit. Measured on rent 100,000 in advance + service charge 20,000 in arrears, terminated on the
 * 20th: the tenant owed 13,333.33 and was refunded 300,000.00 where 286,666.67 was right. The tenant
 * has gone; there is no recovery path, and nothing on the statement says anything is missing.
 *
 * **The row's stated cause was wrong and the fix is not where it pointed.** It blamed the blanket
 * `is_active = false` in `LeaseTerminationService`; that line is guarded by the same `$underNotice`
 * as the status write, so it cannot fire without the status also becoming `terminated`, and
 * `HasLeaseTermState::isBillableForPeriod()` refuses on the STATUS before the planner ever reads a
 * charge row. Proven by re-running with `is_active` forced back to true: nothing changed. Widening
 * `BILLABLE_STATUSES` does not reach it either, because termination sets `expiry_date` to the
 * termination date and the planner requires `expiry_date >= periodStart`. The gate cannot be
 * widened into a fix — it needs an act of its own, which is this.
 *
 * **It writes no arithmetic.** `MonthlyBillingService::planInvoiceForLease()` already produces
 * exactly the right answer once `expiry_date` has moved: `$isFinalCycle` becomes true, so an arrears
 * row settles its own period as well as the previous one, and SW-051's `covered_end` clamp drops the
 * rent that was already billed in advance. Proration and the per-row `prorate` flag compose for free
 * for the same reason. A second copy of that arithmetic here would let the final bill disagree with
 * the credit note sitting beside it on the same statement, which is the fork
 * `CreditUnearnedBillingService` exists to prevent.
 *
 * **`invoice_items.covered_end` IS the idempotency stamp** — no new column. The clamp trims an
 * already-covered prefix and yields nothing for a wholly-covered row, so terminating twice,
 * re-terminating on a different date and a catch-up run are one mechanism. Cancelling the final
 * invoice correctly re-opens it, because a cancelled document is excluded from `lastCoveredEndFor`.
 *
 * **A lease whose history predates that column is REFUSED, not guessed.** SW-051 deliberately did
 * not backfill `covered_end`, and null means *not recorded* rather than *nothing covered* — so with
 * no clamp the planner re-raises rent that was already invoiced AND already credited. Measured on
 * the same fixture: an 86,666.67 double-bill, outbound on a document the tenant reads. Refusing
 * loses nothing an operator cannot do by hand; billing it silently cannot be undone.
 */
class BillFinalPeriodService
{
    public function __construct(
        private MonthlyBillingService $billing,
        private IssueInvoiceService $issuer,
    ) {}

    /**
     * @return array{status:'created'|'skipped', reason?:string, invoice:?Invoice}
     */
    public function billFor(Lease $lease, CarbonImmutable $terminationDate): array
    {
        $skip = function (string $reason) use ($lease, $terminationDate): array {
            // A SKIP IS REPORTED, never swallowed. Every reason below leaves a departing tenant's
            // consumed period unbilled, permanently — a terminated lease never reaches
            // `leases:expire` (that sweep filters on `active`), so there is no second chance. An
            // absence with nothing written down is the failure class this codebase keeps citing.
            OpsLog::warning('Final consumed period was not billed at move-out', [
                'lease_id' => $lease->id,
                'termination_date' => $terminationDate->toDateString(),
                'reason' => $reason,
            ]);

            return ['status' => 'skipped', 'reason' => $reason, 'invoice' => null];
        };

        // THE CYCLE the termination falls in, not its calendar month. A quarterly lease is billed
        // only on a cycle start, so passing `startOfMonth()` made the planner answer `off_cycle` for
        // two months in every three — measured on a lease commencing 1 January, terminating 20
        // November: no document, and Oct 1 – Nov 20 of arrears billed by nothing. That is the
        // "quarterly lease terminating mid-quarter" case `LeaseTerminationService` already cites as
        // a reproduced real defect.
        $periodStart = $this->cycleStartFor($lease, $terminationDate);

        if ($periodStart === null) {
            return $skip('no_billing_cycle');
        }

        // Serialised on the SAME lock the scheduled and manual runs take. `generateForLease()` says
        // why in writing: idempotency here is a check-then-act with no unique key, so a termination
        // racing a catch-up run for that month would read `lastCoveredEndFor` before the run's
        // invoice lands, find no clamp, and raise the period twice.
        $result = Cache::lock('billing:run:'.$periodStart->format('Y-m'), 900)
            ->get(fn () => $this->billUnderLock($lease, $periodStart, $terminationDate, $skip));

        return $result === false ? $skip('run_in_progress') : $result;
    }

    /** @param  callable(string): array{status:string, reason:string, invoice:null}  $skip */
    private function billUnderLock(Lease $lease, CarbonImmutable $periodStart, CarbonImmutable $terminationDate, callable $skip): array
    {
        // `forceFinalCycle`: the tenancy ends HERE. Without it a converted holdover answers false
        // (its expiry is deliberately in the past) and an arrears row covers only the previous
        // month — and termination is a holdover's only door, since `leases:expire` excludes them.
        $plan = $this->billing->planInvoiceForLease($lease, $periodStart, $terminationDate, false, true);

        if (! ($plan['billable'] ?? false)) {
            return $skip($plan['reason'] ?? 'not_billable');
        }

        $items = array_values($plan['items'] ?? []);

        if ($items === []) {
            return $skip('nothing_left_to_bill');
        }

        // The line-level period record is what makes the plan safe — see the class docblock. Asked
        // ONLY of the charge rows this plan is about to bill, which is the scoping
        // `MonthlyBillingService::lastCoveredEndFor()` already uses.
        //
        // Scoping is not a refinement, it is the difference between working and inert: `covered_end`
        // is written by the recurring run alone, and every one-off raiser — the late fee, the
        // deposit bill, the violation fine, the utility recharge, the CAM recovery, the percentage-
        // rent overage, the bounced-cheque fee — issues against the LEASE with no `covered_*` at
        // all. An unscoped probe therefore refused any lease that had ever carried ONE of those,
        // with a fully-stamped recurring history beside it, and it refused hardest on exactly the
        // leases this exists for: a tenant with late fees is the tenant whose deposit you are
        // netting against.
        $chargeIds = array_values(array_filter(array_map(
            fn (array $item) => $item['charge_id'] ?? null,
            $items,
        )));

        if ($this->historyPredatesLinePeriods($lease, $chargeIds)) {
            return $skip('line_periods_not_recorded');
        }

        // A closed period must not make TERMINATION impossible. `Invoice` guards `issue_date` through
        // `GuardsPostingDate` and that guard THROWS — inside `terminate()`'s transaction it would
        // abort the whole termination, and inside `leases:expire` it would abort the whole sweep,
        // for a reason the operator cannot act on and that has nothing to do with the act.
        //
        // **A stated deviation.** `PostingDate`'s docblock says to refuse rather than re-date, and
        // `CreditUnearnedBillingService` — this bill's own mirror — does refuse. The difference is
        // that a credit note is optional relief the operator can re-take later, while this is the
        // last chance to ask for money that otherwise leaves as a larger refund. So it posts
        // forward, and if TODAY is closed too it SKIPS loudly rather than throwing: both months
        // closed is reachable (`closeFiscalYear()` closes every period in the year, the current one
        // included), and taking a termination down with it is the worse outcome.
        $issueDate = PostingDate::isClosed($terminationDate->toDateString())
            ? CarbonImmutable::today()
            : $terminationDate;

        if (PostingDate::isClosed($issueDate->toDateString())) {
            return $skip('no_open_period');
        }

        $invoice = $this->issuer->issue($lease, $items, $issueDate, $periodStart, $terminationDate);

        // The last document a departing tenant is charged, and the one netted off their deposit.
        // The recurring run notifies every invoice it raises; this reached them by no channel.
        $this->billing->notifyInvoiceIssued($invoice);

        return ['status' => 'created', 'invoice' => $invoice];
    }

    /**
     * The start of the billing CYCLE containing a date — the month itself for a monthly lease, and
     * the quarter/half/year start for anything longer. Null when the lease has no billable month
     * yet, which the planner would answer `off_cycle` for anyway.
     */
    private function cycleStartFor(Lease $lease, CarbonImmutable $on): ?CarbonImmutable
    {
        $month = $on->startOfMonth();

        if ($lease->billingCycleMonths() <= 1) {
            return $month;
        }

        // Walk back at most one cycle: exactly one month in any cycle is its start.
        for ($i = 0; $i < $lease->billingCycleMonths(); $i++) {
            $candidate = $month->subMonths($i);

            if ($lease->isBillingCycleStart($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Does this lease carry billed lines, FOR THE CHARGES ABOUT TO BE BILLED, from before
     * `invoice_items.covered_end` existed?
     *
     * Scoped by `charge_id` exactly as `MonthlyBillingService::lastCoveredEndFor()` is — see
     * `billUnderLock()` for why an unscoped probe made this whole service inert. A one-off line
     * carries no `charge_id` at all and is correctly invisible to it.
     *
     * The status partition matches `lastCoveredEndFor()`'s, so the query that TRIGGERS the refusal
     * and the query that supplies the CLAMP draw the same line: a fully-credited invoice must not
     * cause a refusal while contributing no coverage.
     *
     * @param  array<int, int>  $chargeIds
     */
    private function historyPredatesLinePeriods(Lease $lease, array $chargeIds): bool
    {
        if ($chargeIds === []) {
            return false;
        }

        return Invoice::query()
            ->where('lease_id', $lease->id)
            ->whereNotIn('status', ['draft', 'cancelled', 'credited'])
            ->whereHas('items', fn ($q) => $q->whereIn('charge_id', $chargeIds)->whereNull('covered_end'))
            ->exists();
    }
}
