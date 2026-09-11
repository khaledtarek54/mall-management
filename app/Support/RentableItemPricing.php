<?php

namespace App\Support;

use App\Contracts\BillableAgreement;
use App\Models\Lease;
use App\Models\RentableItem;
use App\Services\ChargeScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * What a lease PAYS for the bays, cages and signage faces it holds on a date — each item at its
 * own rate, stepped on the lease anniversary by its own rule (2026-09-12, meeting 2026-09-02
 * point 24: *"the annual increase should be on all expenses … a percentage or a fixed number"*).
 *
 * **The rule lives on the HOLDING, because that is where the rate lives.** Point 24 gave every
 * charge row its own annual increase and left the `parking` row out on purpose: that row is
 * DERIVED — `AssignRentableItemService::rebuildCharge()` re-sums it from the register on every
 * assignment and release, so a rule written on the row was undone by the next bay. In Voyager a
 * rentable item is billed as a recurring lease charge on its own code (benchmark 09 §2) and the
 * escalation schedule sits on that charge (benchmark 01 §4); Atriom's one-row-per-type schedule
 * folds those charges into one `parking` row, so the per-item half of Yardi's shape — the rate
 * this tenant pays for THIS bay — is the `rentable_item_holdings` pivot, and its three escalation
 * columns are that charge's escalation. Same vocabulary and same arithmetic as a charge row:
 * `ChargeEscalation` reads both.
 *
 * **The stepped rate is stored, the way the rent's is.** `monthly_rate` on the holding is the
 * rate IN FORCE; the nightly sweep advances it on the anniversary exactly as it advances
 * `base_rent_monthly`, and the parking row's rungs are the sum's history. Deriving the current
 * rate from the signing figure instead was considered and rejected: a follows-lease bay under an
 * index clause steps by whatever CPI published that year, which no pure function of the base can
 * know a year later, and the rent already answers the same question by storing what was applied.
 *
 * {@see rateOn()} is the one arithmetic — the rate on any date, past anniversaries the sweep has
 * not reached yet included — and every reader takes it: the in-force re-sum on an assignment, the
 * projected ladder's rung for each future anniversary, and the sweep's own step on the night.
 * Three writers reading one function is what keeps a projected parking ladder and a swept one
 * converging rung for rung, the invariant the rent's ladder already holds.
 */
final class RentableItemPricing
{
    /**
     * The items an agreement holds ON a date, pivots loaded — the register's answer to "what does
     * this tenant pay for this month". A holding with no start date is read as held from the
     * beginning, exactly as `RentableItem::isHeldOn()` reads it.
     *
     * @return Collection<int, RentableItem>
     */
    public static function heldOn(BillableAgreement $holder, CarbonImmutable $on): Collection
    {
        $date = $on->toDateString();

        return $holder->rentableItems()
            ->where(fn ($q) => $q->whereNull('rentable_item_holdings.effective_from')
                ->orWhereDate('rentable_item_holdings.effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('rentable_item_holdings.effective_to')
                ->orWhereDate('rentable_item_holdings.effective_to', '>=', $date))
            ->orderBy('rentable_items.code')
            ->get();
    }

    /**
     * Does any holding that is still live carry a rule? The register's half of
     * `Lease::escalatesAnyCharge()`: the sweep selects on it, the pointer is kept while it holds,
     * and the ladder projects for it even when the rent's own clause is `none`. A holding already
     * given back is history and does not count; one released at a FUTURE date still does — it
     * steps on any anniversary before it ends.
     */
    public static function anyRuled(Lease $lease, ?CarbonImmutable $today = null): bool
    {
        if (! $lease->exists) {
            return false;
        }

        $today = ($today ?? CarbonImmutable::today())->toDateString();

        return $lease->rentableItems()
            ->where(fn ($q) => $q->whereNull('rentable_item_holdings.effective_to')
                ->orWhereDate('rentable_item_holdings.effective_to', '>=', $today))
            ->whereNotNull('rentable_item_holdings.escalation_mode')
            ->where('rentable_item_holdings.escalation_mode', '!=', ChargeEscalation::NONE)
            ->exists();
    }

    /**
     * The rate ONE holding bills on a date.
     *
     * `monthly_rate` as it stands, stepped once for every lease anniversary from the sweep's own
     * pointer up to the date — the anniversaries the sweep has not yet applied — and only those
     * whose BILLING MONTH is after the holding's: a bay taken in the anniversary month itself is
     * priced for the year at the rate agreed that day, and steps on the next (every write snaps
     * to the 1st, so "after" is at month granularity — a bay taken on 28 February steps on
     * 1 March). Before the pointer nothing is re-applied, because the pointer is the sweep's
     * statement of what it has already done and `monthly_rate` already carries it (a late
     * sweep's pointer sits in the past by design — the backlog model — and this then reads
     * ahead of it exactly as the sweep will). The corollary is stated rather than hidden: a
     * date BEFORE an anniversary already applied reads the stepped rate, so a change back-dated
     * across one prices the months before it at today's rates — the same limit a back-dated
     * rent change has against a started rung.
     *
     * `$leasePercent` is what a follows-lease holding inherits — the projection's collared stated
     * rate, or the figure the sweep resolved on the night — and null under an index or amount
     * clause, where such a holding stands still (`ChargeEscalation`).
     */
    public static function rateOn(Lease $lease, Model $holding, CarbonImmutable $on, ?float $leasePercent): float
    {
        $rate = round((float) $holding->monthly_rate, 2);

        if ($lease->next_escalation_date === null || ! ChargeEscalation::isStated($holding)) {
            return $rate;
        }

        $step = ChargeEscalation::stepFor($holding, $lease, $leasePercent);

        if ($step === null) {
            return $rate;
        }

        $from = $holding->effective_from !== null
            ? ChargeScheduleService::billingBoundary(CarbonImmutable::parse($holding->effective_from))
            : ($lease->commencement_date !== null
                ? ChargeScheduleService::billingBoundary(CarbonImmutable::instance($lease->commencement_date))
                : null);

        // Walked one anniversary at a time through `escalationDateAfter()` on the RAW date, with
        // the billing boundary taken per step — the same walk the projection and the sweep make,
        // so a month-end anchor does not creep and a biennial clause steps every two years here
        // as it does there.
        $stepDate = CarbonImmutable::instance($lease->next_escalation_date);

        while (true) {
            $effective = ChargeScheduleService::billingBoundary($stepDate);

            if ($effective->greaterThan($on)) {
                break;
            }

            if ($from === null || $effective->greaterThan($from)) {
                $rate = ChargeEscalation::apply($rate, $step);
            }

            $stepDate = $lease->escalationDateAfter($stepDate);
        }

        return $rate;
    }

    /**
     * What the agreement pays for everything it holds on a date — the figure the one `parking`
     * charge row carries for that month. A lease's items each step by their rule; an ownership
     * has no anniversary (a صيانة assessment is re-priced by the annual reconciliation, never by
     * an escalation), so its items bill at their rate as it stands.
     */
    public static function sumOn(BillableAgreement $holder, CarbonImmutable $on, ?float $leasePercent = null): float
    {
        $items = self::heldOn($holder, $on);

        $total = $holder instanceof Lease
            ? $items->sum(fn (RentableItem $item) => self::rateOn($holder, $item->getRelationValue('pivot'), $on, $leasePercent))
            : $items->sum(fn (RentableItem $item) => (float) $item->getRelationValue('pivot')->monthly_rate);

        return round((float) $total, 2);
    }

    /**
     * The register's rules in words, one item after another — what the lease form's "Which
     * charges step" table shows against the parking row, so the form and the Rentable items tab
     * cannot tell the operator two different things. Empty when nothing is held.
     */
    public static function describeHoldings(Lease $lease, ?CarbonImmutable $on = null): string
    {
        $on ??= CarbonImmutable::today();

        return self::heldOn($lease, $on)
            ->map(fn (RentableItem $item): string => $item->code.' — '.ChargeEscalation::describe($item->getRelationValue('pivot'), $lease))
            ->implode(' · ');
    }
}
