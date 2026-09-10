<?php

namespace App\Models\Concerns\Lease;

use App\Models\LeaseEvent;
use App\Models\Unit;
use App\Support\ProjectedState;
// Imported for the `@param Builder` docblocks below: without it they resolve to
// App\Models\Concerns\Lease\Builder, a class that does not exist — a type annotation naming
// nothing, which is the namespace-rebinding trap in its harmless form.
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * **Where a lease is in its term: status, expiry, holdover, and whether it bills.**
 *
 * These are one concern rather than two because FOUR members are hand-maintained readings of the
 * same `holdover_from` predicate, and the file's own note calls the SQL/PHP pair "kept in lockstep
 * by hand":
 *
 *   isHoldover()                 holdover_from is set and the lease runs on
 *   isConvertedHoldover()        the same state, after conversion
 *   isBillableHoldoverFor()      the PHP half of the billing decision
 *   scopeBillableForPeriod()     the SQL half — the whereNotNull('holdover_from') branch
 *
 * Splitting lifecycle from billing-eligibility, as this refactor's plan originally proposed, would
 * have put the two halves of that hand-synchronised pair in different files. That is precisely the
 * drift the original bug came from, so they stay together and the list above exists so the next
 * person changing one can see the other three.
 *
 * **What stays on the model:** the status / expiry_date / holdover_from columns and their casts,
 * and the terminal-immutability `updating` hook in `booted()` — which reads `TERMINAL_STATUSES`
 * from here (a trait constant is a class constant once composed).
 */
trait HasLeaseTermState
{
    /**
     * A signed lease HOLDS its shops — whether or not the tenant has moved in yet.
     *
     * The double-booking question, and the reason it is a named list rather than a literal: adding
     * `future` to the vocabulary meant every `where('status', 'active')` guard silently stopped
     * seeing a whole population, and `Unit::isActivelyLeased()`'s own docblock had ALREADY argued
     * the case in writing — *"a future-dated expansion has already spoken for the unit even though
     * nobody occupies it yet, and letting a second lease take it in the gap is exactly the
     * double-booking this guard exists to stop"* — using the word for a state the query then
     * excluded. Measured before this list existed: two leases, thirteen months of overlap, one
     * shop, both billing, and the unit reading `reserved` the whole time.
     *
     * `pending_approval` is deliberately NOT here. This is the set that BLOCKS a re-let, and a deal
     * still awaiting approval must not stop the mall letting the shop to somebody who signs first —
     * which is the behaviour every one of these guards had before `future` existed and is not a
     * decision this change is entitled to reopen.
     */
    public const HOLDS_PREMISES = ['active', 'future'];

    /**
     * A lease open to COMMERCIAL ACTS — change the rent, grant relief, move the premises, extend
     * the term, terminate it, bill the deposit, allocate a bay.
     *
     * Fifteen call sites carried the literal `['active', 'pending_approval']`, and every one of them
     * admits a lease that is merely AWAITING APPROVAL — so refusing one that is signed and dated to
     * open is incoherent: `future` is strictly more committed than `pending_approval`. Billing the
     * security deposit is the clearest case, because it is a PRE-HANDOVER act that had become
     * unreachable until the day the tenancy started.
     */
    public const OPEN_TO_COMMERCIAL_ACTS = ['active', 'pending_approval', 'future'];

    /** Terminal lease states — immutable once reached (CLAUDE.md invariant). */
    public const TERMINAL_STATUSES = ['terminated', 'expired', 'cancelled', 'renewed'];

    /**
     * Which lease statuses may bill a PERIOD — read by both halves of the eligibility pair below.
     *
     * `expired` is deliberately here and deliberately also in `TERMINAL_STATUSES`, because the two
     * lists answer different questions: that one says the lease may no longer be CHANGED, this one
     * says a month its term covered may still be INVOICED. `expired` is a projection of the dates
     * ({@see ProjectedState}) written by `leases:expire` and by nothing else;
     * `terminated`, `cancelled` and `renewed` are decisions with their own settlement behind them.
     *
     * **`future` is here for an ORDERING reason and it is load-bearing.** The billing job runs at
     * 02:00 and `leases:expire` — which moves a commenced lease to `active` — at 05:15, so on the
     * morning a tenancy starts the lease is still `future` when billing asks. Leaving it out would
     * lose the FIRST MONTH of every lease keyed in advance, which is the commonest lease there is.
     * Admitting it says no more than the date clauses already say: `commencement_date` after the
     * period end still bills nothing, so a genuinely not-yet-started lease is refused by date.
     */
    public const BILLABLE_STATUSES = ['active', 'expired', 'future'];

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Executed, and its term has not started yet — Yardi's FUTURE. */
    public function isFuture(): bool
    {
        return $this->status === 'future';
    }

    /**
     * What status an EXECUTED term carries — the ONE definition, and the whole of what `future` is.
     *
     * `active` and `future` are the same commercial fact (this deal is signed) split by a question
     * about TODAY, which is what makes it a {@see ProjectedState} projection rather
     * than a decision: nobody DECLARES a lease future, they declare it executed and the calendar
     * answers the rest. Every door that executes a lease reads this — the wizard, the renewal, the
     * form, the importer — because the alternative is four doors each remembering a date comparison.
     *
     * Why it had to exist: without it a renewal signed in advance, or a deal signed in September to
     * open in December, read `active` from the day it was keyed. Measured on the demo books — a
     * lease commencing in 60 days took its unit to `occupied` and the mall's occupancy from 0% to
     * 10.4%, for a shop nobody was trading from and nobody was paying for.
     */
    public static function executedStatusFor(mixed $commencement): string
    {
        if (blank($commencement)) {
            return 'active';
        }

        return CarbonImmutable::parse($commencement)->startOfDay()->greaterThan(CarbonImmutable::today())
            ? 'future'
            : 'active';
    }

    /**
     * Has the term started? The projector for the `future` half of {@see ProjectedState}'s
     * `lease.term`, and the twin of {@see hasExpiredTerm()} at the other end of the same term.
     */
    public function hasCommenced(): bool
    {
        return self::executedStatusFor($this->commencement_date) === 'active';
    }

    /** Terminal = terminated/expired/cancelled/renewed — the lease is immutable in this state. */
    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isExpiringSoon(int $days = 90): bool
    {
        if (! $this->expiry_date) {
            return false;
        }

        return $this->expiry_date->isBetween(now(), now()->addDays($days));
    }

    /**
     * Holdover = an active lease PAST its end date. It still occupies the unit + projects it as
     * occupied, but the monthly billing engine excludes it (period past expiry) — so a held-over
     * tenant trades rent-free until someone renews or terminates. Surfaced on the ActionRequired
     * dashboard so it can never go silent. (Automatic holdover *billing* is a deferred decision.)
     */
    public function scopeHoldover($query)
    {
        return $query->where('status', 'active')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now()->toDateString());
    }

    /**
     * Holdovers nobody has dealt with yet — the dashboard's question, which is not the same as the
     * table filter's.
     *
     * `holdover()` is a STATE: past expiry, still active, still occupied. That state persists after
     * an operator converts the lease to holdover billing, and the filter should keep showing it.
     * The ActionRequired card asks something narrower — "what still needs a decision" — and a
     * converted holdover has had its decision. Leaving it on the card would train operators to
     * ignore a card that never empties.
     *
     * @param  Builder  $query
     */
    public function scopeHoldoverNeedingAction($query)
    {
        // Deliberately NOT composed from `scopeHoldover()` any more, and that is the fix.
        //
        // `holdover()` requires `status = 'active'`, which really means "the 05:15 `leases:expire`
        // sweep has not reached this lease yet". That sweep's candidate set — active, past expiry,
        // `holdover_from` null — is EXACTLY the holdover-conversion candidate set, so every morning
        // it emptied this card, hid the Convert button and made the service refuse. The whole LE-04
        // workflow was reachable between midnight and 05:15 on the single morning after a term
        // ended, and never again.
        //
        // `expired` is a PROJECTION (`ProjectedState::PROJECTIONS['lease.term']`) — a machine's guess
        // about today — and it is also a member of `TERMINAL_STATUSES`, a decision that closed the
        // record. The two other projections in that registry both carve out a human's statement
        // (`units.status = 'maintenance'`, `rentable_items.status = 'out_of_service'`); this one had
        // no carve-out and no way back. Whether the tenant is still trading is the one fact only a
        // person holds, which `ConvertLeaseToHoldoverService`'s own docblock says in writing.
        //
        // So the decision is outstanding on an `active` lease past its term AND on one the sweep has
        // since projected as `expired` — but never on a tenancy somebody has actually CLOSED, which
        // is derived from the immutable termination event and from the renewal chain rather than
        // from a new column.
        return $query
            ->whereIn('status', ['active', 'expired'])
            ->whereNull('holdover_from')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now()->toDateString())
            ->whereDoesntHave('events', fn ($e) => $e->where('type', LeaseEvent::TYPE_TERMINATION))
            // …and never a shop that has since been RE-LET. The unit went vacant when the sweep
            // projected this lease as expired, so leasing may legitimately have signed a new tenant
            // in the meantime — and converting then would put two active leases on one shop, both
            // billing. Excluded here as well as refused in the service, so the card cannot offer
            // work the service will decline.
            // Through THIS lease's own pivot on both sides, never `leases.unit_id`: an additional
            // unit of a multi-unit lease can be re-let on its own, and a master-only comparison
            // would leave the card offering work the service now refuses — the drift this scope's
            // row twin exists to prevent.
            ->whereNotExists(fn ($q) => $q
                ->selectRaw('1')
                ->from('leases as relet')
                ->join('lease_unit as ru', 'ru.lease_id', '=', 'relet.id')
                ->join('lease_unit as mine', 'mine.unit_id', '=', 'ru.unit_id')
                ->whereColumn('mine.lease_id', 'leases.id')
                // Its row twin is `Unit::isActivelyLeased()`, which reads the same list: a shop
                // re-let to a tenant who has SIGNED but not opened is just as re-let.
                ->whereIn('relet.status', self::HOLDS_PREMISES)
                ->whereColumn('relet.id', '!=', 'leases.id'));
    }

    /**
     * The row half of {@see scopeHoldoverNeedingAction()} — is a holdover decision still outstanding?
     *
     * Kept beside its SQL twin for the reason this file's docblock gives about the other four pairs:
     * a predicate answered one way by the dashboard's query and another way by the button's
     * `visible()` is how a card offers work an operator then cannot do.
     */
    public function awaitsHoldoverDecision(): bool
    {
        return $this->holdover_from === null && $this->isOpenPastItsTerm();
    }

    /**
     * The term has run out and the tenancy is STILL OPEN — nobody closed it, nobody re-let the shop.
     *
     * Extracted from {@see awaitsHoldoverDecision()} on its second and third real call site, because
     * holding over is only ONE of the things an operator may do at the end of a term. The other two
     * — RENEW the lease, and give the tenant another parking bay while that renewal is negotiated —
     * ask exactly this question, and each was answering it with `status === 'active'`, which the
     * 05:15 `leases:expire` sweep falsifies every morning. Three readings of "is this tenancy still
     * running past its end date" is how they come to disagree.
     *
     * "Still open" is DERIVED and never a column: a tenancy somebody really closed carries an
     * immutable termination event, and a shop leasing has since re-let carries a second active
     * lease. Both facts are already recorded, and reading them is what stops this becoming a fourth
     * opinion about what `expired` means.
     *
     * Says nothing about `holdover_from`: a converted holdover is still open past its term, and it
     * is the holdover DECISION — not the renewal, and not a parking bay — that has been taken.
     */
    public function isOpenPastItsTerm(): bool
    {
        return in_array($this->status, ['active', 'expired'], true)
            && $this->expiry_date !== null
            && $this->expiry_date->startOfDay()->lt(now()->startOfDay())
            && ! $this->events()->where('type', LeaseEvent::TYPE_TERMINATION)->exists()
            // …and no shop of this lease has been re-let. See the scope for why: the sweep vacates
            // every unit, so a new tenant may legitimately hold one now, and acting would make two
            // leases active on it.
            && ! $this->anyUnitIsLetToSomebodyElse();
    }

    /**
     * Is ANY unit of this lease already let to somebody else — master or additional?
     *
     * The master pointer is the wrong thing to ask. A lease over two shops is vacated on BOTH when
     * its term ends, and leasing can then sign a new lease with the ADDITIONAL one as its own
     * master — legitimately, because the creation guard asks whether that unit has an ACTIVE lease
     * and this one is `expired`. Reading `leases.unit_id` alone would miss it and let a renewal or
     * a holdover resumption put two active leases on that shop, both billing it, with
     * `Lease::totalAreaSqmForPeriod()` counting it twice in the CAM denominator.
     *
     * `Unit::isActivelyLeased()` already consults the `lease_unit` PIVOT on the other side, so this
     * is the same question asked from both ends. The pivot is maintained by `LeaseObserver` even
     * for single-unit paths; the `unit` fallback is for a row written before it fires.
     */
    public function unitLetToSomebodyElse(): ?Unit
    {
        $units = $this->units()->get();

        if ($units->isEmpty()) {
            $units = collect(array_filter([$this->unit]));
        }

        return $units->first(fn (Unit $unit): bool => $unit->isActivelyLeased($this->id));
    }

    /**
     * The boolean half of {@see unitLetToSomebodyElse()}.
     *
     * It returns the UNIT rather than a flag because the refusal has to name it: told only that
     * "an expired lease cannot be renewed", the operator goes to look at the lease, and the fact
     * they need is on the shop. One definition, so the predicate that refuses and the sentence that
     * explains it can never disagree about which unit is the problem.
     */
    private function anyUnitIsLetToSomebodyElse(): bool
    {
        return $this->unitLetToSomebodyElse() !== null;
    }

    /**
     * May this tenancy be RENEWED?
     *
     * **A renewal signed after the term ended is ordinary commercial practice**, not an exception:
     * the parties negotiate, the tenant trades on, and the document is dated back to the day after
     * the old term so the tenancy has no gap. Yardi renews from the lease whatever its Current/Past
     * status, and MRI and Entrata do the same. Atriom refused it — `leases:expire` writes `expired`
     * at 05:15, `expired` is in {@see TERMINAL_STATUSES}, and both the button and the service asked
     * for `active`. So a renewal was signable on the single morning after a term ended and never
     * again, the third door LE-04 shut and the last one still closed.
     *
     * The only route left was to convert to HOLDOVER first and renew the resumed lease — which
     * prices those months at the holdover uplift (150% by default) that the parties never agreed to.
     * A workaround that bills the tenant a penalty is not a workaround.
     *
     * `terminated`, `cancelled` and `renewed` stay refused, and that is not a gap: each is a
     * decision with its own successor document, and re-renewing a chain that already forked is how
     * a unit ends up with two live tenancies. An `active` lease is unchanged — including one under
     * notice, which stays renewable because notice can be withdrawn and that is a decision the
     * parties may still take.
     */
    public function canBeRenewed(): bool
    {
        // `future` belongs here for the same reason `active` does, and leaving it out was a
        // workflow REMOVED rather than a rule enforced: a signed-not-started lease is not closed,
        // and two consecutive terms agreed up front is an ordinary anchor deal. It surfaced as the
        // renewal CHAIN breaking — renewing a renewal, whose successor is normally future-dated —
        // which is a path this suite has pinned since long before `future` existed.
        // ── ONCE, AND ONLY ONCE ──────────────────────────────────────────────────────────────
        //
        // Nothing ever guarded this explicitly: the only thing stopping a second renewal was
        // `renew()` stamping the original `renewed`, which made the status test below false. The
        // moment that stamp moved to the day the term actually ends — so an early renewal keeps
        // billing — the implicit guard went with it, and a double-clicked Renew button produced
        // TWO successors on one tenancy, each commencing the day after the same expiry, both
        // billing the same shop once the sweep commenced them.
        //
        // A CANCELLED successor does not count. A renewal that fell through must leave the tenancy
        // renewable again, or the operator is left with a lease that can never be continued — the
        // dead end this codebase already records for guards written without an escape.
        if ($this->renewals()->where('status', '!=', 'cancelled')->exists()) {
            return false;
        }

        return $this->status === 'active'
            || $this->status === 'future'
            || ($this->status === 'expired' && $this->isOpenPastItsTerm());
    }

    public function isHoldover(): bool
    {
        return $this->status === 'active'
            && $this->expiry_date !== null
            && $this->expiry_date->startOfDay()->lt(now()->startOfDay());
    }

    public function daysUntilExpiry(): int
    {
        return (int) now()->diffInDays($this->expiry_date, false);
    }

    /**
     * Is this lease eligible to be billed for the given period at all?
     *
     * **One definition, two callers.** The scheduled run filters eligibility in its query
     * (`scopeBillableForPeriod`); the manual "Generate Invoice" action operates on a lease the
     * operator already picked, so it had no query to filter — and therefore applied NONE of these
     * rules. Measured before this existed: the manual path happily created a real AR invoice (which
     * posts to the GL) for a **terminated** lease, a **draft** lease, and a lease **two months past
     * its expiry**, each of which the batch run correctly refused.
     *
     * Keeping the predicate here and the scope below in lockstep is the point: two copies of
     * "which leases bill" is exactly how the two paths drifted apart in the first place.
     */
    public function isBillableForPeriod(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): bool
    {
        // ── STATUS IS A TODAY FACT AND THIS IS A PERIOD QUESTION ──────────────────────────────
        //
        // `leases:expire` (05:15 nightly) projects every active lease past its term to `expired`,
        // so the morning after a term ends the lease drops out of BOTH halves of this pair — for
        // every period, including the months it was running through. Traced on HEAD by reading
        // the pair, not by running it — the regression test is what measures it: a lease
        // commencing 2025-01-01 and expiring 2026-08-31, swept on 1 September, answers false here
        // for AUGUST, and `billing:run-monthly --period=2026-08` — the documented recovery from a
        // failed billing night — reports it in the ordinary `skipped` counter. The final month of
        // every tenancy that ended between the failed run and the re-run is never invoiced, and
        // nothing anywhere says so.
        //
        // Admitting `expired` says no more than the date clauses below already say: it is written
        // by the sweep alone and only for a term that has actually run out.
        // `terminated`/`cancelled`/`renewed` are DECISIONS — a terminated lease has had its final
        // account and its unearned credit note — and stay refused, which is what
        // `ManualBillingEligibilityTest` pins.
        //
        // This widens WHICH LEASES are asked, never WHICH MONTHS bill: the clauses below still
        // require the period to fall inside the term, and `generateInvoiceForLease()` clips the
        // trailing edge to `expiry_date`, so an expired lease can only ever be billed the part of
        // its last month that it actually ran.
        if (! in_array($this->status, self::BILLABLE_STATUSES, true)) {
            return false;
        }

        // Not yet started: a lease commencing after the period ends bills nothing.
        // blank(), not `=== null`: the column is NOT NULL today, so an explicit null comparison
        // reads as always-true to static analysis — while still being the behaviour we want if the
        // column is ever relaxed.
        if (blank($this->commencement_date)
            || CarbonImmutable::instance($this->commencement_date)->greaterThan($periodEnd)) {
            return false;
        }

        // Already over: an open-ended lease (null expiry) never expires. A lease whose expiry falls
        // before the period starts is finished and bills nothing.
        //
        // …UNLESS an operator has converted it to holdover (story LE-04). Then the parties have
        // continued past expiry, the tenant is in the space, and the mall bills for it from the
        // conversion date. Before this, a held-over tenant traded rent-free and the only response
        // was a dashboard card.
        if (filled($this->expiry_date)
            && CarbonImmutable::instance($this->expiry_date)->lessThan($periodStart)
            && ! $this->isBillableHoldoverFor($periodEnd)) {
            return false;
        }

        // Only an ACTIVE lease may be open-ended. The branch above tolerates a null expiry — and
        // `leases.expiry_date` is NOT NULL today, so this cannot fire — but a row reading `expired`
        // with no expiry date is not a projection of anything, and would otherwise bill for ever.
        if (blank($this->expiry_date) && $this->status !== 'active') {
            return false;
        }

        return true;
    }

    /** Has this lease been converted to holdover, effective on or before the given period? */
    public function isBillableHoldoverFor(CarbonImmutable $periodEnd): bool
    {
        return filled($this->holdover_from)
            && CarbonImmutable::instance($this->holdover_from)->lessThanOrEqualTo($periodEnd);
    }

    /** Converted to holdover and still running — the state the dashboard should stop nagging about. */
    public function isConvertedHoldover(): bool
    {
        return filled($this->holdover_from);
    }

    /**
     * Has this tenancy's TERM run out — with nobody having renewed, terminated or held it over?
     *
     * Named here because two places have to agree about it and previously each inlined its own
     * copy: `leases:expire`, which moves the lease to `expired`, and `RentEscalationService`, which
     * must not step the rent of a lease the sweep has not reached yet. Two guards protecting
     * different things (the state, and acting on the state), one definition — the project's rule
     * for exactly this shape.
     *
     * A converted HOLDOVER is excluded, and that is the whole subtlety: its expiry is deliberately
     * in the past, `holdover_from` is what makes it billable at all, and treating it as expired
     * would end a tenancy the operator explicitly chose to continue.
     *
     * Says nothing about `status` — this is about the DATES. A lease already `terminated` has an
     * expired term too; whether that matters is the caller's question.
     */
    public function hasExpiredTerm(?CarbonImmutable $on = null): bool
    {
        if (blank($this->expiry_date)) {
            return false;   // open-ended: a term that never ends cannot have ended
        }

        if ($this->isConvertedHoldover()) {
            return false;
        }

        $on ??= CarbonImmutable::now();

        return CarbonImmutable::instance($this->expiry_date)->startOfDay()->lessThan($on->startOfDay());
    }

    /**
     * How many months apart this lease's rent steps are — the ONE definition.
     *
     * `RentEscalationService` rolled the next step with a literal `->addYear()` (EG-30 / M-6), so a
     * biennial clause, an 18-month step, or the six-monthly review that goes into a short fit-out
     * lease could not be automated. What happens instead is that escalation gets switched off and
     * done by hand, which is how a step comes to be missed for a year.
     *
     * **Null means twelve**, and null is the normal state: every existing lease keeps escalating
     * annually and the sweep is behaviour-identical on deploy. The floor lives here rather than in
     * the service for the reason `Lease::hasExpiredTerm()` does — the sweep is not the only thing
     * that will ever need to know when the next step falls (a rent-roll projection and the renewal
     * screen both want it), and two readings of "how often does this rent step" is how a clause
     * comes to mean two things.
     *
     * Clamped rather than refused. The column is `unsignedSmallInteger`, so the database already
     * stops a negative; what it cannot stop is a 0 written by an importer, which would roll the
     * date nowhere and make the sweep reconsider the same lease every day for ever — a silent
     * infinite no-op, not an error anyone would see. One month is the floor because it is the
     * shortest interval that is a real clause.
     */
    public function escalationIntervalMonths(): int
    {
        $months = $this->escalation_interval_months;

        return $months === null ? 12 : max(1, (int) $months);
    }

    /**
     * The step after `$current` — the ONE roll, drift-free.
     *
     * Rolling with a bare `addMonthsNoOverflow($interval)` walks a month-end anniversary BACKWARDS.
     * From 31 August a six-monthly clause clamps to 28 February (right), and the next roll then
     * starts from the 28th and gives 28 August (wrong — the contract says the 31st). Every
     * subsequent step inherits the earlier day, so the anniversary creeps and the tenant is
     * escalated a few days early for the rest of the term.
     *
     * The fix is an ANCHOR DAY: roll from the current date, then put the day back to the one the
     * contract states, clamped to a day the target month actually has. 31 Aug → 28 Feb → 31 Aug.
     * The anchor is the lease's commencement day, because that is what `Lease::creating` arms the
     * first escalation from; with no commencement the current date's own day is the best available
     * statement of intent.
     *
     * Same clamping reading as `BillingDay` takes of a month-end billing day — and the reason it is
     * here rather than in `RentEscalationService` is that three callers now need it: the sweep, the
     * hook that ARMS the first date, and `ChargeScheduleService`, which projects the whole ladder.
     * Those three disagreeing is how a projected rent ladder comes to differ from the rent actually
     * billed.
     */
    public function escalationDateAfter(CarbonImmutable $current): CarbonImmutable
    {
        $next = $current->addMonthsNoOverflow($this->escalationIntervalMonths());

        $anchorDay = $this->commencement_date
            ? (int) CarbonImmutable::instance($this->commencement_date)->day
            : (int) $current->day;

        return $next->day(min($anchorDay, $next->daysInMonth));
    }

    /**
     * The query form of {@see isBillableForPeriod()} — used by the scheduled run.
     *
     * @param  Builder  $query
     */
    public function scopeBillableForPeriod($query, CarbonImmutable $periodStart, CarbonImmutable $periodEnd)
    {
        return $query
            // The query half of the period-vs-today reading — see `isBillableForPeriod()`.
            ->whereIn('status', self::BILLABLE_STATUSES)
            ->where('commencement_date', '<=', $periodEnd)
            ->where(function ($q) use ($periodStart, $periodEnd) {
                // Grouped, and narrowed to `active`: only an active lease may be open-ended, and
                // an ungrouped `whereNull` here would OR its way past the status clause above.
                $q->where(fn ($o) => $o->whereNull('expiry_date')->where('status', 'active'))
                    ->orWhere('expiry_date', '>=', $periodStart)
                    // The query half of the holdover exemption above. Kept in lockstep by hand
                    // because that is what this pair is: two copies of "which leases bill", which
                    // is exactly how the manual and scheduled paths drifted apart before.
                    ->orWhere(fn ($h) => $h->whereNotNull('holdover_from')
                        ->where('holdover_from', '<=', $periodEnd));
            });
    }
}
