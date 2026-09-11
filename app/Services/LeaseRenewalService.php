<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\Unit;
use App\Support\LeaseTerm;
use App\Support\Translate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LeaseRenewalService
{
    /**
     * Renew an active lease: creates a new linked Lease and marks the original as 'renewed'.
     * Charges from the original are duplicated; base_rent and service_charge amounts pick up the new values.
     *
     * @param  array{new_term_months:int, new_rent:float, new_service_charge?:float, commencement_date?:string|\DateTimeInterface|null}  $data
     */
    public function renew(Lease $original, array $data): Lease
    {
        // Fast fail for the obvious case; the AUTHORITATIVE check is re-run under a row lock
        // inside the transaction below — this one is only here so the common mistake gets a clear
        // error without opening a transaction.
        //
        // `canBeRenewed()`, not `status === 'active'`. The 05:15 `leases:expire` sweep projects a
        // lease past its term to `expired`, and a renewal is routinely signed WEEKS after the old
        // term ran out — so an `active`-only guard made renewing possible on the single morning
        // after a term ended and never again, leaving the holdover uplift as the only way to keep
        // a tenancy alive. See {@see Lease::canBeRenewed()} for why the other three terminal
        // statuses stay refused.
        //
        // Translated, because the action catches this and shows it to the operator as a toast: it
        // is the app talking to a person, not a developer error.
        if (! $original->canBeRenewed()) {
            // Say WHICH of the two reasons it is. `canBeRenewed()` is one predicate answering a
            // question with two quite different answers — *this tenancy is closed* and *somebody
            // else is trading in the shop* — and a renewal refused with "an expired lease cannot
            // be renewed" sends the operator to look at the lease when the fact they need is on
            // the unit. A refusal that does not name its cause is a dead end.
            // THREE causes now, and the third would otherwise be worded as the first: a lease
            // already renewed is still `active`, so the generic sentence would read "an 'Active'
            // lease cannot be renewed", which is both wrong and a dead end. It names the successor,
            // because that is the record the operator actually wants.
            $existing = $original->renewals()->where('status', '!=', 'cancelled')->first();

            throw new InvalidArgumentException(match (true) {
                $existing !== null => __('admin.refusals.lease_already_renewed', [
                    'reference' => $existing->reference ?: '#'.$existing->getKey(),
                ]),
                ($relet = $original->status === 'expired' ? $original->unitLetToSomebodyElse() : null) !== null => __('admin.refusals.lease_unit_already_relet', ['unit' => $relet->code]),
                default => __('admin.refusals.lease_not_renewable', [
                    'status' => Translate::orHumanized("admin.statuses.lease.{$original->status}", $original->status),
                ]),
            });
        }

        $termMonths = (int) $data['new_term_months'];
        $newRent = (float) $data['new_rent'];
        $newServiceCharge = isset($data['new_service_charge'])
            ? (float) $data['new_service_charge']
            : (float) $original->service_charge_monthly;

        $commencement = isset($data['commencement_date']) && $data['commencement_date']
            ? CarbonImmutable::parse($data['commencement_date'])
            : CarbonImmutable::parse($original->expiry_date)->addDay();

        // The one rule, shared with creation and with the form — see App\Support\LeaseTerm.
        $expiry = CarbonImmutable::parse(LeaseTerm::expiryFrom($commencement, $termMonths));

        return DB::transaction(function () use ($original, $termMonths, $newRent, $newServiceCharge, $commencement, $expiry) {
            // Re-read the original under a row lock and re-check its status HERE.
            //
            // The check above is check-then-act with a whole transaction in between: two requests
            // that each loaded the lease before either committed — a double-click on "Renew", two
            // admins, a retried POST — both saw `active`, both passed, and both created an `active`
            // renewal. Measured: one unit left carrying TWO active leases, each billing it every
            // month, with the original sitting in `renewed`. Double-booking is the one thing this
            // module's invariants exist to prevent.
            //
            // Locking serialises them: the second request blocks until the first commits, then
            // re-reads `renewed` and is refused. Same shape as the period lock in
            // MonthlyBillingService and the session lock in PaymobPaymentInitiator.
            // THE LEASE FIRST, THEN THE UNIT — the canonical order, and it cannot be the other
            // way (SW-009c, deadlock proven on MySQL with two connections, 2026-09-05). Six paths
            // acquire leases→units IMPLICITLY: any lease UPDATE fires `LeaseObserver::updated` →
            // `Unit::recomputeStatus()`, an X lock on `units` taken while the lease row is held —
            // and an observer's lock order cannot be reordered. Unit-first here closed the cycle:
            // renewal held the unit and waited on the lease, termination held the lease and its
            // observer waited on the unit — MySQL killed one with ER_LOCK_DEADLOCK (1213), an
            // intermittent 500 on two ordinary acts.
            //
            // The unit lock still serialises the occupancy writers (creation, renewal, holdover);
            // it is simply taken second, and every re-check below runs under BOTH locks.
            $original = Lease::query()->lockForUpdate()->find($original->id);

            // ── EVERY UNIT THE RENEWAL WILL TAKE, NOT JUST THE MASTER ────────────────────────
            //
            // `syncUnits()` below re-attaches the original's WHOLE unit set, so the master pointer
            // is the wrong thing to guard. A lease over A-01 + A-02 whose term ended is vacated on
            // both by the sweep, and leasing can then sign a new lease with A-02 as ITS master —
            // legitimately, because `LeaseCreationService` asks whether A-02 has an ACTIVE lease
            // and this one is `expired`. Renewing afterwards would put two active leases on A-02,
            // both billing it, and `Lease::totalAreaSqmForPeriod()` would count that shop twice in
            // the CAM denominator, mis-apportioning every other tenant in the pool while the pool
            // still ties out.
            //
            // Ordered by id so two concurrent renewals take the rows in the same sequence and
            // cannot deadlock against each other; the lease is still locked FIRST (SW-009c).
            $unitIds = collect($original?->units()->pluck('units.id')->all() ?: [$original?->unit_id])
                ->filter()
                ->unique()
                ->sort()
                ->values();

            $units = Unit::query()->whereIn('id', $unitIds)->orderBy('id')->lockForUpdate()->get();

            if (! $original || ! $original->canBeRenewed()) {
                // The same two answers the fast-fail distinguishes — a genuine race lands here, and
                // "reload the page" is the wrong advice when the fact the operator needs is that
                // somebody else is trading in the shop.
                // The double-click lands HERE, under the lock, after the first request committed —
                // so the already-renewed branch is the one that fires and it must name the winner.
                $existing = $original?->renewals()->where('status', '!=', 'cancelled')->first();

                throw new InvalidArgumentException(match (true) {
                    $existing !== null => __('admin.refusals.lease_already_renewed', [
                        'reference' => $existing->reference ?: '#'.$existing->getKey(),
                    ]),
                    ($relet = $original?->status === 'expired' ? $original->unitLetToSomebodyElse() : null) !== null => __('admin.refusals.lease_unit_already_relet', ['unit' => $relet->code]),
                    default => __('admin.refusals.lease_changed_while_renewing'),
                });
            }

            // ── A LEASE PAST ITS TERM DOES NOT HOLD ITS UNITS ────────────────────────────────
            //
            // The `active` guard used to carry this guarantee for free: an active lease holds its
            // own shops, and `LeaseCreationService` refuses a second active lease on one, so a
            // renewal could not collide with anything. Renewing an `expired` lease loses that —
            // the sweep vacated every unit the morning the term ran out.
            //
            // The hole is SEQUENTIAL rather than a race, which is why the lock alone is not the
            // answer: the sweep vacates the shop, leasing legitimately re-lets it to somebody
            // else, and this lease is renewed weeks later — two active leases on one unit, both
            // billing it every month, with `Unit::recomputeStatus()` reporting `occupied` either
            // way so nothing looks wrong. Same shape as the guard
            // `ConvertLeaseToHoldoverService` needed for the same reason.
            //
            // A LOCKING read: under MySQL REPEATABLE READ a plain one inside this transaction is
            // answered from the snapshot taken before the wait, so the guard would look correct
            // and see nothing.
            if ($original->status === 'expired') {
                $relet = $units->first(fn (Unit $u): bool => $u->isActivelyLeasedForUpdate($original->id));

                if ($relet) {
                    throw new InvalidArgumentException(__('admin.refusals.lease_unit_already_relet', [
                        'unit' => $relet->code,
                    ]));
                }
            }

            // ── The payload is DERIVED from $fillable, never enumerated ───────────────────────
            //
            // This was a literal array written when `leases` had ~24 columns. It now has 43, and a
            // diff found **14 were silently dropped** — every one of them a term somebody
            // negotiated. The worst were invisible rather than wrong:
            //
            //   `escalation_amount`  — `escalation_type` carried but the amount did not, so
            //                          `Lease::creating` computed `configured = false`,
            //                          `next_escalation_date` stayed null, and
            //                          `RentEscalationService`'s `whereNotNull` excluded the lease
            //                          FOR ITS WHOLE TERM. A compounding revenue leak with no error.
            //   the escalation collar — the guard rail against a mistyped rate, gone on every renewal.
            //   `rent_pricing_basis`  — a rate-priced lease renewed as flat, so a later expansion
            //                          changed no rent at all.
            //
            // Enumerating is the failure mode itself: the list cannot be kept in step with a table
            // that grows, and nothing tells you when it falls behind. So the renewal now carries
            // EVERYTHING fillable except what {@see Lease::RENEWAL_RESETS} explicitly names, each
            // with its reason — and `LeaseRenewalCarriesTermsTest` fails the build on a new column
            // that is neither carried nor excluded.
            $carried = collect($original->getFillable())
                ->reject(fn (string $column): bool => array_key_exists($column, Lease::RENEWAL_RESETS))
                ->mapWithKeys(fn (string $column): array => [$column => $original->{$column}])
                ->all();

            // ── A rate-priced lease renewed at a NEGOTIATED rent (EG-39) ─────────────────────
            //
            // `Lease::saving()` re-derives `base_rent_monthly` from rate × area on CREATE — and a
            // renewal is a create — on the stated rule that a typed monthly figure cannot outrank
            // the rate the deal was struck at. That rule is right at ORIGINATION and wrong here: a
            // renewal IS a re-negotiation, so renewing a 250 m² unit at 4,800/m²/yr for 110,000
            // silently saved 100,000, with nothing on screen to say the figure had been replaced.
            //
            // The deal wins and the RATE follows it. Derived from the ORIGINAL's area, because the
            // renewal has no units until `syncUnits()` below and would divide by zero.
            $reRated = [];

            if ($original->rent_pricing_basis === Lease::RENT_RATE && $newRent > 0) {
                $rate = $original->deriveRateFromBaseRent($newRent, CarbonImmutable::parse($commencement));

                if ($rate !== null) {
                    $reRated['base_rent_rate_per_sqm_year'] = $rate;
                }
            }

            $renewal = Lease::create(array_merge($carried, $reRated, [
                // The renewal's own identity and term — these are what a renewal IS.
                // Reference deliberately NOT set here (2026-08-19). `Lease::creating` allocates it
                // under the document-number lock, and that hook returns early when a reference is
                // already filled — so pre-computing one here bypassed the lock entirely. Reproduced
                // with two processes: both computed `LSE-AW-2026-0034` and one died on the unique
                // index (pre-staging QA, F-10). The model derives the same property code from the
                // unit it is being given.
                'previous_lease_id' => $original->id,
                'status' => 'active',
                'commencement_date' => $commencement,
                'expiry_date' => $expiry,
                'term_months' => $termMonths,
                'base_rent_monthly' => $newRent,
                'service_charge_monthly' => $newServiceCharge,
            ]));

            // The agreed figure is EXACT; the rate is what it implies, rounded to 2dp. On an
            // awkward area those disagree by a cent — `round(rate × area ÷ 12)` will not always
            // land back on the typed rent — and the operator must see the number they negotiated,
            // not one a rounding produced. Quietly, because this corrects the hook's own
            // derivation rather than recording a new decision.
            if ($reRated !== [] && (float) $renewal->base_rent_monthly !== $newRent) {
                $renewal->base_rent_monthly = $newRent;
                $renewal->saveQuietly();
            }

            // Carry the original's FULL unit set into the renewal — a multi-unit
            // lease must keep all its units, not just the master (unit_id).
            $unitIds = $original->units()->pluck('units.id')->all();
            if (count($unitIds) > 1) {
                $renewal->syncUnits($unitIds, $original->unit_id);
            }

            // ── The three child collections the old service never mentioned at all ────────────
            //
            // Grepping it for `camterm`, `tier` or `rentable` returned nothing, and each omission
            // is silent in a different way:
            //
            //   LeaseCamTerm            the CAM cap and the contractually stated share.
            //                           `camTermFor()` queries by the NEW lease id, finds nothing,
            //                           and the tenant gets an UNCAPPED year-end true-up on a
            //                           capped lease — a GL-posted invoice they will dispute with
            //                           the contract in hand. The renewal's CAM panel just looks
            //                           empty, so nobody can see the cap was lost.
            //   LeasePercentageRentTier `has_percentage_rent` and the `tiered` type DO carry, so
            //                           the lease reads as configured — while `ladderFor()` returns
            //                           empty and the overage is 0.00 every single month.
            //   rentable_item_holdings  the parking bays, storage and signage the tenant is paying
            //                           for. Not carried, not billed, not noticed.
            //
            // Copied by `replicate()` rather than a field list, for the same reason the header is
            // derived: a hand-written column list is what put us here.
            foreach ($original->camTerms as $term) {
                $term->replicate()->fill(['lease_id' => $renewal->id])->save();
            }

            foreach ($original->percentageRentTiers as $tier) {
                $tier->replicate()->fill(['lease_id' => $renewal->id])->save();
            }

            // The pivot carries its own terms (rate, and the window it applies to). `effective_to`
            // is deliberately NOT carried: it was scoped to the original term, and a renewal that
            // inherited an end date already in the past would silently stop billing the bay.
            // Read the pivot columns off the relation rather than the model: `$item->pivot` is only
            // typed when the relation declares it, and PHPStan is right that it is not a property
            // of RentableItem.
            //
            // **Only the holdings still LIVE ON THE ORIGINAL'S LAST DAY.** The relation carries the
            // whole HISTORY — a bay given back in month three still has its row, with `effective_to`
            // set — and this loop deliberately does not carry `effective_to`, so an unfiltered read
            // re-attached every released bay to the renewal OPEN-ENDEDLY. `rebuildCharge()` then
            // sums it, and the tenant is billed again for a bay they handed back a year ago, on a
            // renewal nobody re-reads item by item.
            //
            // The bound is the ORIGINAL'S EXPIRY, not the renewal's commencement, and the
            // difference is the whole rule: a holding scoped to the old term ends ON the last day,
            // so a commencement-based filter would drop exactly the bays this loop exists to carry
            // (that is what the sibling assertion in `LeaseRenewalCarriesTermsTest` pins). A bay
            // released BEFORE the term ended is the one that must not come with it.
            // The bound is the EARLIER of the old expiry and the renewal's commencement. Expiry is
            // the right answer for the ordinary case — a holding scoped to the old term ends ON the
            // last day, so a commencement-based bound would drop exactly the bays this loop exists
            // to carry — but `commencement_date` is an unbounded picker on the modal, and an EARLY
            // re-gear (new term starting before the old one ended) is ordinary retail practice. A
            // bay live on the day the new term began must come with it.
            $heldUntil = CarbonImmutable::parse($original->expiry_date ?? $commencement);

            if ($commencement->lessThan($heldUntil)) {
                $heldUntil = $commencement;
            }

            $stillHeld = fn ($q) => $q
                ->whereNull('rentable_item_holdings.effective_to')
                ->orWhereDate('rentable_item_holdings.effective_to', '>=', $heldUntil->toDateString());

            foreach ($original->rentableItems()->where($stillHeld)->get() as $item) {
                // ── AND THE BAY MUST NOT HAVE BEEN LET TO SOMEBODY ELSE IN THE MEANTIME ───────
                //
                // This loop `attach()`es directly, so NONE of `AssignRentableItemService`'s guards
                // run — including `isHeldOn()`, which is the only double-let guard a bay has. That
                // was safe while only an `active` lease could be renewed, because such a lease
                // still held its bays and nobody else could have taken them.
                //
                // An `expired` one opens a real window: the sweep frees the bay the morning the
                // term ends (`reprojectRentableItems()`), an operator lets it to another tenant a
                // fortnight later, and the renewal then re-attaches it open-endedly from the day
                // after the old expiry — two live holdings on one bay, overlapping, with the pivot
                // keyed on `(holder, item, effective_from)` so nothing in the database catches it
                // and both tenants carrying a `parking` charge for the same space.
                //
                // Only asked when the original is `expired`: for an `active` one its OWN holding
                // makes `isHeldOn()` true, and refusing there would drop every bay on every
                // ordinary renewal.
                if ($original->status === 'expired' && $item->isHeldOn(CarbonImmutable::now())) {
                    throw new InvalidArgumentException(__('admin.refusals.rentable_item_relet_since_term_ended', [
                        'code' => $item->code,
                    ]));
                }

                /** @var Pivot $pivot */
                $pivot = $item->getRelationValue('pivot');

                $renewal->rentableItems()->attach($item->id, [
                    'effective_from' => $commencement,
                    'effective_to' => null,
                    'monthly_rate' => $pivot->getAttribute('monthly_rate'),
                    // The bay's own annual increase travels with its rate (2026-09-12) — a
                    // renewal re-negotiates the rent, not the parking clause, and a holding that
                    // lost its rule on renewal would stand still for a term with nothing to say
                    // so. Same three columns `Charge::CARRIED_TERMS` carries for a charge row.
                    'escalation_mode' => $pivot->getAttribute('escalation_mode'),
                    'escalation_rate' => $pivot->getAttribute('escalation_rate'),
                    'escalation_amount' => $pivot->getAttribute('escalation_amount'),
                ]);
            }

            // Carry ONE row per charge type: the one in force at renewal.
            //
            // A charge type is now a date-ranged SCHEDULE (ChargeScheduleService), so a lease
            // three years into a 7%-escalating tenancy has three `base_rent` rows. Copying them
            // all — which is what iterating $original->charges did — would put three overlapping
            // open-ended rent rows on the renewal and bill the tenant three times a month. The
            // renewal starts a fresh schedule from its own commencement.
            $carried = $original->charges
                ->filter(fn (Charge $c) => $c->is_active && $c->frequency !== 'one_time')
                ->sortBy([['start_date', 'asc'], ['id', 'asc']])
                // keyBy on type keeps the LAST (latest-starting) row per type — the one in force.
                ->keyBy('type');

            foreach ($carried as $charge) {

                $amount = match ($charge->type) {
                    'base_rent' => $newRent,
                    'service_charge' => $newServiceCharge,
                    default => (float) $charge->amount,
                };

                Charge::create([
                    'lease_id' => $renewal->id,
                    'name' => $charge->name,
                    'type' => $charge->type,
                    'amount' => $amount,
                    'currency' => $charge->currency,
                    'frequency' => $charge->frequency,
                    'vat_applicable' => $charge->vat_applicable,
                    'vat_rate' => $charge->vat_rate,
                    // The row's TERMS, carried with the rest of it — `Charge::CARRIED_TERMS`, the
                    // one list. A renewal that dropped `billing_timing` silently moved an arrears
                    // service charge back to advance, billing the tenant the crossover month
                    // twice; one that dropped `prorate` (EG-29) let the renewal's own final
                    // part-month claw back part of a flat fee the tenant owes in full; and one
                    // that dropped the charge's own annual-increase rule (point 24) would renew a
                    // bay that steps +500 a year as one that never steps again. A renewal is
                    // exactly where nobody re-reads every charge.
                    ...$charge->carriedTerms(),
                    'start_date' => $commencement,
                    'end_date' => null,
                    'is_active' => true,
                ]);
            }

            // ── A RENEWAL'S RENT AND SERVICE CHARGE ARE NEGOTIATED TERMS, NOT COPIES ─────────
            //
            // The loop above CARRIES rows, so `new_rent` and `new_service_charge` reached the
            // schedule only by amending a row the original already had. When the original had
            // none of that type, the figure was written to `leases.*_monthly` and the schedule
            // got nothing — and the schedule is what bills.
            //
            // Measured on demo lease #4, renewed at 110,000 rent + 12,000 service charge: the
            // lease record read 12,000, the schedule held no service-charge row at all, and the
            // first invoice came out at 115,500 instead of 127,500. 144,000 a year, on a lease
            // whose own screen shows the right figure — so the operator has nothing to notice.
            //
            // Base rent is the worse half and fails the same way: an original whose rent row had
            // been closed (a lease that ran to the end of a stepped ladder, an operator who
            // deactivated it) carries no row, and the renewal then bills the marketing levy alone.
            //
            // The renewal terms are the authority here, exactly as they are on the lease record.
            // Rows are only ADDED — a carried row already has the new amount from the `match()`
            // above, so this can never overwrite a term the loop just set.
            $stated = ['base_rent' => $newRent, 'service_charge' => $newServiceCharge];

            foreach ($stated as $type => $amount) {
                if ($amount <= 0 || $renewal->charges()->where('type', $type)->exists()) {
                    continue;
                }

                Charge::create([
                    'lease_id' => $renewal->id,
                    'name' => $type === 'base_rent' ? 'Base Rent' : 'Service Charge',
                    'type' => $type,
                    'amount' => $amount,
                    'currency' => $renewal->currency ?? 'EGP',
                    'frequency' => 'monthly',
                    // Null on both, so `Charge::resolvedVatRate()` asks the catalogue for the date
                    // being billed — the same shape LeaseCreationService writes. A rate frozen here
                    // would stop a VAT change ever reaching the renewal (EG-01).
                    'vat_applicable' => null,
                    'vat_rate' => null,
                    'start_date' => $commencement,
                    'end_date' => null,
                    'is_active' => true,
                ]);
            }

            // Resync the marketing levy to the renewal's (possibly escalated) rent
            // so it's 5% of the NEW base rent, not the copied original amount.
            if ($newRent > 0) {
                app(MarketingLevyService::class)->createLevyCharge($renewal->fresh());
            }

            // ── THE ORIGINAL KEEPS RUNNING UNTIL ITS TERM ACTUALLY ENDS ───────────────────────
            //
            // This used to be unconditional, and that is the whole of the defect it fixes. A
            // renewal is normally negotiated MONTHS before the term ends, and `renewed` is a
            // TERMINAL status outside `BILLABLE_STATUSES` — so signing the renewal stopped the
            // original invoicing the months it still had to run, on a shop still trading, with the
            // successor not yet commenced and billing nothing either. Measured: renewing in
            // September a lease expiring 31 December left all three remaining months uninvoiced,
            // and `billing:scan-unbilled-periods` was structurally unable to report it, because
            // that scan only reports months a BILLABLE lease missed.
            //
            // A term that has ALREADY run is stamped here as before — that is LE-04, a renewal
            // signed after the old lease expired, where waiting for a sweep would leave the record
            // reading `expired` beside its own successor. Otherwise the lease stays as it is and
            // `leases:expire` writes `renewed` on the day the term ends, from the same successor
            // relation, which is where `expired` and `terminated` are already decided.
            if ($original->hasExpiredTerm() || in_array($original->status, Lease::TERMINAL_STATUSES, true)) {
                $original->update(['status' => 'renewed']);
            }

            // A renewal is a fresh term with its own escalation clause, so it gets its own full
            // ladder written up front — same reason as a new lease.
            app(ChargeScheduleService::class)->projectTermEscalations($renewal->fresh());

            return $renewal;
        });
    }
}
