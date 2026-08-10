<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\Unit;
use Carbon\CarbonImmutable;
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

        $expiry = $commencement->addMonths($termMonths)->subDay();

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

            $assetCode = $original->unit?->asset?->code ?? 'AW';

            $renewal = Lease::create([
                'reference' => Lease::generateReference($assetCode),
                'unit_id' => $original->unit_id,
                'tenant_id' => $original->tenant_id,
                'previous_lease_id' => $original->id,
                'status' => 'active',
                'commencement_date' => $commencement,
                'expiry_date' => $expiry,
                'term_months' => $termMonths,
                'base_rent_monthly' => $newRent,
                'service_charge_monthly' => $newServiceCharge,
                // Carry the negotiated marketing-levy terms — a tenant who opted out (or has a
                // rate override) keeps that on renewal; else the model default would silently re-levy them.
                'has_marketing_levy' => $original->has_marketing_levy,
                'marketing_levy_rate' => $original->marketing_levy_rate,
                // Fit-out grace does NOT carry — it was for the original build-out; a renewal has none.
                // A renewal has no new build-out, so no rent-free grace carries over.
                'rent_commencement_date' => null,
                // Billing frequency DOES carry — a quarterly/annual lease renews on the same cadence.
                'billing_frequency' => $original->billing_frequency,
                'currency' => $original->currency,
                'security_deposit' => $original->security_deposit,
                'security_deposit_received' => $original->security_deposit_received,
                'escalation_rate' => $original->escalation_rate,
                'escalation_type' => $original->escalation_type,
                'next_escalation_date' => null,
                'has_percentage_rent' => $original->has_percentage_rent,
                'percentage_rent_threshold' => $original->percentage_rent_threshold,
                'percentage_rent_rate' => $original->percentage_rent_rate,
                'percentage_rent_calculation_type' => $original->percentage_rent_calculation_type,
                'percentage_rent_frequency' => $original->percentage_rent_frequency,
                'billing_day' => $original->billing_day,
                'payment_terms_days' => $original->payment_terms_days,
                'notes' => $original->notes,
                'metadata' => $original->metadata,
            ]);

            // Carry the original's FULL unit set into the renewal — a multi-unit
            // lease must keep all its units, not just the master (unit_id).
            $unitIds = $original->units()->pluck('units.id')->all();
            if (count($unitIds) > 1) {
                $renewal->syncUnits($unitIds, $original->unit_id);
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
