<?php

namespace App\Services;

use App\Contracts\BillableAgreement;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\RentableItem;
use App\Models\UnitOwnership;
use App\Support\ChargeEscalation;
use App\Support\RentableItemOptions;
use App\Support\RentableItemPricing;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Let a parking bay, store or signage face to an AGREEMENT — and bill it (space model, story 09).
 *
 * **The holder is the agreement, not the lease.** That is Voyager's own model rather than an
 * extension of it: rentable items are assigned to the customer RECORD
 * (`docs/benchmarks/yardi/09-yardi-space-and-parking.md` §2 — "assign Rentable Items … to both new
 * and existing residents"), and in Voyager Condo/Co-Op the unit OWNER simply is that record. So an
 * owner-occupier who bought his shop can hold a bay, and its charge rides his monthly صيانة
 * assessment exactly as a tenant's rides the lease schedule (operator's decision, 2026-08-19).
 * Atriom had narrowed "customer record" to "lease" only because, when rentable items were built, a
 * lease was the only agreement that existed.
 *
 * **It writes an ordinary charge row.** The assignment itself moves no money; what bills is a
 * `parking` charge on the lease's schedule, which the monthly run, VAT and the GL already
 * understand. There is no second billing engine here, which is the whole reason rentable items were
 * built on the existing charge schedule rather than beside it.
 *
 * **One charge row per lease, not per item.** A lease with four bays has ONE `parking` charge whose
 * amount is the sum of what it pays for the items it holds on that date. That is forced rather than
 * chosen: `Charge` refuses two active rows of the same type covering the same period (the overlap
 * guard), and it is also what an operator wants to read on an invoice — "Parking (4 bays)", not four
 * near-identical lines.
 *
 * **Dated, like the premises.** An item taken on 1 March bills from March; released at the end of
 * June, it bills through June and stops in July. The re-derivation runs at the effective date and
 * goes through `ChargeScheduleService`, so the old amount stays true for the months it was true for.
 *
 * **And each item steps on the lease anniversary by ITS OWN rule (2026-09-12).** The holding
 * carries `escalation_mode` / `escalation_rate` / `escalation_amount` — the same three terms and
 * the same vocabulary as a charge row (`ChargeEscalation`), because in Voyager a rentable item IS
 * a recurring charge and the escalation sits on it. `assign()` states the rule at birth and
 * `setEscalation()` changes it on a holding that exists (a renewal copies it across);
 * `RentableItemPricing::rateOn()` is the one reading of what a holding bills on a date; and
 * `rebuildCharge()` sums through it, so the parking row is always what the register says it
 * should be — today, and on every anniversary the projected ladder walks. A rule is a LEASE term:
 * an ownership has no anniversary (its assessment is re-priced by the annual reconciliation), so
 * a rule handed in for one is normalised to none rather than stored inert.
 */
class AssignRentableItemService
{
    public function __construct(private ChargeScheduleService $schedule) {}

    /**
     * Assign an item to an agreement from a date — at a rate, and (for a lease) under a rule.
     *
     * @param  array{effective_from?: string|\DateTimeInterface|null, monthly_rate?: float|null, escalation_mode?: ?string, escalation_rate?: mixed, escalation_amount?: mixed}  $data
     */
    public function assign(BillableAgreement $holder, RentableItem $item, array $data = []): void
    {
        // No date means "from now" — or from the commencement, for a lease that has not started
        // yet: a bay let today to a lease beginning next month is held from the day the lease
        // does, and a holding ahead of its lease is refused below.
        $from = ChargeScheduleService::billingBoundary(
            isset($data['effective_from']) && $data['effective_from']
                ? CarbonImmutable::parse($data['effective_from'])
                : ($holder instanceof Lease && $holder->commencement_date !== null
                    ? CarbonImmutable::now()->max(CarbonImmutable::instance($holder->commencement_date))
                    : CarbonImmutable::now()),
        );

        if (! $this->holderCanTakeOn($holder)) {
            throw new DomainException(__('admin.errors.rentable_item_lease_not_active'));
        }

        // A bay in another mall cannot be let by this agreement. `assetId()` is the contract's own
        // answer, so a lease derives it through its unit and an ownership reads its own column —
        // neither caller has to know which.
        if ((int) $item->asset_id !== (int) $holder->assetId()) {
            throw new DomainException(__('admin.errors.rentable_item_other_property'));
        }

        if ($item->status === RentableItem::STATUS_OUT_OF_SERVICE) {
            throw new DomainException(__('admin.errors.rentable_item_out_of_service'));
        }

        // A bay cannot be held under a lease before the lease itself began: the create form and
        // the wizard default a blank date to the commencement, and a typed date ahead of it
        // (found by review — nothing bounded the picker) would put a parking row in front of
        // the rent's own first row.
        if ($holder instanceof Lease
            && $holder->commencement_date !== null
            && $from->lessThan(ChargeScheduleService::billingBoundary(CarbonImmutable::instance($holder->commencement_date)))) {
            throw new DomainException(__('admin.errors.rentable_item_before_commencement', [
                'date' => CarbonImmutable::instance($holder->commencement_date)->format('d/m/Y'),
            ]));
        }

        // The same double-booking rule the premises have. Lock the CONTENDED item, not the lease:
        // two operators assigning the same bay to different tenants contend on the item row, and
        // locking the lease would let both through.
        DB::transaction(function () use ($holder, $item, $from, $data) {
            $locked = RentableItem::query()->lockForUpdate()->findOrFail($item->id);

            // NOT `ignoreLeaseId: $lease->id`. That exclusion was meant for "somebody else has it"
            // and silently permitted the same lease to take the same bay TWICE — the pivot's unique
            // key is (lease, item, effective_from), so a second assignment on a different date is
            // accepted, both rows read as held, and `rebuildCharge()` sums the bay twice. A
            // double-click or an operator correcting a date doubled the tenant's parking bill with
            // nothing to show for it. Re-letting after a release still works: `effective_to` is set
            // by then, so `isHeldOn` is false.
            if ($locked->isHeldOn($from)) {
                throw new DomainException(__(
                    $holder->rentableItems()->whereKey($locked->id)->wherePivotNull('effective_to')->exists()
                        ? 'admin.errors.rentable_item_already_on_this_lease'
                        : 'admin.errors.rentable_item_already_held',
                    ['code' => $locked->code],
                ));
            }

            $rate = isset($data['monthly_rate'])
                ? round((float) $data['monthly_rate'], 2)
                : (float) $locked->monthly_rate;

            if ($rate < 0) {
                throw new DomainException(__('admin.errors.rentable_item_negative_rate'));
            }

            $holder->rentableItems()->attach($locked->id, [
                'effective_from' => $from->toDateString(),
                'effective_to' => null,
                'monthly_rate' => $rate,
                ...$this->ruleFor($holder, $data),
            ]);

            // Through the projection rather than a literal, so the assign path, the release path
            // and the nightly sweep cannot come to disagree about what `status` means.
            $locked->fresh()->recomputeStatus();

            $this->rebuildCharge($holder->fresh(), $from);
        });
    }

    /**
     * Rule on a held item's annual increase — the writer for a holding that already EXISTS
     * (`assign()` states the rule at birth), the register's twin of
     * `ChargeScheduleService::setEscalation()`.
     *
     * Reaches the LIVE holding only (open, or released at a date still ahead): a holding already
     * given back is history and keeps what it carried, and the same item may have been held,
     * released and re-let by this lease, so the row is addressed by its own id rather than by
     * `updateExistingPivot()`, which would rewrite every holding of the item at once. Then the
     * parking ladder is thrown away and re-walked through `rebuildCharge()`, which also arms the
     * anniversary the sweep keys on where the lease had none (the projection does, for every
     * door). A lease term, so a non-lease holder is a developer error rather than a refusal in
     * the operator's words — no screen offers the fields for one.
     */
    public function setEscalation(BillableAgreement $holder, RentableItem $item, string $mode, ?float $rate = null, ?float $amount = null): void
    {
        if (! $holder instanceof Lease) {
            throw new InvalidArgumentException('A rentable item steps on a LEASE anniversary; an ownership carries no rule.');
        }

        if (! in_array($mode, ChargeEscalation::MODES, true)) {
            throw new InvalidArgumentException("Unknown escalation mode [{$mode}].");
        }

        DB::transaction(function () use ($holder, $item, $mode, $rate, $amount): void {
            $today = CarbonImmutable::today()->toDateString();

            // The OPEN holding first, then the latest one still ahead: a bay released at a
            // future date and re-let from the day after has two live holdings for a while, and
            // the rule the operator sets is for the one that goes on (found by review — an
            // unordered `first()` ruled the ending one).
            $held = $holder->rentableItems()
                ->wherePivot('rentable_item_id', $item->id)
                ->where(fn ($q) => $q->whereNull('rentable_item_holdings.effective_to')
                    ->orWhereDate('rentable_item_holdings.effective_to', '>=', $today))
                ->orderByRaw('rentable_item_holdings.effective_to is null desc')
                ->orderByDesc('rentable_item_holdings.effective_from')
                ->first();

            if (! $held) {
                throw new DomainException(__('admin.errors.rentable_item_not_held'));
            }

            DB::table('rentable_item_holdings')
                ->where('id', $held->getRelationValue('pivot')->id)
                ->update(ChargeEscalation::normalise($mode, $rate, $amount));

            // The in-force sum does not move today — the rule decides the anniversaries ahead —
            // but the ladder that stands for them does, and `rebuildCharge()` re-walks it.
            $this->rebuildCharge($holder->fresh(), ChargeScheduleService::billingBoundary(CarbonImmutable::today()));
        });
    }

    /**
     * The rule an assignment stores, normalised BY MODE (the figure a mode does not read is never
     * written — `ChargeEscalation::normalise()`, the rule `Charge` applies on save). Absent means
     * the property's own proposal (`billing.new_charges_follow_escalation`), exactly as a new
     * charge on the schedule tab is proposed — so the create form, the wizard and the tab agree
     * about what an un-ruled bay does. An ownership carries none, whatever was handed in.
     *
     * @return array{escalation_mode: ?string, escalation_rate: ?float, escalation_amount: ?float}
     */
    private function ruleFor(BillableAgreement $holder, array $data): array
    {
        if (! $holder instanceof Lease) {
            return ['escalation_mode' => null, 'escalation_rate' => null, 'escalation_amount' => null];
        }

        $mode = $data['escalation_mode'] ?? ChargeEscalation::defaultModeFor($holder->assetId());

        if (! in_array($mode, ChargeEscalation::MODES, true)) {
            throw new InvalidArgumentException("Unknown escalation mode [{$mode}].");
        }

        return ChargeEscalation::normalise($mode, $data['escalation_rate'] ?? null, $data['escalation_amount'] ?? null);
    }

    /**
     * May this agreement take on a new item today?
     *
     * Per holder, because "live" means different things. A LEASE must be `active` or
     * `pending_approval` — a terminated tenancy cannot acquire a bay. An OWNERSHIP must not be
     * `transferred`: the unit has been sold on, and the former owner holds nothing. A `contracted`
     * or `reserved` owner CAN take a bay before handover, which is deliberate — the bay is part of
     * what he is buying, and `isBillable()` (handover) governs when it starts being charged, not
     * when it can be recorded.
     *
     * PUBLIC since 2026-09-11 because the *Assign* button's `visible()` reads it — the lease tab
     * and the ownership tab each restated it inline (`OPEN_TO_COMMERCIAL_ACTS` in one file,
     * `isTerminal()` in the other), which is a button and a guard free to disagree.
     */
    public function holderCanTakeOn(BillableAgreement $holder): bool
    {
        if ($holder instanceof Lease) {
            // `expired` is deliberately NOT here, and it is the one case worth writing down —
            // widening it was tried, measured, and reverted (2026-09-10).
            //
            // A bay is not like a lease. `rentable_items.status` is a PROJECTION
            // (`ProjectedState::PROJECTIONS['rentable_item.occupancy']`) whose stated meaning is
            // that a term ending RELEASES the space to the market, exactly as the same sweep
            // vacates the unit — and `isHeldOn()`, the double-let guard, reads the same
            // `active|pending_approval` list. So a bay attached to an `expired` lease reads
            // AVAILABLE the instant it is attached, can be let to somebody else with no clash
            // raised, and bills nothing (`isBillableHoldoverFor()` needs `holdover_from`): three
            // defects, not a feature.
            //
            // It is also what Yardi does. Voyager's month-to-month resident is CURRENT, not Past,
            // and Atriom's counterpart is the CONVERTED holdover — which is `active` and reaches
            // this line on the first clause. A Voyager *Past* lease acquires no rentable items
            // either. So continuing a tenancy stays an explicit act (renew, or hold over), and the
            // bay follows the tenancy rather than outliving it.
            return in_array($holder->status, Lease::OPEN_TO_COMMERCIAL_ACTS, true);
        }

        if ($holder instanceof UnitOwnership) {
            return ! $holder->status->isTerminal();
        }

        // A new agreement type must state its own rule here rather than inherit a permissive
        // default — refusing is the safe answer for something nobody has thought about yet.
        return false;
    }

    /** Give an item back, effective at the end of a date. */
    public function release(BillableAgreement $holder, RentableItem $item, mixed $effectiveTo = null): void
    {
        $to = $effectiveTo
            ? CarbonImmutable::parse($effectiveTo)->startOfDay()
            : CarbonImmutable::now()->endOfMonth()->startOfDay();

        DB::transaction(function () use ($holder, $item, $to) {
            $held = $holder->rentableItems()
                ->wherePivot('rentable_item_id', $item->id)
                ->wherePivotNull('effective_to')
                ->first();

            if (! $held) {
                throw new DomainException(__('admin.errors.rentable_item_not_held'));
            }

            // By the holding's OWN id, never `updateExistingPivot()`: that reaches every holding
            // of the item on this agreement, so a bay held in the spring, given back and re-let
            // in the summer had BOTH rows closed on the summer's release date, read as held
            // twice and summed twice (found by review of the per-item step, pre-existing).
            DB::table('rentable_item_holdings')
                ->where('id', $held->getRelationValue('pivot')->id)
                ->update(['effective_to' => $to->toDateString()]);

            // Free for re-letting once no live agreement holds it open-endedly — which is what
            // `recomputeStatus()` decides, for every path, in one place. A bay released effective
            // 30 June reads AVAILABLE from the moment the release is recorded: the operator can
            // let it from July, and that forward-booking meaning is what `status` has always had.
            $item->fresh()->recomputeStatus();

            // The new amount takes effect the day the item stops being held.
            $this->rebuildCharge($holder->fresh(), ChargeScheduleService::billingBoundary($to->addDay()));
        });
    }

    /**
     * Re-derive the agreement's `parking` rows from the items it holds, from a date onward —
     * and, for a lease, the anniversary rungs that stand after it.
     *
     * A FUNCTION of the register, re-derived rather than amended: every row from `$on` is laid
     * again from what the holdings say for each date the held set changes (an item beginning,
     * an item ending), at each item's rate ON THAT DATE (`RentableItemPricing::rateOn()`, so a
     * bay assigned from next quarter is priced past the anniversary in between). Moving the one
     * row in force and leaving the later ones standing — `setAmount()`'s discipline, right for a
     * charge an operator states rung by rung — left a bay back-dated across a started anniversary
     * unbilled for a year, and a bay released back-dated billing for the rest of the term (found
     * by review). Nothing held from a date is a GAP, never a row at zero: `setAmount(0)` once put
     * "Parking & rentable items — EGP 0.00" on every invoice for the rest of the term.
     *
     * Then the projection walks the anniversaries from the sweep's own pointer through
     * `projectParkingRung()` — the one seam every door reaches, which also arms the pointer on a
     * none-clause lease whose only step is a bay's. An ownership has no anniversary and gets no
     * projection.
     */
    private function rebuildCharge(BillableAgreement $holder, CarbonImmutable $on): void
    {
        $on = ChargeScheduleService::billingBoundary($on);
        $isLease = $holder instanceof Lease;
        $percent = $isLease ? ChargeEscalation::inheritedPercent($holder) : null;

        // Every date from `$on` at which the held set changes. Read off the pivot directly — the
        // dates of every holding this agreement ever had, a release still ahead included.
        $identity = RentableItemOptions::identity($holder);
        $boundaries = DB::table('rentable_item_holdings')
            ->where('holder_type', $identity['type'])
            ->where('holder_id', $identity['id'])
            ->get(['effective_from', 'effective_to'])
            ->flatMap(fn (object $h): array => array_filter([
                $h->effective_from ? ChargeScheduleService::billingBoundary(CarbonImmutable::parse($h->effective_from)) : null,
                $h->effective_to ? ChargeScheduleService::billingBoundary(CarbonImmutable::parse($h->effective_to)->addDay()) : null,
            ]))
            ->filter(fn (CarbonImmutable $d): bool => $d->greaterThan($on))
            ->prepend($on)
            ->unique(fn (CarbonImmutable $d): string => $d->toDateString())
            ->sortBy(fn (CarbonImmutable $d): int => $d->timestamp)
            ->values();

        // One segment per boundary, merged where the sum does not move (a bay swapped for
        // another at the same rate is one row, not two).
        $segments = [];
        $previous = null;

        foreach ($boundaries as $date) {
            $sum = RentableItemPricing::sumOn($holder, $date, $percent);

            if ($previous !== null && abs($sum - $previous) < 0.005) {
                continue;
            }

            $segments[] = ['start' => $date, 'amount' => $sum];
            $previous = $sum;
        }

        // Rent is exempt, service charge is standard-rated, and parking is neither obviously — a
        // licence to use a space rather than a lease of it. The VAT Law schedules settle that and a
        // developer does not, so the answer is the accountant's: `charge_codes.tax_code` on the
        // `parking` code, shipping exempt because under-charging the tenant beats collecting tax
        // that may not be due and having to refund it. (It was a settings toggle of its own until
        // 2026-08-11, then `vat_treatment` until the tax catalogue replaced both on 2026-08-12 —
        // one question with three homes over three days, which is how they come to disagree.)
        //
        // Resolved at ORIGINATION — and for a MONTHLY row that means each billing, not the day
        // the bay was assigned (EG-01). So neither the answer nor the rate is written here; the
        // catalogue is asked for the date being billed, and an issued invoice keeps what it billed.
        $this->schedule->relayDerivedRows($holder, 'parking', $on, $segments, [
            'name' => 'Parking & rentable items',
            'frequency' => 'monthly',
            'vat_rate' => null,
        ]);

        if ($isLease) {
            $this->schedule->projectTermEscalations($holder->fresh());
        }
    }
}
