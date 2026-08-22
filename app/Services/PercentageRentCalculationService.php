<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\LeasePercentageRentTier;
use App\Models\TenantSalesDeclaration;
use App\Models\User;
use App\Notifications\SalesDeclarationLockedNotification;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PercentageRentCalculationService
{
    /**
     * Calculate the percentage rent owed for a declaration, based on the lease's
     * percentage-rent configuration. Returns 0 when the lease has no percentage-rent terms.
     *
     * - artificial: percentage_rent = max(0, (sales - threshold) * rate%)
     * - natural_breakpoint: percentage_rent = max(0, sales * rate% - base_rent_monthly)
     */
    public function calculate(TenantSalesDeclaration $declaration): float
    {
        $lease = $declaration->lease;

        if (! $lease instanceof Lease || ! $lease->has_percentage_rent) {
            return 0.0;
        }

        // ANNUAL: each month carries its CANONICAL chronological marginal contribution to the year's
        // cumulative overage — overage(cumulative sales through this month) − overage(through the
        // previous month), each floored at 0. Deterministic (independent of lock order), always ≥ 0
        // (a seasonal spike is netted against slow months, never over-billed against a monthly
        // breakpoint), and the months' marginals sum to overage(total year sales). WHICH month carries
        // the overage is fixed by calendar order, so every lock/void re-trues the WHOLE year
        // (retrueAnnualYear) — removing or adding a month re-attributes the others, keeping the live
        // invoices summing to overage(cumulative). This value is the display estimate; the authoritative
        // per-month figure is written by retrueAnnualYear at lock time.
        if (($lease->percentage_rent_frequency ?? 'monthly') === 'annual') {
            $prior = $this->priorLockedSalesYtd($declaration);
            $withThis = $prior + (float) $declaration->declared_sales;

            $year = Carbon::parse($declaration->period_start)->year;

            $gross = round(max(0.0, $this->overage($lease, $withThis, $year)) - max(0.0, $this->overage($lease, $prior, $year)), 2);

            return $this->netOfDeductions($declaration, $gross);
        }

        // MONTHLY (default): the breakpoint applies fresh to this month's sales.
        $gross = round(max(0.0, $this->overage($lease, (float) $declaration->declared_sales)), 2);

        return $this->netOfDeductions($declaration, $gross);
    }

    /**
     * Net the gross overage against the charges this lease's clause makes creditable against it —
     * *"percentage rent is payable to the extent it exceeds CAM and real-estate tax paid in the
     * same period"*.
     *
     * Applied AFTER the basis has produced its gross figure, deliberately: the deduction is a
     * clause about what the tenant already paid, not about how the breakpoint works, so it must
     * not perturb the cumulative-marginal arithmetic that has to keep summing to the year's
     * overage.
     *
     * **Floored at zero.** Deductions that exceed the overage do not become a refund — a clause
     * that says "payable to the extent it exceeds X" owes nothing when it does not exceed X.
     * Letting it go negative would silently credit the tenant for their own service charge.
     */
    private function netOfDeductions(TenantSalesDeclaration $declaration, float $gross): float
    {
        $deductible = $this->deductionFor($declaration);

        if ($deductible <= 0) {
            return $gross;
        }

        return round(max(0.0, $gross - $deductible), 2);
    }

    /**
     * What this declaration's period already billed in the lease's deductible charge types.
     *
     * Reads the INVOICED amounts for the period, not the lease's configured monthly figures: the
     * clause credits what the tenant was actually charged, and those differ the moment a month is
     * prorated, abated or re-billed. Cancelled and written-off invoices are excluded — a charge
     * that was reversed was never paid, so crediting it would hand the tenant a deduction for
     * money they never spent.
     */
    public function deductionFor(TenantSalesDeclaration $declaration): float
    {
        $types = $declaration->lease?->percentage_rent_deductible_types ?? [];

        if (! is_array($types) || $types === []) {
            return 0.0;
        }

        return round((float) InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.lease_id', $declaration->lease_id)
            ->whereNotIn('invoices.status', ['cancelled', 'draft', 'written_off'])
            ->whereDate('invoices.period_start', '<=', $declaration->period_end)
            ->whereDate('invoices.period_end', '>=', $declaration->period_start)
            ->whereIn('invoice_items.type', $types)
            ->sum('invoice_items.amount'), 2);
    }

    /**
     * A plain-language breakdown of how this declaration's percentage rent is worked out — so an
     * operator (and the tenant notification) can SEE and verify the number rather than trusting a bare
     * figure. For an annual lease this is the whole point: it exposes the running cumulative that a
     * single month's charge is otherwise impossible to explain. `applicable` is false when the lease
     * has no percentage-rent terms. `is_estimate` flags a not-yet-locked figure.
     *
     * @return array<string, mixed>
     */
    public function explain(TenantSalesDeclaration $declaration): array
    {
        $lease = $declaration->lease;
        if (! $lease instanceof Lease || ! $lease->has_percentage_rent) {
            return ['applicable' => false];
        }

        $annual = ($lease->percentage_rent_frequency ?? 'monthly') === 'annual';
        $natural = ($lease->percentage_rent_calculation_type ?? 'artificial') === 'natural_breakpoint';
        $rate = (float) $lease->percentage_rent_rate;

        // The breakpoint we DISPLAY is always the SALES level at which percentage rent begins, so it is
        // directly comparable to declared/cumulative sales. Artificial = the stated threshold. Natural =
        // sales × rate = base rent → sales = base rent ÷ rate (× 12 base for an annual lease). Showing
        // the raw base rent here instead — as the first cut did — reads as a nonsensical "breakpoint"
        // that a tenant's sales dwarf while still owing nothing.
        $breakpoint = $natural
            ? ($rate > 0 ? round(((float) $lease->base_rent_monthly * ($annual ? 12 : 1)) / ($rate / 100.0), 2) : 0.0)
            : (float) ($lease->percentage_rent_threshold ?? 0);

        // A SHORT year gets a short breakpoint, and the explanation must show the one the
        // calculation used. Displaying the full-year figure beside a charge computed against a
        // quarter of it is how a correct invoice comes to look like a mistake — and how a wrong one
        // escapes notice.
        $yearFactor = $annual ? $this->yearFactor($lease, Carbon::parse($declaration->period_start)->year) : 1.0;
        $breakpoint = round($breakpoint * $yearFactor, 2);

        $base = [
            'applicable' => true,
            'frequency' => $annual ? 'annual' : 'monthly',
            'method' => $natural ? 'natural_breakpoint' : 'artificial',
            'rate' => $rate,
            'breakpoint' => $breakpoint,
            'declared_sales' => (float) $declaration->declared_sales,
            'this_period_share' => (float) $declaration->calculated_percentage_rent,
            'is_estimate' => $declaration->status !== 'locked',
            // < 1.0 on a lease that traded only part of this calendar year.
            'year_factor' => $yearFactor,
        ];

        if (! $annual) {
            return $base;
        }

        $prior = $this->priorLockedSalesYtd($declaration);
        $cumulative = $prior + (float) $declaration->declared_sales;

        return $base + [
            'prior_ytd_sales' => $prior,
            'cumulative_ytd_sales' => $cumulative,
            'ytd_overage' => round(max(0.0, $this->overage($lease, $cumulative, Carbon::parse($declaration->period_start)->year)), 2),
        ];
    }

    /**
     * How an annual lease's overage is currently spread across the year's LOCKED months (each month's
     * live share + the year total). Used to tell the operator, after a lock/void re-trues the year,
     * exactly how the % rent now sits — the re-attribution is otherwise invisible.
     *
     * @return array{year:int, months:array<int,array{period:string, share:float}>, total:float, cumulative_sales:float}
     */
    public function yearAttribution(int $leaseId, int $year): array
    {
        $months = TenantSalesDeclaration::query()
            ->where('lease_id', $leaseId)
            ->where('status', 'locked')
            ->whereYear('period_start', $year)
            ->orderBy('period_start')
            ->get(['id', 'period_start', 'calculated_percentage_rent', 'declared_sales']);

        return [
            'year' => $year,
            'months' => $months->map(fn ($d) => [
                'period' => Carbon::parse($d->period_start)->isoFormat('MMM YYYY'),
                'share' => (float) $d->calculated_percentage_rent,
            ])->all(),
            'total' => (float) $months->sum('calculated_percentage_rent'),
            'cumulative_sales' => (float) $months->sum('declared_sales'),
        ];
    }

    /**
     * The raw (pre-floor) percentage-rent overage on a given sales figure, per the lease's formula.
     * Artificial: (sales − breakpoint) × rate. Natural: sales × rate − base rent (× 12 for an annual
     * lease, whose breakpoint is the ANNUAL base rent).
     */
    private function overage(Lease $lease, float $sales, ?int $year = null): float
    {
        $annual = ($lease->percentage_rent_frequency ?? 'monthly') === 'annual';

        // ── A SHORT percentage-rent year gets a SHORT breakpoint (2026-08-16) ──────────────────
        //
        // An annual breakpoint is a whole year's figure. Applied unchanged to a year the lease only
        // traded part of, it is unreachable: a lease commencing 1 October carried a 12,000,000
        // breakpoint against three months of trading, so it owed no percentage rent at all in its
        // first year and the clock then reset on 1 January. That is a straight under-bill of the
        // landlord's share, and it is silent — the tenant simply never crosses a line nobody looks at.
        //
        // The market rule is to pro-rate the breakpoint for the short year, and the natural
        // breakpoint proves why it must be: it is DEFINED as annual base rent ÷ rate, and a tenant
        // who occupies three months pays three months of base rent, so the sales at which the
        // percentage would have covered that rent are a quarter as many.
        //
        // **Applied by annualising the sales rather than scaling each breakpoint**, which is the
        // same arithmetic and survives every calculation type:
        //
        //     overage_short(S) = f × overage(S ÷ f)
        //
        //   artificial:  f × ((S/f) − T)·r          = (S − f·T)·r      ← threshold scaled
        //   natural:     f × ((S/f)·r − 12·B)       = S·r − f·12·B     ← annual base rent scaled
        //   tiered:      every band boundary scales with f, whatever the ladder's shape
        //
        // A tiered ladder cannot be pro-rated by scaling "the breakpoint" — there isn't one — so
        // doing it at the sales end is what keeps the three types on one rule instead of three.
        //
        // MONTHLY leases are untouched: their breakpoint is already a monthly figure applied fresh
        // to a month's actual sales, so there is no year to be short of.
        $factor = ($annual && $year !== null) ? $this->yearFactor($lease, $year) : 1.0;

        if ($factor <= 0.0) {
            return 0.0;   // the lease was not in force at all that year
        }

        if ($factor < 1.0) {
            return round($factor * $this->overage($lease, $sales / $factor), 2);
        }

        $rate = (float) $lease->percentage_rent_rate / 100.0;
        $type = $lease->percentage_rent_calculation_type ?? 'artificial';

        // TIERED: a breakpoint ladder, where each band charges only the sales within it. Inserted
        // HERE, at the single choke point, so both the monthly and the annual (cumulative-marginal)
        // bases become tiered without touching either — the marginal arithmetic and
        // retrueAnnualYear() are expressed purely in terms of overage(), and stay correct.
        if ($type === 'tiered') {
            return LeasePercentageRentTier::overageFor(
                LeasePercentageRentTier::ladderFor($lease),
                $sales,
            );
        }

        if ($type === 'natural_breakpoint') {
            return ($sales * $rate) - ((float) $lease->base_rent_monthly * ($annual ? 12 : 1));
        }

        return ($sales - (float) ($lease->percentage_rent_threshold ?? 0)) * $rate;
    }

    /**
     * How much of a calendar year this lease was actually in force — 1.0 for a full year, 0.25 for
     * an October commencement, 0.0 for a year it never touched.
     *
     * **Counted in whole MONTHS, not days**, and that is a deliberate deviation from the commonest
     * legal wording (which pro-rates by days). Sales are declared per month: a lease commencing on
     * the 20th of October still files an October declaration covering that month, so the year holds
     * three months of reported sales and a day-share breakpoint would be measuring one thing against
     * the other. The grain of the breakpoint should match the grain of the sales it is compared to.
     *
     * The year is the CALENDAR year, matching `priorLockedSalesYtd()` and `retrueAnnualYear()`. That
     * is a separate contract question — some clauses run the percentage-rent year from the lease
     * anniversary — and it needs a per-lease setting rather than an assumption; recorded in module 09.
     * With proration in place, the two readings agree everywhere except the boundary month itself.
     */
    private function yearFactor(Lease $lease, int $year): float
    {
        if (blank($lease->commencement_date)) {
            return 1.0;
        }

        $yearStart = CarbonImmutable::create($year, 1, 1);
        $yearEnd = $yearStart->endOfYear();

        $from = CarbonImmutable::instance($lease->commencement_date)->startOfMonth();
        $from = $from->greaterThan($yearStart) ? $from : $yearStart;

        $to = filled($lease->expiry_date)
            ? CarbonImmutable::instance($lease->expiry_date)->endOfMonth()
            : $yearEnd;
        $to = $to->lessThan($yearEnd) ? $to : $yearEnd;

        if ($to->lessThan($from)) {
            return 0.0;
        }

        $months = ($to->year - $from->year) * 12 + ($to->month - $from->month) + 1;

        return min(1.0, max(0.0, $months / 12));
    }

    /**
     * Cumulative declared sales over the lease's LOCKED periods strictly BEFORE this declaration's
     * period in the same calendar year (chronological running total the annual overage builds on).
     * This declaration's own sales are added by the caller.
     */
    private function priorLockedSalesYtd(TenantSalesDeclaration $declaration): float
    {
        return (float) TenantSalesDeclaration::query()
            ->where('lease_id', $declaration->lease_id)
            ->where('status', 'locked')
            ->where('id', '!=', $declaration->id)
            ->whereYear('period_start', Carbon::parse($declaration->period_start)->year)
            ->whereDate('period_start', '<', $declaration->period_start)
            ->sum('declared_sales');
    }

    /**
     * The live (not cancelled/credited) percentage-rent overage currently invoiced for THIS period —
     * used by retrueAnnualYear to decide whether a month's invoice already matches its recomputed
     * marginal (skip, no churn) or must be reversed + rebilled.
     */
    private function billedOverageForPeriod(TenantSalesDeclaration $declaration): float
    {
        return (float) InvoiceItem::query()
            ->where('type', 'percentage_rent')
            ->whereHas('charge', fn ($q) => $q->where('lease_id', $declaration->lease_id)
                ->whereDate('start_date', $declaration->period_start))
            ->whereHas('invoice', fn ($q) => $q->whereNotIn('status', ['cancelled', 'credited']))
            ->sum('total');
    }

    /**
     * Re-true an annual lease's whole calendar year so the live overage invoices ALWAYS sum to
     * overage(cumulative year sales). Walks the locked months chronologically and reconciles each to
     * its canonical marginal (overage-through-this − overage-through-previous). Necessary because in
     * the cumulative model WHICH month carries the overage is an artifact of lock order: locking,
     * voiding, or re-locking one month shifts the cumulative the OTHER months were sized against, so
     * touching only the changed month would leave the year over- or under-billed (the exact stale-Feb
     * over-bill a plain void produced before this existed). Runs inside the per-lease mutex +
     * transaction. Reversing a month whose marginal DROPPED throws if that invoice was already PAID
     * (VoidInvoiceService) → the whole operation rolls back and is refused until it is refunded — the
     * same guard the single-period path already has.
     */
    private function retrueAnnualYear(int $leaseId, int $year): void
    {
        $lease = Lease::find($leaseId);
        if (! $lease instanceof Lease) {
            return;
        }

        $locked = TenantSalesDeclaration::query()
            ->where('lease_id', $leaseId)
            ->where('status', 'locked')
            ->whereYear('period_start', $year)
            ->orderBy('period_start')
            ->get();

        $runningPrior = 0.0;
        foreach ($locked as $decl) {
            $withThis = $runningPrior + (float) $decl->declared_sales;
            $marginalGross = round(max(0.0, $this->overage($lease, $withThis, $year)) - max(0.0, $this->overage($lease, $runningPrior, $year)), 2);

            // The cumulative stays GROSS — `$runningPrior` is SALES, and the marginal arithmetic
            // must not be perturbed by a clause about what the tenant already paid (see
            // netOfDeductions). Deductions come off afterwards, exactly as `calculate()` does it.
            $runningPrior = $withThis;

            // **This netting was missing until 2026-08-11.** `calculate()` applied it, this did
            // not, and this is the path that BILLS. A lease on the annual frequency with a
            // deductible-types clause was therefore charged GROSS while every screen — the
            // declaration, the breakdown, the estimate — showed net. The tenant's own contract said
            // the deduction was theirs.
            $marginal = $this->netOfDeductions($decl, $marginalGross);

            if ((float) $decl->calculated_percentage_rent !== $marginal) {
                $decl->update(['calculated_percentage_rent' => $marginal]);
            }
        }

        // Computing what each month OWES and deciding when it is CHARGED are two steps now.
        $this->settleBillingPeriods($lease, $year);
    }

    /**
     * Turn the months' owed figures into invoices, one per **billing period**.
     *
     * Splitting this out is what let billing frequency exist at all. It used to be fused into the
     * re-true loop, one invoice per month, so *when* overage was charged was not a term of the lease
     * but a property of the code — every tenancy billed monthly whatever its contract said. Yardi
     * carries reporting, billing and calculation basis as three separate settings, and the project's
     * own benchmark says a system conflating them "cannot express the most common retail deal".
     *
     * Both bases feed this: the annual basis writes canonical marginals above, the monthly basis
     * writes each month's own overage at lock. Either way this reads `calculated_percentage_rent`
     * and never recomputes it — the basis decides WHAT is owed, this decides WHEN it is raised.
     *
     * **In arrears, always.** A period is invoiced only once every month of it that the lease traded
     * has been locked; until then the figures stand and nothing is raised. That is what "quarterly in
     * arrears" means, and it is also the honest answer — a quarter cannot be settled while a month of
     * it is still unknown.
     *
     * The invoice is anchored on the period's FIRST locked month, which is what `reverseOverage()`
     * keys on, so re-locking or voiding any month of a period reverses that period's one invoice and
     * re-raises it at the new total.
     */
    private function settleBillingPeriods(Lease $lease, int $year): void
    {
        $locked = TenantSalesDeclaration::query()
            ->where('lease_id', $lease->getKey())
            ->where('status', 'locked')
            ->whereYear('period_start', $year)
            ->orderBy('period_start')
            ->get();

        $months = $lease->percentageRentBillingMonths();

        foreach ($locked->groupBy(fn (TenantSalesDeclaration $d): int => intdiv(Carbon::parse($d->period_start)->month - 1, $months)) as $group) {
            /** @var TenantSalesDeclaration $anchor */
            $anchor = $group->first();
            $billed = $this->billedOverageForPeriod($anchor);

            if (! $this->billingPeriodIsSettled($lease, $group, $year, $months)) {
                // Not due yet. Anything already raised for it must come back off — a month voided
                // out of a settled quarter un-settles that quarter.
                if ($billed > 0) {
                    $this->reverseOverage($anchor);
                }

                continue;
            }

            $target = round((float) $group->sum('calculated_percentage_rent'), 2);

            if (abs($billed - $target) < 0.005) {
                continue; // already invoiced correctly — leave it (and any payment) untouched
            }

            $this->reverseOverage($anchor); // throws on a PAID invoice → the whole operation rolls back
            if ($target > 0) {
                $this->billOverageForPeriod($anchor, $group->last(), $target);
            }
        }
    }

    /**
     * Has every month of this billing period that the lease actually traded been locked?
     *
     * Monthly cadence: trivially yes — the group is that one month, and it is locked by definition.
     * So this is a no-op for every lease that existed before billing frequency did.
     *
     * The clip to the lease term is what makes a part-traded quarter settleable: a lease commencing
     * in November owes for Nov–Dec of Q4 and is not waiting for an October it never traded.
     *
     * @param  Collection<int, TenantSalesDeclaration>  $group
     */
    private function billingPeriodIsSettled(Lease $lease, $group, int $year, int $months): bool
    {
        if ($months === 1) {
            return true;
        }

        $firstMonth = intdiv(Carbon::parse($group->first()->period_start)->month - 1, $months) * $months + 1;
        $periodStart = CarbonImmutable::create($year, $firstMonth, 1);
        $periodEnd = $periodStart->addMonths($months - 1)->endOfMonth();

        $from = filled($lease->commencement_date)
            ? CarbonImmutable::instance($lease->commencement_date)->startOfMonth()
            : $periodStart;
        $from = $from->greaterThan($periodStart) ? $from : $periodStart;

        $to = filled($lease->expiry_date)
            ? CarbonImmutable::instance($lease->expiry_date)->endOfMonth()
            : $periodEnd;
        $to = $to->lessThan($periodEnd) ? $to : $periodEnd;

        if ($to->lessThan($from)) {
            return false;
        }

        $expected = ($to->year - $from->year) * 12 + ($to->month - $from->month) + 1;

        return $group->count() >= $expected;
    }

    /**
     * Recalculate and persist `calculated_percentage_rent` on the declaration without locking.
     */
    public function recalculate(TenantSalesDeclaration $declaration): TenantSalesDeclaration
    {
        $declaration->calculated_percentage_rent = $this->calculate($declaration);
        $declaration->save();

        return $declaration;
    }

    /**
     * Lock a declaration: recalculate, persist, mark as locked, and create a one-off Charge
     * so the next monthly billing run picks the percentage rent up.
     *
     * Idempotent: locking an already-locked declaration is a no-op.
     */
    public function lock(TenantSalesDeclaration $declaration, User $lockedBy, ?string $auditNotes = null): TenantSalesDeclaration
    {
        if ($declaration->status === 'locked') {
            return $declaration;
        }

        // $txn returns the declaration and whether THIS call actually locked it (vs a racing no-op). The
        // tenant notification is sent AFTER the DB transaction commits (still inside the per-lease
        // mutex, but out of the transaction) so a slow/failing notification channel can't extend the
        // transaction — which holds row locks and must stay well under the lease lock's TTL.
        $txn = function () use ($declaration, $lockedBy, $auditNotes) {
            [$declaration, $locked] = DB::transaction(function () use ($declaration, $lockedBy, $auditNotes) {
                // Lock the row + re-check status INSIDE the txn so two concurrent locks (a
                // double-clicked / retried Filament action, or two staff) can't BOTH bill the overage.
                // Under MySQL REPEATABLE READ a non-locking read sees a stale pre-commit snapshot, so
                // both would reach billing → two issued invoices + two GL postings for one declaration.
                // Mirrors VoidInvoiceService / CamReconciliationService (the lock-safe pattern).
                $fresh = TenantSalesDeclaration::query()->lockForUpdate()->find($declaration->id);
                if (! $fresh || $fresh->status === 'locked') {
                    return [$fresh ?? $declaration, false]; // a racing request already locked it — no-op
                }

                $lease = $fresh->lease;
                $isAnnual = $lease instanceof Lease && $lease->percentage_rent_frequency === 'annual';

                $fresh->update([
                    // Annual: retrueAnnualYear writes each month's authoritative figure below.
                    // Monthly: this month's own overage.
                    'calculated_percentage_rent' => $isAnnual ? $fresh->calculated_percentage_rent : $this->calculate($fresh),
                    'status' => 'locked',
                    'locked_at' => now(),
                    'locked_by_user_id' => $lockedBy->id,
                    'audit_notes' => $auditNotes,
                ]);

                if ($isAnnual) {
                    // Re-true the WHOLE calendar year (this newly-locked month + every other locked
                    // month) so the live overage invoices always sum to overage(cumulative year sales),
                    // whatever order months were locked in.
                    $this->retrueAnnualYear($fresh->lease_id, Carbon::parse($fresh->period_start)->year);
                } elseif ($lease instanceof Lease) {
                    // Monthly BASIS: this month's own overage was written above. Settling is then the
                    // same step for both bases — it is the billing FREQUENCY, not the basis, that
                    // decides which invoice it lands on, and re-lock safety comes with it (a period
                    // whose total is unchanged is left alone, payment and all).
                    $this->settleBillingPeriods($lease, Carbon::parse($fresh->period_start)->year);
                }

                return [$fresh->refresh(), true];
            });

            // Even a zero charge is useful to the tenant (they were under-threshold). Out of the txn.
            if ($locked) {
                $this->notifyLocked($declaration);
            }

            return $declaration;
        };

        // Annual billing reads/adjusts the lease's OTHER declarations + invoices, so two concurrent
        // locks of DIFFERENT months of the SAME lease must not interleave (each would miss the other's
        // uncommitted charge under REPEATABLE READ and mis-bill). Serialize every lock/void for one
        // annual lease behind a per-lease mutex (Cache::lock, cf. the single-lease billing lock);
        // monthly leases are independent per month → no lock.
        return $this->runSerializedPerLease($declaration, $txn);
    }

    private function notifyLocked(TenantSalesDeclaration $declaration): void
    {
        $tenant = $declaration->lease?->tenant;
        if (! $tenant) {
            return;
        }

        try {
            $tenant->notifyPortal(new SalesDeclarationLockedNotification($declaration->refresh()));
        } catch (\Throwable $e) {
            \Log::warning('Sales declaration locked notification failed', [
                'declaration_id' => $declaration->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Void a previously-locked declaration: flip status to `disputed`, reverse the overage
     * (deactivate the anchor Charge AND cancel its immediate invoice, so the GL entry is
     * voided by the sweep), and stamp audit_notes with the reason. A PAID overage invoice
     * can't be voided — VoidInvoiceService throws, the txn rolls back, the void is refused.
     *
     * Idempotent: voiding a non-locked declaration is a no-op (the action
     * is UI-gated to status=locked, but we belt-and-braces in case a future
     * caller doesn't gate). Audit M12 F-48 / D-36.
     */
    public function voidLocked(TenantSalesDeclaration $declaration, User $voidedBy, string $reason): TenantSalesDeclaration
    {
        if ($declaration->status !== 'locked') {
            return $declaration;
        }

        $txn = fn () => DB::transaction(function () use ($declaration, $voidedBy, $reason) {
            // Lock the row + re-check inside the txn (same rationale as lock()): two racing
            // voids must not both run reverseOverage() and double-cancel / double-refund.
            $declaration = TenantSalesDeclaration::query()->lockForUpdate()->find($declaration->id);
            if (! $declaration || $declaration->status !== 'locked') {
                return $declaration; // already voided by a racing request — nothing to do
            }

            // Reverse the overage: deactivate the anchor Charge AND void its immediate
            // invoice (the overage was already billed at lock time). If that invoice has
            // been PAID, VoidInvoiceService throws — the void is refused until it's refunded.
            $this->reverseOverage($declaration);

            $existing = $declaration->audit_notes ? rtrim($declaration->audit_notes)."\n\n" : '';
            $stamp = now()->format('Y-m-d');
            $note = "Voided on {$stamp} by {$voidedBy->name}: {$reason}";

            $declaration->update([
                'status' => 'disputed',
                'audit_notes' => $existing.$note,
            ]);

            // Annual: this month's sales just left the cumulative, so the OTHER locked months — sized
            // against a running total that included it — must be re-trued, or the year stays over-billed
            // (the exact stale-invoice bug a per-period void produced). Re-truing may reverse another
            // month's now-too-large invoice; if that invoice is PAID it throws and the void is refused.
            $lease = $declaration->lease;
            $year = Carbon::parse($declaration->period_start)->year;

            if ($lease instanceof Lease && $lease->percentage_rent_frequency === 'annual') {
                $this->retrueAnnualYear($declaration->lease_id, $year);
            } elseif ($lease instanceof Lease) {
                // Monthly BASIS still needs the period re-settled whenever billing is not monthly:
                // the reverse above keys on THIS month, but a quarter's invoice is anchored on the
                // quarter's FIRST month, so voiding a middle month would otherwise leave the whole
                // quarter billed at a total that includes sales now withdrawn. Re-settling also
                // un-settles the period — a quarter missing a month is no longer due.
                $this->settleBillingPeriods($lease, $year);
            }

            return $declaration->refresh();
        });

        // Serialize with lock() on the same annual lease (see runSerializedPerLease): a void shifts
        // the cumulative that a racing lock reads, so both must run one at a time per lease.
        return $this->runSerializedPerLease($declaration, $txn);
    }

    /**
     * Run $txn either directly (monthly lease — each month is independent) or, for an ANNUAL lease,
     * inside a per-lease mutex so every lock/void for that lease serializes. The annual cumulative
     * delta is derived from the lease's other declarations + already-billed invoices, which two
     * concurrent operations would otherwise read stale (REPEATABLE READ) and under-bill. Blocks up to
     * 10s for the lock; the lock self-expires after 10s so a crashed holder can't wedge the lease.
     *
     * @param  callable():TenantSalesDeclaration  $txn
     */
    private function runSerializedPerLease(TenantSalesDeclaration $declaration, callable $txn): TenantSalesDeclaration
    {
        $lease = $declaration->lease;

        // Two reasons a lock is needed, and either is enough: an annual BASIS reads every other
        // month's sales, and a non-monthly BILLING period reads every other month's owed figure to
        // total the period. Only a monthly-basis, monthly-billed lease is genuinely independent per
        // month — anything else can interleave two locks and mis-bill under REPEATABLE READ.
        if (! $lease instanceof Lease
            || ($lease->percentage_rent_frequency !== 'annual' && $lease->percentageRentBillingMonths() === 1)) {
            return $txn();
        }

        return Cache::lock('pct-rent:lock:lease:'.$declaration->lease_id, 10)->block(10, $txn);
    }

    /**
     * Bill the percentage-rent overage IMMEDIATELY as its own issued invoice. The monthly
     * billing run can't reach a one_time charge dated to a past sales month, so — mirroring the
     * CAM positive true-up (billChargeImmediately) — we invoice it now. The Charge is kept as an
     * INACTIVE traceability anchor + the void/re-lock identity key (matched on
     * start_date = period_start); the money lives on the invoice, posting to the GL as
     * percentage_rent_revenue via the invoice item's `percentage_rent` type.
     */
    private function billOverageForPeriod(TenantSalesDeclaration $declaration, TenantSalesDeclaration $last, float $amount): Charge
    {
        /** @var Lease $lease */
        $lease = $declaration->lease;
        $now = now();

        // One invoice can now cover several declared months, so the label and the invoice period
        // span the BILLING period rather than the single month that anchors it. The anchor is still
        // the first month — `reverseOverage()` keys on it.
        $label = 'Percentage Rent — '.($declaration->is($last)
            ? $declaration->periodLabel()
            : $declaration->periodLabel().' – '.$last->periodLabel());

        $periodEnd = $last->period_end;

        // Overage rent follows base rent: it is rent, and rent is outside the scope of VAT. Read
        // from the catalogue so the charge, the invoice line and a hand-typed one agree.
        $vatRate = Vat::rateForType('percentage_rent');
        $vat = Vat::atRate($amount, $vatRate);
        $total = round($amount + $vat, 2);

        // Anchor: is_active=false so the monthly engine (which loads only active charges)
        // never re-bills it; dated to the sales period so void/re-lock match it.
        $charge = Charge::create([
            'lease_id' => $lease->id,
            'name' => $label,
            'type' => 'percentage_rent',
            'amount' => $amount,
            'currency' => 'EGP',
            'frequency' => 'one_time',
            'vat_rate' => $vatRate,
            'start_date' => $declaration->period_start,
            'end_date' => $periodEnd,
            'is_active' => false,
        ]);

        // Invoice period = the SALES period (the truthful period the overage covers), NOT
        // now(). That single-month span DOES fall inside MonthlyBillingService's "already
        // billed" window, so both of its idempotency probes explicitly exclude pure
        // percentage-rent overage invoices (whereDoesntHave items type=percentage_rent) —
        // otherwise a back-filled monthly run for this month would skip the base rent.
        $invoice = app(IssueInvoiceService::class)->issue(
            agreement: $lease,
            items: [[
                'charge_id' => $charge->id,
                'description' => $label,
                'type' => 'percentage_rent', // → percentage_rent_revenue in the GL journalizer
                'amount' => $amount,
                'vat_rate' => $vatRate,
                'vat_amount' => $vat,
                'total' => $total,
            ]],
            issueDate: $now,
            periodStart: $declaration->period_start,
            periodEnd: $periodEnd,
        );

        return $charge;
    }

    /**
     * Reverse a period's overage (used by void AND re-lock): deactivate the anchor Charge(s)
     * and void their immediate invoice. Matched by (lease, type, start_date = period_start) so
     * a sibling period is never touched. A PAID overage invoice can't be voided —
     * VoidInvoiceService throws, the caller's transaction rolls back, and the void/re-lock is
     * refused (the invoice must be refunded first).
     */
    private function reverseOverage(TenantSalesDeclaration $declaration): void
    {
        $charges = Charge::where('lease_id', $declaration->lease_id)
            ->where('type', 'percentage_rent')
            ->whereDate('start_date', $declaration->period_start)
            ->get();

        foreach ($charges as $charge) {
            $charge->update(['is_active' => false, 'end_date' => now()]);

            $invoice = InvoiceItem::where('charge_id', $charge->id)->latest('id')->first()?->invoice;
            if ($invoice && ! in_array($invoice->status, ['cancelled', 'credited'], true)) {
                app(VoidInvoiceService::class)->void($invoice, 'Percentage-rent declaration voided / re-locked');
            }
        }
    }
}
