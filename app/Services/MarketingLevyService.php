<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\MarketingBudget;
use App\Settings\MarketingSettings;
use Carbon\CarbonImmutable;

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
    /**
     * The levy rate for a lease: the lease's per-deal override if set, else the global default.
     * Call with no lease for the global default (e.g. a form placeholder).
     */
    public function ratePercent(?Lease $lease = null): float
    {
        if ($lease && $lease->marketing_levy_rate !== null) {
            return (float) $lease->marketing_levy_rate;
        }

        try {
            return (float) app(MarketingSettings::class)->levy_rate_percent;
        } catch (\Throwable $e) {
            // Settings row missing (minimal envs) — fall back to the FR default.
            return 5.0;
        }
    }

    public function amountFor(Lease $lease): float
    {
        return round((float) $lease->base_rent_monthly * $this->ratePercent($lease) / 100, 2);
    }

    /**
     * Sync the lease's marketing levy charge (idempotent — one `marketing` charge per lease).
     * Respects the per-lease options:
     *  - has_marketing_levy = false (or rate 0) → the levy is DEACTIVATED (billing stops; the
     *    charge is kept inactive, not deleted, so any history it already billed is preserved).
     *  - otherwise → active at the lease's rate (override or global default) × base rent, VAT-exempt.
     * Re-called on create, renewal, rent-change, and lease edit so a toggle/rate change takes effect.
     */
    public function createLevyCharge(Lease $lease, ?CarbonImmutable $effectiveFrom = null): ?Charge
    {
        $rate = $this->ratePercent($lease);

        if (! $lease->has_marketing_levy || $rate <= 0) {
            // Deactivate EVERY row in the levy's schedule, not just the current one — billing
            // filters on is_active, so a stale open row would keep billing a levy that was
            // turned off. Kept, not deleted: what it already billed is history.
            Charge::where('lease_id', $lease->id)->where('type', 'marketing')->update(['is_active' => false]);

            return null;
        }

        // The levy is a percentage of base rent, so it is a SCHEDULE for the same reason the rent
        // is: a past month must bill the levy that was in force then, not the one derived from
        // today's rent. `updateOrCreate` on (lease, type) used to enforce exactly one row, which
        // is the assumption a schedule breaks.
        return app(ChargeScheduleService::class)->setAmount(
            $lease,
            'marketing',
            $this->amountFor($lease),
            $effectiveFrom ?? CarbonImmutable::now()->startOfDay(),
            [
                'name' => 'Marketing Levy',
                'frequency' => 'monthly',
                'vat_applicable' => false,
                'vat_rate' => 0,
            ],
            Charge::ORIGIN_LEVY,
        );
    }
}
