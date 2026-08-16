<?php

namespace App\Models\Concerns\Lease;

use Carbon\CarbonImmutable;

/**
 * **When rent starts and what the grace period abates — the billing CALENDAR.**
 *
 * A separate concern from {@see HasLeaseTermState} even though both decide what bills, because they
 * read different column families: this one is `rent_commencement_date`, `fit_out_scope` and
 * `billing_frequency`; that one is `status`, `expiry_date` and `holdover_from`. The two never call
 * each other — `isBillableForPeriod()` reaches none of these — so the seam is real rather than
 * cosmetic.
 *
 * The call graph closes inside the trait and touches nothing but CarbonImmutable:
 *
 *   firstBillableMonth()  -> rentCommencesOn()
 *   inFitOutWindow()      -> rentCommencesOn()
 *   abatedChargeTypesFor()-> inFitOutWindow()
 *   periodInFitOut()      -> firstBillableMonth()
 *   isBillingCycleStart() -> firstBillableMonth() + billingCycleMonths()
 *
 * This refactor's plan originally moved only the first four and left `periodInFitOut()`,
 * `billingCycleMonths()` and `isBillingCycleStart()` behind — stranding three callers of
 * `firstBillableMonth()` on the model, reaching across the seam for no reason.
 *
 * **What stays on the model:** those columns, their casts, and the `$attributes` defaults
 * (`fit_out_scope`, `billing_frequency`) — `$attributes` is a class property and a trait cannot
 * redeclare it.
 */
trait DeterminesFitOutGrace
{
    /** Fit-out grace suppresses the ENTIRE invoice — rent, service charge, CAM, levy. */
    public const FIT_OUT_GROSS = 'gross';

    /**
     * Fit-out grace abates **base rent only**; the tenant still pays the service charge and every
     * other reimbursement, because the landlord is still incurring those costs while the unit is
     * fitted out. This is the industry standard ("net abatement") and the default for new leases.
     */
    public const FIT_OUT_RENT_ONLY = 'rent_only';

    /**
     * Fit-out / rent-free grace: the first period for which ANY charge bills.
     *
     * Keyed on `rent_commencement_date`, which replaced the old `fit_out_months` count — a real
     * lease says "rent commences 1 April", not "three months of fit-out", and a month count could
     * not express a mid-month start at all. Null rent-commencement means no grace: the lease bills
     * from its commencement month (operator decision 2026-07-19, OPEN-QUESTIONS C1.5).
     *
     * Null when no commencement date.
     */
    public function firstBillableMonth(): ?CarbonImmutable
    {
        if (! $this->commencement_date) {
            return null;
        }

        $commencement = CarbonImmutable::instance($this->commencement_date)->startOfMonth();

        // Under NET abatement only the rent is free — the service charge still bills — so the
        // lease's first billable month is its commencement, not the end of the fit-out window.
        // Deriving it here means `periodInFitOut()` (nothing bills), the quarterly cycle anchor
        // and the ActionRequired "unbilled leases" card all follow automatically, instead of each
        // growing its own copy of the rule.
        if ($this->fit_out_scope === self::FIT_OUT_RENT_ONLY) {
            return $commencement;
        }

        $rentStart = $this->rentCommencesOn();

        // A rent-commencement on or before the commencement month is not a grace period; it bills
        // from the start. Guarded rather than trusted so a mis-keyed earlier date cannot pull the
        // first billable month BACKWARDS and mint invoices for months before the lease existed.
        return $rentStart !== null && $rentStart->greaterThan($commencement)
            ? $rentStart
            : $commencement;
    }

    /**
     * The month rent starts billing, normalized to the first of that month, or null when no grace
     * is recorded. Billing periods are whole months, so a rent-commencement of 15 April means April
     * is the first billed month — the half-month is a proration question, not a period question.
     */
    public function rentCommencesOn(): ?CarbonImmutable
    {
        return $this->rent_commencement_date
            ? CarbonImmutable::instance($this->rent_commencement_date)->startOfMonth()
            : null;
    }

    /**
     * Is this period inside the rent-free fit-out window at all — regardless of what that grace
     * abates?
     *
     * Distinct from {@see periodInFitOut()}, which asks the narrower question "does NOTHING bill".
     * Under net abatement the answer to that is no while this is still yes, and it is this one the
     * per-charge abatement filter needs.
     */
    public function inFitOutWindow(CarbonImmutable $periodEnd): bool
    {
        if (blank($this->commencement_date)) {
            return false;
        }

        $graceEnds = $this->rentCommencesOn();
        $commencement = CarbonImmutable::instance($this->commencement_date)->startOfMonth();

        // No recorded rent-commencement, or one that is not actually later than commencement, is no
        // grace at all — the same reading the old `fit_out_months <= 0` gave.
        if ($graceEnds === null || ! $graceEnds->greaterThan($commencement)) {
            return false;
        }

        return $periodEnd->lessThan($graceEnds);
    }

    /**
     * Charge types abated for this period — free to the tenant, so they produce no invoice line.
     *
     * Empty for every lease outside its fit-out window, and for `gross` leases (whose grace
     * suppresses the whole invoice before this is ever consulted).
     *
     * **Base rent only, deliberately.** "Net abatement" in the market means the tenant keeps paying
     * the operating-cost reimbursements; the service charge, CAM and the marketing levy are all
     * costs the landlord is genuinely incurring while the unit is fitted out.
     *
     * @return array<int, string>
     */
    public function abatedChargeTypesFor(CarbonImmutable $periodEnd): array
    {
        if ($this->fit_out_scope !== self::FIT_OUT_RENT_ONLY || ! $this->inFitOutWindow($periodEnd)) {
            return [];
        }

        return ['base_rent'];
    }

    /**
     * Does the rent-free period abate this charge type — asked WITHOUT reference to a date?
     *
     * The sibling above answers "what is free in this period", which is empty once the grace has
     * run out. This answers the different question the billing engine needs in the CROSSOVER month:
     * the grace ended on the 15th, so the period is no longer inside the window, and yet the first
     * fourteen days of it were still rent-free. Only the types the grace actually covered may be
     * clipped to the rent-commencement date — under net abatement the service charge and the levy
     * have been billing in full since handover and must keep doing so.
     */
    public function graceAbates(string $chargeType): bool
    {
        return $this->fit_out_scope === self::FIT_OUT_GROSS || $chargeType === 'base_rent';
    }

    /**
     * True when the given billing period falls entirely inside the fit-out grace, so NOTHING bills.
     * fit_out_months = 0 → always false (today's behaviour). Shared by the monthly billing engine
     * and the ActionRequired "unbilled leases" card, so a lease in fit-out is neither billed nor nagged.
     */
    public function periodInFitOut(CarbonImmutable $periodEnd): bool
    {
        $first = $this->firstBillableMonth();

        return $first !== null && $periodEnd->lessThan($first);
    }

    /** Months in one billing cycle: monthly=1, quarterly=3, semiannual=6, annual=12. */
    public function billingCycleMonths(): int
    {
        return match ($this->billing_frequency) {
            'quarterly' => 3,
            'semiannual' => 6,
            'annual' => 12,
            default => 1,
        };
    }

    /**
     * Is the given month the START of a billing cycle for this lease? Cycles are anchored to the
     * lease's first billable month (commencement + fit-out) and run in full N-month steps
     * (operator decision 2026-07-19 — commencement-anchored, no partial cycles). A month before the
     * first billable month (still in fit-out / not started) is never a cycle start. For a monthly
     * lease every billable month is a cycle start, so this is true from the first billable month on.
     */
    public function isBillingCycleStart(CarbonImmutable $period): bool
    {
        $first = $this->firstBillableMonth();
        if ($first === null) {
            return false;
        }

        $month = $period->startOfMonth();
        if ($month->lessThan($first)) {
            return false;
        }

        $monthsSince = ($month->year - $first->year) * 12 + ($month->month - $first->month);

        return $monthsSince % $this->billingCycleMonths() === 0;
    }
}
