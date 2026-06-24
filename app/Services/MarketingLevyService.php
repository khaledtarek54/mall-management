<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\MarketingBudget;
use App\Settings\MarketingSettings;

/**
 * Computes and applies the marketing levy (FR MKT-2): a percentage of a lease's
 * base rent, realized as a recurring `marketing` Charge on the lease (which the
 * existing billing engine then bills automatically) and accrued into the
 * property's marketing budget (FR MKT-5).
 *
 * The rate is read from MarketingSettings; the amount is captured on the Charge
 * at creation time, so a later rate change never alters historical charges.
 */
class MarketingLevyService
{
    public function ratePercent(): float
    {
        try {
            return (float) app(MarketingSettings::class)->levy_rate_percent;
        } catch (\Throwable $e) {
            // Settings row missing (minimal envs) — fall back to the FR default.
            return 5.0;
        }
    }

    public function amountFor(Lease $lease): float
    {
        return round((float) $lease->base_rent_monthly * $this->ratePercent() / 100, 2);
    }

    /**
     * Ensure the lease carries its marketing levy charge. Idempotent: one
     * marketing charge per lease, kept in sync with the current rate × base rent.
     * VAT-exempt, mirroring base rent.
     */
    public function createLevyCharge(Lease $lease): Charge
    {
        return Charge::updateOrCreate(
            ['lease_id' => $lease->id, 'type' => 'marketing'],
            [
                'name' => 'Marketing Levy',
                'amount' => $this->amountFor($lease),
                'currency' => $lease->currency,
                'frequency' => 'monthly',
                'vat_applicable' => false,
                'vat_rate' => 0,
                'is_active' => true,
            ],
        );
    }

    /**
     * Accrue an amount into the property's marketing budget for a given year
     * (FR MKT-5). Single entry point so the running total stays derived.
     */
    public function accrue(int $assetId, int $year, float $amount): MarketingBudget
    {
        $budget = MarketingBudget::forPeriod($assetId, $year);
        $budget->increment('accrued_amount', $amount);

        return $budget->refresh();
    }
}
