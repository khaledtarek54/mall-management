<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Lease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LeaseRenewalService
{
    /**
     * Renew an active lease: creates a new linked Lease and marks the original as 'renewed'.
     * Charges from the original are duplicated; base_rent and service_charge amounts pick up the new values.
     *
     * @param array{new_term_months:int, new_rent:float, new_service_charge?:float, commencement_date?:string|\DateTimeInterface|null} $data
     */
    public function renew(Lease $original, array $data): Lease
    {
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

            foreach ($original->charges as $charge) {
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

            $original->update(['status' => 'renewed']);

            return $renewal;
        });
    }
}
