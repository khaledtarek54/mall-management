<?php

namespace App\Models\Concerns\Lease;

use Carbon\CarbonImmutable;
// Imported for the `@param Builder` docblocks below: without it they resolve to
// App\Models\Concerns\Lease\Builder, a class that does not exist — a type annotation naming
// nothing, which is the namespace-rebinding trap in its harmless form.
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
    /** Terminal lease states — immutable once reached (CLAUDE.md invariant). */
    public const TERMINAL_STATUSES = ['terminated', 'expired', 'cancelled', 'renewed'];

    public function isActive(): bool
    {
        return $this->status === 'active';
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
        return $this->scopeHoldover($query)->whereNull('holdover_from');
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
        if ($this->status !== 'active') {
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
            ->where('status', 'active')
            ->where('commencement_date', '<=', $periodEnd)
            ->where(function ($q) use ($periodStart, $periodEnd) {
                $q->whereNull('expiry_date')
                    ->orWhere('expiry_date', '>=', $periodStart)
                    // The query half of the holdover exemption above. Kept in lockstep by hand
                    // because that is what this pair is: two copies of "which leases bill", which
                    // is exactly how the manual and scheduled paths drifted apart before.
                    ->orWhere(fn ($h) => $h->whereNotNull('holdover_from')
                        ->where('holdover_from', '<=', $periodEnd));
            });
    }
}
