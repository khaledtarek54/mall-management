<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\Unit;
use App\Support\LeaseTerm;
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
        if ($original->status !== 'active') {
            throw new InvalidArgumentException("Only active leases can be renewed (lease #{$original->id} is '{$original->status}').");
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
            // Lock the UNIT first. Occupancy is the contended resource, and every path that can
            // put an active lease on a unit (here and LeaseCreationService) takes this same row —
            // so they serialise against each other, not just against themselves.
            Unit::query()->lockForUpdate()->find($original->unit_id);

            $original = Lease::query()->lockForUpdate()->find($original->id);

            if (! $original || $original->status !== 'active') {
                throw new InvalidArgumentException(
                    'This lease was renewed by another request a moment ago — reload it before renewing again.'
                );
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

            $renewal = Lease::create(array_merge($carried, [
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
            foreach ($original->rentableItems()->get() as $item) {
                /** @var Pivot $pivot */
                $pivot = $item->getRelationValue('pivot');

                $renewal->rentableItems()->attach($item->id, [
                    'effective_from' => $commencement,
                    'effective_to' => null,
                    'monthly_rate' => $pivot->getAttribute('monthly_rate'),
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

            $original->update(['status' => 'renewed']);

            // A renewal is a fresh term with its own escalation clause, so it gets its own full
            // ladder written up front — same reason as a new lease.
            app(ChargeScheduleService::class)->projectTermEscalations($renewal->fresh());

            return $renewal;
        });
    }
}
