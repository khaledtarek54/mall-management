<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\LeaseEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Update a lease's contractual rent (and optionally service charge) in a
 * single atomic operation. Keeps Lease.base_rent_monthly /
 * service_charge_monthly aligned with the matching Charge.amount rows so
 * MonthlyBillingService and the dashboard widgets never read drifted
 * numbers. Closes audit M04 F-20 / D-13.
 *
 * Why this isn't on the standard edit form: edits via the form would
 * silently leave Charge.amount at the old value, so the next monthly
 * billing run would bill the old amount even though tables + widgets
 * show the new value.
 */
class LeaseRentChangeService
{
    public function __construct(private ChargeScheduleService $schedule) {}

    /**
     * The date the change takes effect. Defaults to today, which reproduces the old
     * overwrite-now behaviour; the escalation sweep passes the anniversary.
     *
     * @param  array<string, mixed>  $data
     */
    private function effectiveDate(array $data): CarbonImmutable
    {
        return isset($data['effective_from']) && $data['effective_from']
            ? CarbonImmutable::parse($data['effective_from'])->startOfDay()
            : CarbonImmutable::now()->startOfDay();
    }

    /**
     * @param  array{base_rent_monthly?:float|null, base_rent_rate_per_sqm_year?:float|null, service_charge_monthly?:float|null, reason?:string|null, narrative?:string, narrative_data?:array<string,mixed>, document_reference?:string|null, effective_from?:string|\DateTimeInterface|null, origin?:string|null}  $data
     */
    public function apply(Lease $lease, array $data): Lease
    {
        if (! in_array($lease->status, ['active', 'pending_approval'], true)) {
            throw new InvalidArgumentException(
                "Lease #{$lease->id} is '{$lease->status}'; only active or pending leases can have their rent changed."
            );
        }

        // ── A rate-priced lease is re-rated, not just re-priced (LS-04) ────────────────────────
        // The operator may state the new RATE, in which case it is the authority and the monthly
        // figure follows. Where only a rent is stated — which is what the escalation sweep does —
        // the rate is re-derived from it, so a 7% step raises the contracted rate by 7% too. Left
        // alone, the lease would carry a rate that no longer describes what it bills, and the rent
        // roll would show a gap that means nothing.
        $newRate = null;

        if ($lease->rent_pricing_basis === Lease::RENT_RATE) {
            $area = $lease->totalAreaSqmOn($this->effectiveDate($data));

            // The caller may legitimately state only a rate — the Change Rent modal hides the
            // amount on a rate-priced lease, because two editable fields that derive from each
            // other is how they end up disagreeing. Hold the current rent until it is re-derived.
            $data['base_rent_monthly'] ??= (float) $lease->base_rent_monthly;

            // ── BOTH DERIVATIONS HONOUR THE HOLDOVER PREMIUM (SW-049, EG-40's third door) ────
            //
            // This file did the arithmetic inline and the word `holdover` appeared nowhere in it,
            // so a lease converted to holdover lost its uplift through the ordinary Change Rent
            // action — and the model hook cannot compensate, because `Lease::saving` deliberately
            // skips re-derivation when this service states a rent ("must not be second-guessed").
            //
            // Rate → rent: the modal PREFILLS the contractual rate and requires it, so even a save
            // that only meant to change the service charge re-states 4,800 and writes the
            // contracted rent back. Measured on 250 m² at 4,800/m²/yr under a 150% holdover:
            // 150,000 → 100,000, a silent 50,000/month drop with nothing on screen to say the
            // negotiated uplift had gone.
            //
            // Rent → rate: `RentEscalationService` keeps holdovers in scope on purpose and passes
            // the UPLIFTED rent, so the inverse wrote the premium into the contractual rate —
            // 157,500 gave 7,560/m²/yr where 5,040 is contracted, and every later rate→rent
            // derivation compounds it.
            //
            // Both go through the model's helpers now, which is the only way the two directions
            // cannot disagree; the inverse is a SECOND method rather than an edit to the shared one
            // (see `deriveContractedRateFromEffectiveRent()` for why the renewal caller must not
            // inherit it).
            if (isset($data['base_rent_rate_per_sqm_year']) && $area > 0) {
                $newRate = round((float) $data['base_rent_rate_per_sqm_year'], 2);

                // On the INSTANCE, so the helper reads the rate being set rather than the stored
                // one — and on a clone, so nothing here can leave a half-applied rent on the model
                // the caller is holding.
                $priced = (clone $lease)->forceFill(['base_rent_rate_per_sqm_year' => $newRate])
                    ->deriveBaseRentFromRate($this->effectiveDate($data));

                $data['base_rent_monthly'] = $priced ?? round($newRate * $area / 12, 2);
            } elseif ($area > 0) {
                $newRate = $lease->deriveContractedRateFromEffectiveRent(
                    (float) $data['base_rent_monthly'],
                    $this->effectiveDate($data),
                ) ?? round(((float) $data['base_rent_monthly']) * 12 / $area, 2);
            }
        }

        $newRent = round((float) $data['base_rent_monthly'], 2);
        if ($newRent < 0) {
            throw new InvalidArgumentException('base_rent_monthly must be ≥ 0.');
        }

        $hasServiceUpdate = array_key_exists('service_charge_monthly', $data) && $data['service_charge_monthly'] !== null;
        $newService = $hasServiceUpdate ? round((float) $data['service_charge_monthly'], 2) : null;
        if ($hasServiceUpdate && $newService < 0) {
            throw new InvalidArgumentException('service_charge_monthly must be ≥ 0.');
        }

        $effectiveFrom = $this->effectiveDate($data);
        $origin = $data['origin'] ?? Charge::ORIGIN_MANUAL;

        return DB::transaction(function () use ($lease, $newRent, $newRate, $newService, $hasServiceUpdate, $data, $effectiveFrom, $origin) {
            $previousRent = (float) $lease->base_rent_monthly;

            $updates = ['base_rent_monthly' => $newRent];
            if ($newRate !== null) {
                $updates['base_rent_rate_per_sqm_year'] = $newRate;
            }
            if ($hasServiceUpdate) {
                $updates['service_charge_monthly'] = $newService;
            }
            $lease->update($updates);

            // The rent schedule: CLOSE the row in force and OPEN the next one from the effective
            // date — never overwrite an amount. That is the whole point of the change; see
            // ChargeScheduleService. If no row exists yet (a lease created via the form before
            // charges were seeded), the first one is opened dated to the commencement, as before.
            $opened = $this->schedule->setAmount($lease, 'base_rent', $newRent, $effectiveFrom, [
                'name' => 'Base Rent',
                // Catalogue at billing time (EG-01); a rent change must not date taxability to
                // the day the new rent was agreed.
                'vat_rate' => null,
            ], $origin);

            $openedService = null;

            if ($hasServiceUpdate) {
                $openedService = $this->schedule->setAmount($lease, 'service_charge', $newService, $effectiveFrom, [
                    'name' => 'Service Charge',
                    // null = the catalogue answers at billing time (Charge::resolvedVatRate);
                    // a value is an override.
                    'vat_rate' => null,
                    // Toggling a service charge OFF must not mint a zero row on a lease that never
                    // had one — the pre-schedule createIfZero:false rule, preserved.
                    'skip_if_zero' => true,
                ], $origin);
            }

            // The marketing levy is a percentage of base rent, so it moves WITH the rent and on the
            // SAME effective date — otherwise a past month would bill the historically-correct rent
            // beside a levy computed from today's, which is a worse inconsistency than the one this
            // change set out to fix.
            if ($newRent > 0) {
                app(MarketingLevyService::class)->createLevyCharge($lease->fresh(), $effectiveFrom);
            }

            // The history entry (story LE-01) — written INSIDE this transaction, so a change and
            // its record commit or fail together. Until now this was a sentence appended to
            // `leases.notes`: unqueryable, unreportable, unattributable, and it polluted a field
            // operators use for their own notes. The event replaces it.
            //
            // No reason supplied means a sweep did this (the escalation run passes one; a bare
            // programmatic call does not), and a factual sentence is more honest than refusing the
            // change or storing an empty string. The FORM requires the operator to type one — that
            // is where "a rent change cannot happen without a reason" is enforced, because that is
            // the only path where a human is present to give one.
            app(RecordLeaseEventService::class)->record(
                $lease,
                LeaseEvent::TYPE_RENT_MODIFICATION,
                $effectiveFrom,
                // The operator's words, or none — the narrative in the payload is what a reader
                // composes from, in THEIR language. This built an English sentence and stored it.
                isset($data['reason']) && trim((string) $data['reason']) !== ''
                    ? trim((string) $data['reason'])
                    : null,
                RecordLeaseEventService::scheduleChangePayload(
                    'base_rent',
                    $previousRent,
                    $newRent,
                    // The service-charge rung rides along when one was opened — the payload's
                    // builder drops nulls, so a rent-only change records exactly what it did.
                    [$opened, $openedService],
                    // A caller that composed its own sentence names the narrative instead; the
                    // escalation sweep is the one that does, and it runs unattended, so there is no
                    // reader's language to compose in at the moment it writes.
                    $data['narrative'] ?? 'rent_changed',
                    $data['narrative_data'] ?? [],
                ),
                $data['document_reference'] ?? null,
            );

            return $lease->fresh();
        });
    }
}
