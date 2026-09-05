<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\Unit;
use App\Settings\BillingSettings;
use App\Support\LeaseEventNarrative;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Convert an expired-but-occupied lease into a billing holdover (story LE-04, scenario S9).
 *
 * **What was wrong.** A lease past its expiry with the tenant still in the unit kept the unit
 * marked occupied, appeared on the ActionRequired dashboard — and billed nothing at all, because
 * `isBillableForPeriod()` refuses any period starting after expiry. The tenant traded rent-free in
 * the mall's space for as long as nobody got round to renewing or terminating them. The alert was
 * the end of the story instead of the prompt for an action; this is that action.
 *
 * **Nothing converts itself, deliberately.** "The tenant is still in the unit" is a fact only a
 * human knows. An automatic conversion would invent rent for a tenant who had already moved out and
 * left the operator arguing with an invoice, which is worse than the gap it closes. So the sweep
 * still only raises the card; an operator confirms, and that confirmation is the event.
 *
 * **The rent is a multiple of the last rent in force**, typically 150% — a deterrent by design,
 * because holdover is meant to cost more than renewing. The row it opens is open-ended: holdover
 * runs month to month until a renewal or a termination ends it, and neither has a knowable date on
 * the day of conversion.
 */
class ConvertLeaseToHoldoverService
{
    public function __construct(private ChargeScheduleService $schedule) {}

    /**
     * @param  array{rate_pct?:float|null, effective_from?:string|\DateTimeInterface|null, reason:string, document_reference?:string|null}  $data
     */
    public function convert(Lease $lease, array $data): Lease
    {
        // `awaitsHoldoverDecision()`, not `status === 'active'`. The nightly `leases:expire` sweep
        // projects exactly this candidate set to `expired` at 05:15, so an `active`-only guard made
        // the whole workflow reachable on one morning and never again. `expired` is a PROJECTION —
        // the machine's guess that nobody continued the tenancy — and converting to holdover is the
        // operator asserting the opposite, which is the one fact only a person holds. A tenancy
        // somebody really did close (a termination event, or a renewal) is excluded by the predicate
        // and stays refused.
        if (! $lease->awaitsHoldoverDecision()) {
            throw new InvalidArgumentException(
                "Lease #{$lease->id} is '{$lease->status}' and has no outstanding holdover decision."
            );
        }

        if (blank($lease->expiry_date)) {
            throw new InvalidArgumentException('An open-ended lease has not expired, so it cannot hold over.');
        }

        if ($lease->isConvertedHoldover()) {
            throw new InvalidArgumentException('This lease is already billing as a holdover.');
        }

        $expiry = CarbonImmutable::instance($lease->expiry_date);

        if ($expiry->greaterThanOrEqualTo(CarbonImmutable::now()->startOfDay())) {
            throw new InvalidArgumentException('This lease has not expired yet — renew or extend it instead.');
        }

        $rate = isset($data['rate_pct'])
            ? (float) $data['rate_pct']
            : (float) app(BillingSettings::class)->holdover_default_rate_pct;

        // The same floor the modal enforces, because this is the gate: the modal's `minValue` is a
        // real server-side rule, but `$data['rate_pct']` may be absent — in which case the rate is
        // the PORTFOLIO DEFAULT, which until 2026-09-03 could itself be saved below 100.
        //
        // Translated, unlike its four siblings in this method, because the action catches
        // `\InvalidArgumentException` and renders the message as a danger notification — so this one
        // is the app talking to a person, and it names the escape.
        if ($rate < Lease::HOLDOVER_MIN_RATE_PCT) {
            throw new InvalidArgumentException(__('admin.refusals.holdover_rate_below_floor', [
                'min' => (int) Lease::HOLDOVER_MIN_RATE_PCT,
            ]));
        }

        // Holdover begins the month after the term ended unless the operator says otherwise. A
        // later date is legitimate (the parties agreed terms in arrears, which is the normal case);
        // an EARLIER one would bill months the lease itself already covered.
        $effectiveFrom = ChargeScheduleService::billingBoundary(
            isset($data['effective_from']) && $data['effective_from']
                ? CarbonImmutable::parse($data['effective_from'])
                : $expiry->addDay()
        );

        if ($effectiveFrom->lessThanOrEqualTo(ChargeScheduleService::billingBoundary($expiry))) {
            throw new InvalidArgumentException(
                'Holdover cannot start in a month the lease term already covered.'
            );
        }

        return DB::transaction(function () use ($lease, $rate, $effectiveFrom, $expiry, $data) {
            // Lock the UNIT, and re-ask under the lock — the third writer that makes a lease active
            // on a shop, and the two that came before it (`LeaseCreationService`,
            // `LeaseRenewalService`) both do exactly this for exactly this reason.
            //
            // It is not only a race. The sweep OPENS the hole sequentially: the term ends, the lease
            // is projected `expired`, the unit re-projects to vacant, leasing re-lets it — and weeks
            // later somebody works the ActionRequired card and converts the old lease. Both would be
            // `active` on one shop, both would bill, and `Unit::recomputeStatus()` would report
            // `occupied` either way. Before this change it could not happen, because the conversion
            // required `active` and an active lease blocks the re-let.
            //
            // A LOCKING read: the row lock serialises the writers, and only a locking read can see
            // what the one that went first committed (MySQL REPEATABLE READ answers a plain read
            // from the snapshot taken before the wait).
            // THE LEASE FIRST, THEN THE UNIT — same canon and same proof as
            // `LeaseRenewalService` (SW-009c): the observer edge (any lease UPDATE → X lock on
            // `units`) fixes the order leases→units, and this service both locks the unit AND
            // later updates the lease, so unit-first was the other half of a proven deadlock.
            $lease = Lease::query()->lockForUpdate()->findOrFail($lease->id);

            $unit = Unit::query()->lockForUpdate()->find($lease->unit_id);

            if ($unit && $unit->isActivelyLeasedForUpdate($lease->id)) {
                throw new InvalidArgumentException(
                    "Unit {$unit->code} has been re-let since this lease expired, so it cannot hold over."
                );
            }

            // The last rent actually in force — read at EXPIRY, not today. A schedule with a step
            // dated after the term ended (a projected escalation the lease never reached) must not
            // become the basis of the holdover rent.
            $lastRow = $this->schedule->rowInForce($lease, 'base_rent', ChargeScheduleService::billingBoundary($expiry));

            if ($lastRow === null) {
                throw new InvalidArgumentException('This lease has no base-rent schedule to hold over from.');
            }

            $lastRent = (float) $lastRow->amount;
            $holdoverRent = round($lastRent * $rate / 100, 2);

            // Close every base-rent row that is still open-ended, so the holdover row is the only
            // one covering the months from here on. Without this the projected ladder would keep
            // billing the contracted rent straight through the holdover.
            Charge::query()
                ->where('lease_id', $lease->id)
                ->where('type', 'base_rent')
                ->where('is_active', true)
                ->where('frequency', '!=', 'one_time')
                ->get()
                ->each(function (Charge $row) use ($effectiveFrom): void {
                    $start = $row->start_date ? CarbonImmutable::instance($row->start_date) : null;

                    if ($start && $start->greaterThanOrEqualTo($effectiveFrom)) {
                        // A contracted step dated inside the holdover: the term never reached it,
                        // so it must not bill. Deactivated rather than deleted — it stays legible
                        // as something the lease had scheduled and did not get to.
                        $row->update(['is_active' => false]);

                        return;
                    }

                    $end = $row->end_date ? CarbonImmutable::instance($row->end_date) : null;

                    if ($end === null || $end->greaterThanOrEqualTo($effectiveFrom)) {
                        $row->update(['end_date' => $effectiveFrom->subDay()->toDateString()]);
                    }
                });

            $holdoverRow = Charge::create([
                'lease_id' => $lease->id,
                'name' => $lastRow->name,
                'type' => 'base_rent',
                'origin' => Charge::ORIGIN_MANUAL,
                'amount' => $holdoverRent,
                'currency' => $lease->currency ?? 'EGP',
                'frequency' => 'monthly',
                // Base rent is VAT-exempt, and holding over does not change what the supply is —
                // so it resolves through the charge code, exactly like the row it continues.
                // Catalogue at billing time, not frozen here (EG-01) — a holdover can run for
                // months, which is precisely when a ruling changes underneath it.
                'vat_rate' => null,
                'start_date' => $effectiveFrom->toDateString(),
                // Open-ended: holdover runs month to month until a renewal or termination ends it,
                // and neither has a knowable date today.
                'end_date' => null,
                'is_active' => true,
            ]);

            $lease->update([
                // Back to `active`, because the tenant IS still in the unit — which is the whole
                // assertion this act makes. Without it the conversion would succeed and still bill
                // nothing: `isBillableForPeriod()` and `scopeBillableForPeriod()` both require
                // `active`, so the LE-04 defect would simply move one layer down. Stable under the
                // sweep, too — `holdover_from` is now set, so the candidate query excludes it and a
                // second run changes nothing.
                'status' => 'active',
                'holdover_rate_pct' => $rate,
                'holdover_from' => $effectiveFrom->toDateString(),
                // The rent in force really is the holdover rent now — the dashboard, the rent roll
                // and the widgets all read this column and would otherwise report the expired one.
                'base_rent_monthly' => $holdoverRent,
            ]);

            app(RecordLeaseEventService::class)->record(
                $lease,
                LeaseEvent::TYPE_HOLDOVER,
                $effectiveFrom,
                $data['reason'],
                [
                    LeaseEventNarrative::KEY => 'converted_to_holdover',
                    'charge_type' => 'base_rent',
                    'amount_from' => $lastRent,
                    'amount_to' => $holdoverRent,
                    'holdover_rate_pct' => $rate,
                    'expired_on' => $expiry->toDateString(),
                    'rows_opened' => [[
                        'id' => $holdoverRow->id,
                        'amount' => $holdoverRent,
                        'start_date' => $holdoverRow->start_date?->toDateString(),
                        'end_date' => null,
                    ]],
                ],
                $data['document_reference'] ?? null,
            );

            return $lease->fresh();
        });
    }
}
