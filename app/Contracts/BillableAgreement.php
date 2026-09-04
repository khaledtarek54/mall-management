<?php

namespace App\Contracts;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\RentableItem;
use App\Support\ProrationMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Something that gives a party the right to occupy a unit, and bills them for it.
 *
 * A `Lease` is one. A unit OWNERSHIP is the other (plan 08) — a buyer who pays no rent but owes the
 * service charge, whether they trade from the unit, let it themselves, hand it to the operator to
 * let, or leave it empty. The two are different agreements over the same unit, and the money
 * machinery downstream — AR, VAT, ageing, late fees, the GL — cannot tell them apart and should not
 * have to.
 *
 * ## Why an interface rather than a `type` column on `leases`
 *
 * Because a lease is 1,360 lines of law — fit-out grace, holdover, escalation ladders, percentage
 * rent, CAM ceilings — and an ownership has almost none of it. Widening `Lease` to mean "or an
 * ownership" would make every one of those rules answer "not applicable" at runtime instead of at
 * the type level, which is how a nullable column becomes a bug report.
 *
 * ## What is deliberately NOT here
 *
 * Fit-out abatement, holdover, percentage rent, straight-line rent, CAM ceilings, escalations.
 * Those are lease law. They stay on `Lease`, and the engine that needs them keeps asking a `Lease`
 * for them. This interface is only the part that is true of ANY agreement that raises AR: who owes,
 * in what currency, on what terms, for which property, over what schedule.
 *
 * Keeping the cut this narrow is why `Lease` already satisfied five of these eight methods before
 * the interface existed — a sign the seam was found rather than invented.
 *
 * ## Consumers
 *
 * `IssueInvoiceService` today. `MonthlyBillingService` in phase 2, generalized against a real
 * second implementer rather than guessed at now — it is soaked in the lease-only concepts above,
 * and the honest way to find which of them an ownership genuinely needs is to have one.
 *
 * @see Lease
 * @see docs/modules/37-unit-owners.md
 */
interface BillableAgreement
{
    /**
     * The AR counterparty who owes what this agreement bills.
     *
     * A `tenants` row either way: that table is the AR PARTY table, and `party_type` says whether
     * the party is a retailer or a unit owner. This is Yardi's own answer — its ledger belongs to a
     * customer record, and in the condo product the owner simply is that record type — and it is
     * what lets payments, credit notes, deposits, cheques, ageing, the portal and the mobile API
     * serve an owner without learning that owners exist.
     */
    public function billingTenantId(): int;

    /** The property whose books this agreement's money belongs to. Null only on a detached fixture. */
    public function assetId(): ?int;

    /** The currency this agreement bills in. */
    public function billingCurrency(): string;

    /** Days from issue date to due date, per this agreement's own terms. */
    public function paymentTermsDays(): int;

    /**
     * How a PARTIAL month of this agreement is priced (EG-29).
     *
     * On the contract rather than on the lease alone, because the termination credit reads it to
     * un-bill exactly what the invoice billed — and it credits a unit ownership as readily as a
     * lease. One of {@see ProrationMethod::METHODS}.
     */
    public function prorationMethod(): string;

    /** How many months one invoice covers — 1 monthly, 3 quarterly, 12 annually. */
    public function billingCycleMonths(): int;

    /**
     * The `charges.frequency` values THIS agreement's billing run can actually invoice.
     *
     * Not the question {@see billingCycleMonths()} answers: that is how often an INVOICE is
     * raised, this is how often a schedule ROW recurs — and the two runs understand different
     * sets. `MonthlyBillingService::chargeAppliesToPeriod()` has an arm for all four values the
     * column may hold; `BillUnitOwnershipsService::appliesToPeriod()` answers two, deliberately
     * (no operator has asked for quarterly صيانة, and a half-built arm would bill the wrong month).
     *
     * It exists because that difference was written down in exactly ONE place — a hardcoded pair
     * of options on the unit-ownership charges form, under a comment saying a quarterly row
     * *"would be silently ignored, which is worse than not offering it"* — while `ChargeImporter`,
     * the other door onto the same table, validated `frequency` against
     * `ValueSets::allowed('charges', 'frequency')` and accepted all four. So a migrating
     * operator's quarterly assessment loaded cleanly, rendered on the schedule as a live charge,
     * and was counted by every monthly run as an ordinary `skipped`, for the life of the
     * ownership. `Charge::assertFrequencyIsBillable()` asks this at the MODEL, so the next door
     * onto the table is covered by existing rather than by being remembered.
     *
     * @return list<string> a subset of `ValueSets::allowed('charges', 'frequency')`
     */
    public function billableChargeFrequencies(): array;

    /** Does this agreement bill anything for the given period at all? */
    public function isBillableForPeriod(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): bool;

    /**
     * The recurring charge schedule this agreement bills from.
     *
     * @return HasMany<Charge, $this>
     */
    public function charges(): HasMany;

    /**
     * Parking bays, storage or signage this agreement holds.
     *
     * On the contract since 2026-08-19, when the holder of a rentable item stopped being
     * specifically a lease. Voyager assigns rentable items to the customer RECORD
     * (`docs/benchmarks/yardi/09-yardi-space-and-parking.md` §2), and its condo product makes the
     * unit owner that record — so "who can hold a bay" is exactly "who can be billed", which is
     * what this interface already means.
     *
     * @return MorphToMany<RentableItem, covariant self>
     */
    public function rentableItems(): MorphToMany;

    /**
     * The columns that record WHICH agreement raised an invoice.
     *
     * Two nullable FKs on `invoices` rather than a polymorphic pair, so every existing query on
     * `lease_id` keeps working and eager loads stay typed. Exactly one is ever set; the agreement
     * itself says which, so no caller has to know the shape.
     *
     * @return array<string, int|null>
     */
    public function invoiceLinkAttributes(): array;
}
