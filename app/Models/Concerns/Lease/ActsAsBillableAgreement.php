<?php

namespace App\Models\Concerns\Lease;

use App\Models\Unit;
use App\Support\PropertySettings;
use App\Support\ProrationMethod;

/**
 * **Who owes, where it posts, and on what terms — the {@see \App\Contracts\BillableAgreement} half.**
 *
 * `MonthlyBillingService` bills a lease and a unit ownership through the same interface, so these
 * members are what let an ownership assessment and a rent invoice share one code path. Grouped here
 * because they answer the billing engine rather than the lease's own state.
 *
 * `totalMonthlyAmount()` / `annualValue()` sit with them: both are sums over the same charge rows
 * the engine bills from.
 *
 * **Moving `paymentTermsDays()` required editing a registry keyed by FILE PATH.**
 * `App\Support\SettingsReach::NOT_NULL_FALLBACKS` keys its entries as
 * `'<relative/path.php>:<column>'`, and `SettingsReachConformanceTest` walks `app/` computing that
 * path per file — so relocating the method turns the build red until the key moves with it. Nothing
 * in the method or its callers hints at that coupling. If another member ever moves out of a model,
 * grep the registries for the model's path first.
 */
trait ActsAsBillableAgreement
{
    public function totalMonthlyAmount(): float
    {
        return (float) ($this->base_rent_monthly + $this->service_charge_monthly);
    }

    public function annualValue(): float
    {
        return $this->totalMonthlyAmount() * 12;
    }

    /**
     * The late-fee terms that govern this lease (story MF-08).
     *
     * **Lease first, portfolio default second.** Real leases do not agree on the rate, the cap, the minimum
     * or the grace period — an anchor negotiates 30 days, a kiosk gets 5 — and until this existed
     * the sweep applied one global number to all of them.
     *
     * The default comes from `BillingSettings`, **not** `config('billing.*')`. That distinction was
     * a live bug: the admin Settings page writes the settings record while `LateFeeService` read the
     * config file (populated from `env`), so every late-fee value an operator saved on that screen
     * was silently ignored. Reading one source here is what makes the screen mean something.
     *
     * @return array{percent: float, grace_days: int, minimum: float, maximum: float, recurrence_days: int}
     */
    /**
     * How a PARTIAL month of this lease is priced (EG-29).
     *
     * Same three tiers as every other lease term: the clause first, then the property, then the
     * portfolio — and `actual` underneath all of it, which is what this system billed before the
     * method was expressible, so a lease that states nothing bills exactly as it always did.
     */
    public function prorationMethod(): string
    {
        return ProrationMethod::resolve($this->proration_method, $this->assetId());
    }

    public function lateFeeTerms(): array
    {
        // THREE tiers, not two. The lease's negotiated figure still wins; what changed is the
        // fallback, which used to jump straight to the portfolio and now asks the PROPERTY first.
        // Eltizam runs several malls and a late-fee rate is a per-building term — the lease tier
        // above this already assumed the number varies, so a single portfolio answer underneath it
        // was the odd one out. See `App\Support\PropertySettings`.
        $assetId = $this->assetId();

        return [
            'percent' => $this->late_fee_percent !== null
                ? (float) $this->late_fee_percent
                : (float) PropertySettings::get('billing.late_fee_percent', $assetId),
            'grace_days' => $this->late_fee_grace_days !== null
                ? (int) $this->late_fee_grace_days
                : (int) PropertySettings::get('billing.late_fee_grace_days', $assetId),
            'minimum' => $this->late_fee_minimum !== null
                ? (float) $this->late_fee_minimum
                : (float) PropertySettings::get('billing.late_fee_minimum', $assetId),
            // The clause's ceiling, on the same three tiers (EG-35). 0 = no cap at every tier,
            // which is what every install had before the column existed. It MUST be returned here
            // and not only in `LateFeeService`'s detached-invoice fallback: `invoices.lease_id` is
            // NOT NULL, so that branch never runs for a real invoice and a cap defined only there
            // would be read as an undefined key on every fee the sweep charges.
            'maximum' => $this->late_fee_maximum !== null
                ? (float) $this->late_fee_maximum
                : (float) PropertySettings::get('billing.late_fee_maximum', $assetId),
            // How often the clause lets the fee be charged again while the balance stands. 0 =
            // once per invoice, which is what every install did before EG-35 (2026-08-22).
            'recurrence_days' => $this->late_fee_recurrence_days !== null
                ? (int) $this->late_fee_recurrence_days
                : (int) PropertySettings::get('billing.late_fee_recurrence_days', $assetId),
        ];
    }

    /**
     * The property this lease belongs to.
     *
     * Derived through the MASTER unit (`leases.unit_id`), because there is no `leases.asset_id` —
     * a lease's mall is a fact about its premises. `units()` is date-ranged and can be empty outside
     * the lease's own term, so the master unit is the stable answer and the one every other
     * property-scoped query here already uses.
     *
     * Null only for a lease whose unit has been force-deleted, which `DeletionPolicy` refuses; the
     * callers treat null as "no property tier", falling through to the portfolio.
     */
    public function assetId(): ?int
    {
        return $this->unit?->asset_id
            ?? Unit::withTrashed()->whereKey($this->unit_id)->value('asset_id');
    }

    /**
     * How many days this lease's tenant has to pay.
     *
     * `payment_terms_days` is NOT NULL with a database default of 7, so the `?? defaults` that used
     * to sit at eight billing call sites could never fire — the portfolio setting was unreachable
     * for any real lease. The default belongs at ORIGINATION instead: a new lease is pre-filled from
     * its property's convention (see `LeaseForm`), and from then on the lease carries its own number.
     *
     * That is also the correct semantics, and what Yardi does. Changing a property's default must
     * not retroactively move the due date on receivables that have already been raised.
     */
    public function paymentTermsDays(): int
    {
        return (int) ($this->payment_terms_days ?? PropertySettings::paymentTermsDays($this->assetId()));
    }

    /** @see BillableAgreement::billingTenantId() */
    public function billingTenantId(): int
    {
        return (int) $this->tenant_id;
    }

    /** @see BillableAgreement::billingCurrency() */
    public function billingCurrency(): string
    {
        return $this->currency ?? 'EGP';
    }

    /** @see BillableAgreement::invoiceLinkAttributes() */
    public function invoiceLinkAttributes(): array
    {
        return ['lease_id' => $this->id];
    }
}
