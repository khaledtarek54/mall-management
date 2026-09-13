<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\RentIndex;
use App\Support\ChargeEscalation;
use App\Support\OpsLog;
use App\Support\RentableItemPricing;
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
 * than compounding many years in a single pass.
 *
 * **All three clause types are applied**: `fixed_percent`, `fixed_amount` and — since the
 * `rent_indices` register arrived on 2026-08-19 — `cpi`, which resolves the named index
 * `escalation_index_lag_months` back from the anniversary, measures it against the lease's
 * `escalation_index_base_value` and collars the result. *(This docblock said "CPI escalation is
 * skipped (no index feed)" until 2026-08-31, describing the world before that register: the
 * sentence outlived its truth by twelve days and was contradicted by the `'cpi'` branch below it.)*
 * What is still absent is an automatic FEED — a published statistic is keyed by a person, because
 * inventing an index number is inventing the money a tenant pays. A clause naming no index, or
 * carrying no base, produces no step rather than a guess.
 *
 * **Every charge steps by ITS OWN rule** (meeting 2026-09-02, point 24 — Yardi's per-charge
 * grain, replacing the 2026-09-05 service-charge toggle). Each recurring row carries a mode
 * (`ChargeEscalation`): FOLLOWS the lease's clause — the same collared percentage on the same
 * anniversary, which for the service charge rides in the SAME `LeaseRentChangeService` call as the
 * rent so one lease event records both figures — or its OWN percentage or amount, stepped here
 * through `ChargeScheduleService::setAmount()` with an event of its own, or nothing. The step is
 * SIZED FROM THE SCHEDULE (the rung billing into the anniversary), never from a lease column —
 * the schedule tab can end or restate a service charge without touching `service_charge_monthly`,
 * and only `base_rent` is barred from the tab — and a charge with no rung live on the anniversary
 * is skipped rather than resurrected. A CAM re-estimate is never stepped: the annual true-up
 * re-prices it, and escalating an estimate the reconciliation corrects would double-adjust it.
 * A lease whose own clause is `none` is still swept for the charges that carry one.
 */
class RentEscalationService
{
    public function __construct(
        private LeaseRentChangeService $rentChange,
        private ChargeScheduleService $schedule,
    ) {}

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
            // The rent's own clause, OR a charge row carrying a rule of its own, OR a held item
            // carrying one on its holding — a lease whose rent never steps is still swept for the
            // bay that does (point 24; the register's half since 2026-09-12). The SAME three
            // predicates `Lease::escalatesAnyCharge()` reads, so the sweep cannot select a lease
            // the pointer was cleared on, or miss one it was kept for.
            ->where(fn ($q) => $q
                ->whereIn('escalation_type', ['fixed_percent', 'fixed_amount', 'cpi'])
                ->orWhereHas('charges', fn ($c) => $c
                    ->where('is_active', true)
                    ->where('frequency', '!=', 'one_time')
                    ->whereNotIn('type', ChargeEscalation::DERIVED_TYPES)
                    ->whereNotNull('escalation_mode')
                    ->where('escalation_mode', '!=', ChargeEscalation::NONE))
                ->orWhereHas('rentableItems', fn ($i) => $i
                    ->where(fn ($h) => $h->whereNull('rentable_item_holdings.effective_to')
                        ->orWhereDate('rentable_item_holdings.effective_to', '>=', $today->toDateString()))
                    ->whereNotNull('rentable_item_holdings.escalation_mode')
                    ->where('rentable_item_holdings.escalation_mode', '!=', ChargeEscalation::NONE)))
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
        return self::collarWith($lease->escalation_floor_rate, $lease->escalation_ceiling_rate, $rate);
    }

    /**
     * The same clamp over explicit bounds — for a caller holding the bounds a lease USED to carry
     * (`Lease::updated` asks whether a collar edit moved the collared rate before it re-trues).
     */
    public static function collarWith(float|string|null $floor, float|string|null $ceiling, float $rate): float
    {
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
            $rentClause = in_array($type, ['fixed_percent', 'fixed_amount', 'cpi'], true);

            // The clause's own interval, not a literal year (EG-30 / M-6). `escalationIntervalMonths()`
            // floors a null at 12, so a lease that has never been ruled on steps annually exactly as
            // it always did.
            //
            // `escalationDateAfter()` rather than date arithmetic here, because THREE places now
            // need this answer — this sweep, the hook that arms the first date, and
            // `ChargeScheduleService`, which projects the whole ladder — and three disagreeing is
            // how a projected rent ladder comes to differ from the rent actually billed. It also
            // holds the anchor day, without which a month-end anniversary walks backwards a few
            // days at every step.
            $nextDate = $lease->escalationDateAfter(
                CarbonImmutable::instance($lease->next_escalation_date)
            );
            // The anniversary ITSELF, since 2026-09-13 — not the 1st of its month. Every rung
            // this sweep opens starts on the day the clause names, and the planner bills the
            // days before it at the outgoing figure and the days from it at the new one
            // (Trello gzwI17R0: a lease commencing on the 10th stepped from the 1st, nine days
            // early, at every anniversary). `ChargeScheduleService::billingBoundary()` records
            // why the snap that stood here was the wrong grain for a step.
            $anniversary = CarbonImmutable::instance($lease->next_escalation_date)->startOfDay();
            $current = (float) $lease->base_rent_monthly;

            // ── THE RENT — the lease's own clause ─────────────────────────────────────────────
            //
            // The two kinds differ only in how the step is SIZED. Everything after this — the
            // anniversary dating, the schedule row, the marketing levy resync, the date roll — is
            // one path, so an amount lease can never drift from a percentage one.
            // CPI resolves to a PERCENTAGE and then walks the identical path as a stated one —
            // same collar, same anniversary dating, same schedule row. Null means the figure has
            // not been published yet (or the clause is incomplete), and the answer to that is to
            // wait, never to invent: the sweep runs daily and will pick it up the day it lands,
            // which is Voyager's "it generates the row when the index publishes". The WHOLE lease
            // waits with it — a charge on its own percentage too, deliberately: one anniversary,
            // one sweep, and a lease whose rent is unknowable on the day is not half-stepped.
            $indexFigure = null;
            $step = 0.0;
            $newRent = $current;
            $narrative = null;
            $narrativeData = [];
            $collared = false;

            // What a FOLLOWS-LEASE row inherits: the collared percentage the rent steps by. Null
            // under an amount clause (a step in pounds is a statement about the rent) or none.
            $leasePercent = null;

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
                $narrative = 'rent_escalated_amount';
                $narrativeData = ['step_amount' => $step];
            } elseif ($rentClause) {
                $stated = $type === 'cpi' ? $indexRate : (float) $lease->escalation_rate;
                $rate = self::collar($lease, $stated);
                $step = $rate;
                $leasePercent = $rate;
                $newRent = round($current * (1 + $rate / 100), 2);

                // The narrative names the RAW index movement beside the applied rate whenever the
                // collar changed it. A tenant querying a 3% step on a year the index fell needs to
                // see that the floor did that, not a mistake — and the collar is precisely the term
                // that is invisible in the resulting number.
                $collared = $type === 'cpi' && abs($rate - $stated) >= 0.01;
                $narrative = $collared ? 'rent_escalated_collared' : 'rent_escalated';
                $narrativeData = array_filter([
                    'step_pct' => $rate,
                    'index_pct' => $collared ? number_format($stated, 2) : null,
                ], fn ($v) => $v !== null);
            }

            $rentSteps = $rentClause && $step > 0;

            // ── EVERY OTHER CHARGE — its own rule against the clause (point 24) ───────────────
            //
            // The base comes from the SCHEDULE, never a lease column. `base_rent` is barred from
            // the schedule tab precisely so its column cannot drift; `service_charge` is NOT — the
            // tab can end or restate it without touching `service_charge_monthly` — so a
            // column-sized step would resurrect an ENDED charge (`setAmount` finds no active row
            // and mints an open-ended one dated to the COMMENCEMENT) or cut a tab-restated amount
            // back to a stale figure, both by an unattended nightly job. Two covering reads, both
            // load-bearing:
            //
            //  - the row covering the EVE of the anniversary is the outgoing rung, the base the
            //    step is sized from and the rung whose RULE governs — on a projected lease the
            //    anniversary itself is covered by the NEW rung, and sizing from that would step
            //    the step;
            //  - a row covering the anniversary itself proves the charge is still live to bill
            //    the stepped amount. A charge bounded to end at the boundary — or a future-dated
            //    stop's active-with-past-end residue — must produce NO step: `setAmount` would
            //    fall back to the ended row, inherit its past end date and build an inverted
            //    range, whose refusal rolls back the RENT step beside it and repeats every night.
            //
            // A CAM RE-ESTIMATE IS NEVER STEPPED — the annual true-up re-prices it, so escalating
            // it double-adjusts. Both rungs are asked: an estimate as the OUTGOING rung must not
            // be the base of a step, and an estimate taking over ON the anniversary owns that
            // date — `setAmount` would amend it in place with the escalated figure, silently
            // overwriting the reconciliation's own answer.
            /** @var array<string, array{from: float, to: float, rule: array, follows: bool}> $chargeSteps */
            $chargeSteps = [];

            foreach ($this->schedule->escalatingChargeTypes($lease) as $chargeType) {
                $outgoing = $this->schedule->rowCovering($lease, $chargeType, $anniversary->subDay());
                $incoming = $this->schedule->rowCovering($lease, $chargeType, $anniversary);

                // A rung snapped to the 1st under the pre-2026-09-13 rule and already started IS
                // this anniversary's step — sizing from it would step twice. The base is the row
                // it succeeded, exactly as the snapped sweep read it; the write below then finds
                // the step already in force and no-ops, while the event and the column still
                // record it (`ChargeScheduleService::stepAlreadyAppliedOn()`).
                if ($this->schedule->stepAlreadyAppliedOn($lease, $chargeType, $outgoing, $anniversary)) {
                    $outgoing = $this->schedule->rowCovering($lease, $chargeType, CarbonImmutable::instance($outgoing->start_date)->subDay());
                }

                if ($outgoing === null
                    || (float) $outgoing->amount <= 0
                    || $outgoing->origin === Charge::ORIGIN_CAM_ESTIMATE
                    || $incoming === null
                    || $incoming->origin === Charge::ORIGIN_CAM_ESTIMATE) {
                    continue;
                }

                $rule = ChargeEscalation::stepFor($outgoing, $lease, $leasePercent);

                if ($rule === null) {
                    continue;
                }

                // A RELIEF row is never a base and never a target (found by review — the sweep
                // stepped a flat concession and wrote the stepped figure INTO the window). The
                // contract still steps: the base is the contracted rung the concession was granted
                // against, and where the anniversary itself sits inside the window no rung is
                // written — the projection has already re-priced the rung that resumes after it —
                // while the column and the timeline still record the contractual step.
                $contracted = $outgoing->origin === Charge::ORIGIN_RELIEF
                    ? $this->schedule->contractedRowBefore($lease, $chargeType, $anniversary->subDay())
                    : $outgoing;

                if ($contracted === null || (float) $contracted->amount <= 0) {
                    continue;
                }

                $from = (float) $contracted->amount;
                $to = ChargeEscalation::apply($from, $rule);

                if (abs($to - $from) < 0.005) {
                    continue;
                }

                $chargeSteps[$chargeType] = [
                    'from' => $from,
                    'to' => $to,
                    'rule' => $rule,
                    'follows' => ChargeEscalation::modeOf($outgoing) === ChargeEscalation::FOLLOWS_LEASE,
                    'relieved' => $incoming->origin === Charge::ORIGIN_RELIEF,
                ];
            }

            // ── THE REGISTER — each held item by its own rule (2026-09-12) ─────────────────────
            //
            // A bay's rule lives on its HOLDING, and what the lease pays is the sum of what each
            // item bills on the day. `RentableItemPricing::rateOn()` prices the anniversary from
            // the pointer, which still sits on it here, so it applies exactly this step and no
            // other; the new rate is then STORED on the holding — the rent's own discipline, and
            // the reason a follows-lease bay under an index clause can be re-summed a year later.
            // Sized and written like every other charge: the outgoing rung is the base, an
            // anniversary inside a relief window records the step and writes no rung.
            $itemSteps = [];

            if (RentableItemPricing::anyRuled($lease, $today)) {
                foreach (RentableItemPricing::heldOn($lease, $anniversary) as $item) {
                    $holding = $item->getRelationValue('pivot');
                    $was = round((float) $holding->monthly_rate, 2);
                    $now = RentableItemPricing::rateOn($lease, $holding, $anniversary, $leasePercent);

                    if (abs($now - $was) >= 0.005) {
                        $itemSteps[] = ['id' => $holding->id, 'code' => $item->code, 'from' => $was, 'to' => $now];
                    }
                }
            }

            if ($itemSteps !== []) {
                $outgoing = $this->schedule->rowCovering($lease, 'parking', $anniversary->subDay());
                $incoming = $this->schedule->rowCovering($lease, 'parking', $anniversary);

                // The sentence states what STEPPED: the sum of the held items before and after,
                // never the eve row's figure — a bay let in the anniversary month sits in the
                // new sum without having moved, and reading the row made "500 to 850" of a 50
                // step (found by review).
                $before = round((float) RentableItemPricing::heldOn($lease, $anniversary)
                    ->sum(fn ($item) => (float) $item->getRelationValue('pivot')->monthly_rate), 2);

                // A parking row bills into the anniversary whenever anything is held — the relay
                // lays one for every held month — so the guard is for a register out of step with
                // its schedule, not a case a door produces. The rates move either way: they are
                // the register's truth, and the next rebuild sums from them.
                if ($outgoing !== null && $incoming !== null) {
                    $chargeSteps['parking'] = [
                        'from' => $before,
                        'to' => RentableItemPricing::sumOn($lease, $anniversary, $leasePercent),
                        'rule' => ['items' => collect($itemSteps)->pluck('code')->implode(', ')],
                        'follows' => false,
                        'relieved' => $incoming->origin === Charge::ORIGIN_RELIEF,
                    ];
                }

                foreach ($itemSteps as $itemStep) {
                    DB::table('rentable_item_holdings')->where('id', $itemStep['id'])->update(['monthly_rate' => $itemStep['to']]);
                }
            }

            if (! $rentSteps && $chargeSteps === []) {
                // Nothing to escalate; still roll the date so it isn't re-considered every day.
                $lease->forceFill(['next_escalation_date' => $nextDate])->save();

                return 'skipped';
            }

            $roll = ['next_escalation_date' => $nextDate];

            if ($rentSteps) {
                $change = [
                    'base_rent_monthly' => $newRent,
                    // No prose: this sweep runs unattended, so there is no reader whose language it
                    // could compose in. The key and its figures are stored and read back in whichever
                    // language the person looking at the history is using.
                    'narrative' => $narrative,
                    'narrative_data' => $narrativeData,
                    // The step takes effect on the ANNIVERSARY, not the night the sweep happens to
                    // run. A sweep delayed by a weekend or a failed cron used to silently move the
                    // increase; now the schedule row starts where the contract says it starts.
                    'effective_from' => $lease->next_escalation_date,
                    'origin' => Charge::ORIGIN_ESCALATION,
                ];

                // A service charge that FOLLOWS the clause rides in the rent's own call — the same
                // percentage, one transaction, one lease event naming both figures. One with a
                // rule of its own is stepped below like any other charge, under its own event,
                // because the `_with_service` sentence states ONE percentage for both. Added only
                // when it steps — `apply()` reads a present key as an instruction, and a null
                // there means "no service update", so omitting is the unambiguous way to leave
                // the service charge alone.
                if (($chargeSteps['service_charge']['follows'] ?? false) === true
                    && ! $chargeSteps['service_charge']['relieved']) {
                    $change['service_charge_monthly'] = $chargeSteps['service_charge']['to'];
                    // The timeline must say BOTH figures moved — the `_with_service` narratives
                    // exist because rendering a rent-only sentence over a two-charge step would
                    // under-tell the one place an operator audits what the sweep did. FULL key
                    // literals, not a suffix appended to `$narrative`: the vocabulary gate proves
                    // every key has a writer by finding the quoted key in `app/`, and a
                    // concatenation is a writer it cannot see.
                    $change['narrative'] = $collared ? 'rent_escalated_collared_with_service' : 'rent_escalated_with_service';
                    $change['narrative_data']['service_amount_from'] = $chargeSteps['service_charge']['from'];
                    $change['narrative_data']['service_amount_to'] = $chargeSteps['service_charge']['to'];
                    unset($chargeSteps['service_charge']);
                }

                $this->rentChange->apply($lease, $change);
            }

            // Each remaining charge steps on its own rung, under its own event — the same
            // close-and-open discipline, the same anniversary, its own sentence naming the charge.
            foreach ($chargeSteps as $chargeType => $chargeStep) {
                $opened = $chargeStep['relieved']
                    ? null
                    : $this->schedule->setAmount($lease, $chargeType, $chargeStep['to'], $anniversary, [], Charge::ORIGIN_ESCALATION);

                // The lease column tracks the charge in force, as `apply()` keeps it for the
                // rent — every widget and form reads it.
                if ($chargeType === 'service_charge') {
                    $roll['service_charge_monthly'] = $chargeStep['to'];
                }

                // Three sentences, chosen by the rule's shape: a percentage, an amount, or — for
                // the register — the items that stepped, each by its own rule, so one sentence
                // does not state one figure over bays that moved by different ones.
                [$narrativeKey, $narrativeData] = match (true) {
                    isset($chargeStep['rule']['items']) => ['charge_escalated_items', ['items' => $chargeStep['rule']['items']]],
                    isset($chargeStep['rule']['amount']) => ['charge_escalated_amount', ['step_amount' => $chargeStep['rule']['amount']]],
                    default => ['charge_escalated', ['step_pct' => $chargeStep['rule']['percent']]],
                };

                app(RecordLeaseEventService::class)->record(
                    $lease,
                    LeaseEvent::TYPE_RENT_MODIFICATION,
                    $anniversary,
                    null,
                    RecordLeaseEventService::scheduleChangePayload(
                        $chargeType,
                        $chargeStep['from'],
                        $chargeStep['to'],
                        [$opened],
                        $narrativeKey,
                        $narrativeData,
                    ),
                );
            }

            // Advance by the clause's interval (the base_rent Charge + marketing levy were synced
            // by apply()), and
            // for CPI roll the base index forward to the figure this step measured from — that is
            // what makes the NEXT step year-on-year rather than cumulative-since-commencement.
            // Voyager offers both readings; this codebase already resolves compounding one way
            // ("a percentage step multiplies the current rent"), and two opposite conventions under
            // one word is how an escalation type comes to mean something nobody agreed.
            if ($type === 'cpi') {
                $roll['escalation_index_base_value'] = $indexFigure;
            }

            $lease->forceFill($roll)->save();

            return 'applied';
        });
    }
}
