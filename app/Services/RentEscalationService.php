<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\RentIndex;
use App\Support\OpsLog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Automatic contractual rent escalation. Leases carry `escalation_rate` / `escalation_type` /
 * `next_escalation_date`, but nothing applied them — so every anniversary increase was a manual
 * `LeaseRentChangeService` call, and a missed one leaked revenue (competitive gap analysis,
 * [docs/gap-analysis/README.md]). This sweep applies the increase through
 * `LeaseRentChangeService` (which keeps the base-rent Charge + marketing levy in lock-step) and
 * rolls `next_escalation_date` forward by the clause's own interval — `escalationIntervalMonths()`,
 * which is twelve unless the lease says otherwise.
 *
 * **What changed 2026-08-08:** applying an escalation no longer OVERWRITES the rent. It closes
 * the current schedule row the day before the anniversary and opens the next one
 * (ChargeScheduleService) — so what the rent was last year is still readable, and a step is dated
 * to the contract's anniversary rather than to whichever night the sweep managed to run.
 *
 * Idempotent + lock-safe: each lease is row-locked and its due-ness re-checked inside the
 * transaction, and applying advances `next_escalation_date` past today so a re-run is a no-op.
 * One step per run — a multi-year backlog (a mis-set date) catches up over subsequent runs rather
 * than compounding many years in a single pass. CPI escalation is skipped (no index feed —
 * inventing a CPI number would be inventing data); `fixed_percent` and `fixed_amount` are applied.
 */
class RentEscalationService
{
    public function __construct(private LeaseRentChangeService $rentChange) {}

    /** @return array{considered:int, applied:int, skipped:int, failed:int} */
    public function runForToday(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now()->startOfDay();
        $stats = ['considered' => 0, 'applied' => 0, 'skipped' => 0, 'failed' => 0];

        $dueIds = Lease::query()
            ->where('status', 'active')
            // A lease whose TERM has run out must not keep escalating. `status` alone was the only
            // filter until 2026-08-19, and nothing moves a lease to `expired` by itself — so a
            // tenancy that ended in January still had its rent stepped in August, writing schedule
            // rows for months it does not cover and putting rent for a dead lease into the rent
            // roll and the 24-month forecast (pre-staging QA, F-04). Invoices were never affected:
            // billing refuses an ended lease with `lease_ended`.
            //
            // Kept SEPARATE from the `leases:expire` sweep on purpose. That sweep fixes the state;
            // this guards against acting on a lease it has not reached yet — a sweep that fails, or
            // has not run since the expiry, must not leave this one escalating.
            //
            // A converted HOLDOVER is deliberately still in scope: its expiry is in the past by
            // design, `holdover_from` is what keeps it billing, and its rent may legitimately step.
            ->where(fn ($q) => $q
                ->whereNull('expiry_date')
                ->orWhereDate('expiry_date', '>=', $today->toDateString())
                ->orWhereNotNull('holdover_from'))
            ->whereIn('escalation_type', ['fixed_percent', 'fixed_amount', 'cpi'])
            ->whereNotNull('next_escalation_date')
            ->whereDate('next_escalation_date', '<=', $today->toDateString())
            ->pluck('id');

        foreach ($dueIds as $id) {
            $stats['considered']++;
            try {
                $stats[$this->applyOne((int) $id, $today)]++;
            } catch (\Throwable $e) {
                // Per-row containment — one bad lease can't stop the sweep (mirrors the SLA scans).
                $stats['failed']++;
                OpsLog::error('rent_escalation.failed', ['lease_id' => $id, 'error' => $e->getMessage()]);
            }
        }

        return $stats;
    }

    /**
     * Clamp an escalation rate to the lease's contractual collar (الحد الأدنى/الأقصى للزيادة).
     *
     * The clause this serves is the standard index-linked one — *"the increase shall be the greater
     * of CPI or 3%, capped at 10%"* — where the floor and the ceiling are what the tenant actually
     * pays in the years the index misbehaves. Applied to whatever rate is about to be used, not only
     * to an index-derived one, which is what makes it bite before CPI exists: on a `fixed_percent`
     * lease the ceiling is a real rail against a mistyped rate. A `70` entered for `7` is a
     * plausible slip that nothing else catches, and it would step the rent seventy percent on the
     * anniversary, unattended, at whatever hour the sweep runs.
     *
     * Each bound is applied only when it is set — a lease with a floor and no ceiling is a lease
     * with a floor and no ceiling, not one capped at zero.
     *
     * Deliberately static + public: the same clamp answers "what WILL this escalate by", which is
     * what the lease screen shows the operator, and a second implementation there is how the preview
     * and the sweep come to disagree.
     */
    public static function collar(Lease $lease, float $rate): float
    {
        $floor = $lease->escalation_floor_rate;
        $ceiling = $lease->escalation_ceiling_rate;

        if ($floor !== null) {
            $rate = max($rate, (float) $floor);
        }

        if ($ceiling !== null) {
            $rate = min($rate, (float) $ceiling);
        }

        return round($rate, 2);
    }

    /**
     * The index figure this lease's next step measures against, or null when it cannot be known.
     *
     * The period is the anniversary month shifted back by the lease's **publication lag** — the
     * September index published in October cannot drive a 1 January step unless the clause says to
     * read three months back, which is exactly what a real index clause states.
     */
    private function indexValueFor(Lease $lease, ?CarbonInterface $anniversary): ?float
    {
        $code = $lease->escalation_index_code;

        if (blank($code) || $anniversary === null) {
            return null;
        }

        $period = CarbonImmutable::parse($anniversary)
            ->startOfMonth()
            ->subMonths((int) $lease->escalation_index_lag_months);

        return RentIndex::valueFor((string) $code, $period);
    }

    /**
     * The percentage the index has moved since this lease's base — BEFORE the collar.
     *
     * Takes the figure rather than fetching it, so the caller can read the register once and use
     * the same number for both the rate and the new base.
     *
     * Null when the clause is incomplete (no index named, no base figure recorded) or the figure
     * for the period has not been published yet. Every one of those is a reason to WAIT, and the
     * caller skips: the sweep runs daily, so the step lands the day the statistic does. That is
     * Voyager's behaviour — it generates the row when the index publishes — and it is the same
     * refusal-to-invent this module has always had, now with somewhere for the real number to live.
     *
     * A base of zero returns null rather than dividing by it: a lease recorded with no base index
     * cannot be escalated against one, and an infinite step is not a better answer than none.
     */
    private function indexRateFrom(Lease $lease, ?float $current): ?float
    {
        $base = $lease->escalation_index_base_value === null
            ? null
            : (float) $lease->escalation_index_base_value;

        if ($base === null || $base <= 0.0 || $current === null) {
            return null;
        }

        return round(($current / $base - 1) * 100, 2);
    }

    /** @return 'applied'|'skipped' */
    private function applyOne(int $leaseId, CarbonImmutable $today): string
    {
        return DB::transaction(function () use ($leaseId, $today) {
            /** @var Lease|null $lease */
            $lease = Lease::whereKey($leaseId)->lockForUpdate()->first();

            // Re-check due-ness under the lock (idempotent + concurrency-safe).
            if (! $lease
                || $lease->status !== 'active'
                || $lease->next_escalation_date === null
                || $lease->next_escalation_date->gt($today)) {
                return 'skipped';
            }

            // The term guard, re-checked under the lock alongside due-ness — the outer query
            // snapshotted this lease as live, and `leases:expire` may have ended it since. Shares
            // `hasExpiredTerm()` with that sweep so the two cannot disagree about what "ended" is.
            if ($lease->hasExpiredTerm($today)) {
                return 'skipped';
            }

            // Read once, as a plain string. `escalation_type` was created as a DB-level
            // `enum('none','fixed_percent','cpi')` in 2024 and static analysis still derives the
            // attribute type from that migration, ignoring the `->change()` that made it a varchar —
            // so comparing the attribute directly against `fixed_amount` reads as "always false".
            $type = (string) $lease->escalation_type;

            if (! in_array($type, ['fixed_percent', 'fixed_amount', 'cpi'], true)) {
                return 'skipped';
            }

            // The clause's own interval, not a literal year (EG-30 / M-6). `escalationIntervalMonths()`
            // floors a null at 12, so a lease that has never been ruled on steps annually exactly as
            // it always did.
            //
            // `addMonthsNoOverflow()`, NOT `addMonths()`: Carbon's default OVERFLOWS a month-end
            // date into the following month, so 31 August + 18 months lands on 2 March rather than
            // the last day of February, and the anniversary a clause names as month-end silently
            // becomes an arbitrary day near the start of the next one. Clamping is the same reading
            // `BillingDay` takes of a month-end billing day, and the only one that keeps a step on
            // the date the contract states. (Written with the overflowing call first; the test that
            // asserts 29 Feb is what caught it.)
            $nextDate = $lease->next_escalation_date->copy()
                ->addMonthsNoOverflow($lease->escalationIntervalMonths());
            $current = (float) $lease->base_rent_monthly;

            // The two kinds differ only in how the step is SIZED. Everything after this — the
            // anniversary dating, the schedule row, the marketing levy resync, the date roll — is
            // one path, so an amount lease can never drift from a percentage one.
            // CPI resolves to a PERCENTAGE and then walks the identical path as a stated one —
            // same collar, same anniversary dating, same schedule row. Null means the figure has
            // not been published yet (or the clause is incomplete), and the answer to that is to
            // wait, never to invent: the sweep runs daily and will pick it up the day it lands,
            // which is Voyager's "it generates the row when the index publishes".
            $indexFigure = null;

            if ($type === 'cpi') {
                // Read ONCE and carry it. The rate and the new base both derive from this single
                // figure, and they are separated by a call into `LeaseRentChangeService` — reading
                // the register a second time down there would let the two disagree if anything in
                // between ever touched `next_escalation_date`. It does not today; a base rolled to
                // a figure the step was not measured from would be silent and would corrupt every
                // step after it, which is too quiet a failure to leave to a call graph staying
                // still.
                $indexFigure = $this->indexValueFor($lease, $lease->next_escalation_date);
                $indexRate = $this->indexRateFrom($lease, $indexFigure);

                if ($indexRate === null) {
                    return 'skipped';
                }
            }

            if ($type === 'fixed_amount') {
                $step = round((float) $lease->escalation_amount, 2);
                $newRent = round($current + $step, 2);
                // The collar is expressed in PERCENT and is not applied here: a floor of "3%" has no
                // meaning against a step stated in pounds, and silently reinterpreting one unit as
                // the other is how a lease gets escalated by something nobody agreed. The form hides
                // the collar for amount leases for the same reason.
                $reason = 'Automatic rent escalation +'.number_format($step, 2).' EGP';
            } else {
                $stated = $type === 'cpi' ? $indexRate : (float) $lease->escalation_rate;
                $rate = self::collar($lease, $stated);
                $step = $rate;
                $newRent = round($current * (1 + $rate / 100), 2);

                // The reason line names the RAW index movement beside the applied rate whenever the
                // collar changed it. A tenant querying a 3% step on a year the index fell needs to
                // see that the floor did that, not a mistake — and the collar is precisely the term
                // that is invisible in the resulting number.
                $reason = $type === 'cpi' && abs($rate - $stated) >= 0.01
                    ? "Automatic rent escalation +{$rate}% (index ".number_format($stated, 2).'%, collared)'
                    : "Automatic rent escalation +{$rate}%";
            }

            if ($step <= 0) {
                // Nothing to escalate; still roll the date so it isn't re-considered every day.
                $lease->forceFill(['next_escalation_date' => $nextDate])->save();

                return 'skipped';
            }

            $this->rentChange->apply($lease, [
                'base_rent_monthly' => $newRent,
                'reason' => $reason,
                // The step takes effect on the ANNIVERSARY, not the night the sweep happens to
                // run. A sweep delayed by a weekend or a failed cron used to silently move the
                // increase; now the schedule row starts where the contract says it starts.
                'effective_from' => $lease->next_escalation_date,
                'origin' => Charge::ORIGIN_ESCALATION,
            ]);

            // Advance by the clause's interval (the base_rent Charge + marketing levy were synced
            // by apply()), and
            // for CPI roll the base index forward to the figure this step measured from — that is
            // what makes the NEXT step year-on-year rather than cumulative-since-commencement.
            // Voyager offers both readings; this codebase already resolves compounding one way
            // ("a percentage step multiplies the current rent"), and two opposite conventions under
            // one word is how an escalation type comes to mean something nobody agreed.
            $roll = ['next_escalation_date' => $nextDate];

            if ($type === 'cpi') {
                $roll['escalation_index_base_value'] = $indexFigure;
            }

            $lease->forceFill($roll)->save();

            return 'applied';
        });
    }
}
