# 03 — Yardi recoveries & percentage rent

> The two calculations a mall lives on, and the two Yardi is genuinely famous for. Confidence
> markings per the [README](README.md#sourcing--confidence).

---

# Part A — Recoveries (CAM / tax / insurance)

"Recovery" is Yardi's word for billing the tenant back for the landlord's operating costs. In a mall
this is CAM, and it is the calculation with the most lease-specific variation in the entire product.

## A1. The pipeline

```
GL expense accounts  ──►  RECOVERY POOL  ──►  gross-up  ──►  cap / base year  ──►  tenant share
   (actuals, posted)      (per property)      (variable         (per lease)        (numerator ÷
                                               expenses only)                       denominator)
                                                                                        │
                          ┌─────────────────────────────────────────────────────────────┘
                          ▼
        MONTHLY ESTIMATE charge (CAMEST)  ──►  billed all year  ──►  YEAR-END RECONCILIATION
                                                                            │
                                     ┌──────────────────────────────────────┤
                                     ▼                                      ▼
                        actual share > estimated billed          actual share < estimated billed
                             → true-up CHARGE                         → CREDIT
                                     │                                      │
                                     └──────────► RE-ESTIMATE next year's monthly charge ◄──┘
```

## A2. Recovery pools — sourced from the GL, not from a keyboard

*(cited: "Recovery pools define which expense GL accounts are included in each CAM category and are
set up under **Commercial > Setup > Recovery Pool Setup**. Each pool needs: expense accounts,
recovery type (pro-rata, direct, metered), and gross-up rules.")*

A pool is a **named set of GL expense accounts** on a property. Consequences:

- **The pool total is a query, not a number.** It is whatever those accounts actually hold at
  year end. Nobody re-keys it, so nobody can mis-key it, and every CAM charge **drills down to the
  vendor invoices that justify it** — which is exactly what a tenant challenges and what an
  audit clause entitles them to inspect.
- **Many pools per property.** CAM · real-estate tax · insurance · utilities · security ·
  HVAC · capital amortisation — each with a different recovery basis, a different set of
  participants and a different cap. A single-pool model cannot express "everyone shares CAM, but
  only the food court shares grease-trap cleaning".
- **Per-account fixed/variable classification** *(cited: "Variable vs. fixed classification is set
  per account — this drives gross-up eligibility.")*

## A3. Recovery types & the share formula

`tenant share = numerator ÷ denominator`, and Yardi lets both sides be chosen
*(cited: "varying numerators and denominators, base years, caps, gross-ups, management fees for
the terms defined in the lease")*.

| Recovery type | How the tenant's share is found |
|---|---|
| **Pro-rata by area** | tenant's rentable area ÷ a **chosen denominator** |
| **Fixed percentage** | a negotiated % written in the lease, regardless of area |
| **Fixed amount / rate** | a flat EGP or EGP/m²/yr, often with its own escalation |
| **Direct / metered** | actual measured consumption billed at cost |

**The denominator is the negotiated part**, and each choice moves money between landlord and tenants:

| Denominator | Who eats the vacancy |
|---|---|
| Total property **GLA** | the **landlord** — vacant space's share is unrecovered |
| **Occupied** area | the **sitting tenants** — they absorb the vacancy |
| Fixed / stated denominator | whoever the lease says; certainty for the tenant |
| Adjusted (e.g. excluding anchors) | anchors carved out, in-line tenants share the rest |

~~**Atriom is hard-coded to occupied area**~~ — **SHIPPED (RC-03).** `cam_expense_pools.denominator_basis`
offers `occupied` (the default, and the legacy behaviour) · `gla` · `fixed` with
`denominator_fixed_sqm`, resolved in `CamReconciliationService::resolveDenominator()`. A zone-scoped
pool on a `gla` basis divides by the ZONE's leasable area, not the centre's. The landlord can now
elect to absorb vacancy, and the choice is a pool field rather than an accident of the code.
Yardi's *adjusted* denominator (carve an anchor out of the denominator while it still participates)
has no direct equivalent — `participant_scope = area` expresses the zone case, not the carve-out.

## A4. Gross-up

If a property is 80% occupied, variable costs (cleaning, utilities, security hours) are lower than
they would be at full occupancy. Charging the occupied tenants their pro-rata share of the *actual*
low cost means the landlord recovers less than 100% of what those tenants consume. **Gross-up**
restates *variable* expenses to what they would have been at an assumed occupancy (95% or 100%),
then allocates on the same assumption. Fixed expenses are not grossed up — hence the per-account
classification in A2.

~~Atriom has no gross-up~~ — **SHIPPED (RC-04).** `gross_up_pct` restates the VARIABLE share of the
pool to an assumed occupancy; `variableShare()` reads the per-account `cost_nature` when the pool is
ledger-synced and falls back to a stated percentage otherwise. Occupancy is measured as the
participants' area over the denominator actually used — the same two numbers the shares divide, so
the gross-up cannot disagree with the apportionment it feeds. **Refused on an `occupied`
denominator**, where the shares already sum to 100% and grossing up would recover more than the
pool.

## A4b. Occupancy proration within the recovery year — **CLOSED 2026-08-17**

Yardi computes a tenant's recovery on the days they occupied **within the recovery period**: a lease
commencing 1 October carries roughly a quarter of a full-year share.

Atriom weights the area basis by the **`lease_unit` pivot window** — which is dated only when an
expansion or contraction is recorded, and is null on an ordinary lease. `totalAreaSqmForPeriod()`
then clamps to the whole period, so **a lease that commenced mid-year takes a FULL year's share.**

Measured on a 2026 pool of 500,000 with participants of 200 m² + 120 m², adding a 100 m² lease
commencing **1 October**:

| | share | cost |
|---|---|---|
| what Atriom produces | 23.81% | 119,048 |
| prorated (3 of 12 months) | ~7.25% | ~36,232 |

It over-recovers from the arriving tenant and under-charges the sitting ones, because the
denominator also counts the newcomer's full area. The mechanism is half-wired rather than absent:
the same function already day-weights a unit that was ADDED mid-year. The fix is to intersect the
held window with the lease's own commencement/expiry, not only the pivot dates — numerator and
denominator both narrow, so an `occupied` pool still sums to 100% and a `gla` pool correctly leaves
the empty months with the landlord.

The mirror case was the same defect: a lease that TERMINATED mid-year was excluded from the pool
altogether (only active leases were allocation targets), so the months it did occupy were recovered
from nobody.

**Both are fixed.** `totalAreaSqmForPeriod()` narrows the window by the lease's own term — clamping
the END only once the lease has ENDED, so a lease in holdover is not cut short — and
`CamReconciliationService::participants()` selects leases that OVERLAP the reconciled year instead
of leases that are `active` today. Numerator and denominator narrow together, so Σ allocated still
equals the pool and the shares still sum to 100%; `CamProratesPartYearOccupancyTest` pins that
alongside the two corrected shares, and both halves are proven by removal.

Yardi parity for recoveries is now complete except the **adjusted denominator** (carve an anchor out
of the denominator while it still participates), which has no equivalent here.

## A5. Caps, base years and expense stops

| Term | Meaning |
|---|---|
| **Cap** | the tenant's recoverable share may not rise more than X% per year; **cumulative** (unused headroom carries) or **non-cumulative**; **compounded** or simple; usually **controllable expenses only** (excludes tax, insurance, utilities) |
| **Cap base year** | the year the cap ladder is measured from |
| **Base year / expense stop** | the tenant pays only the *increase* over a base-year amount, or over a stated EGP/m² stop |

**Atriom already has most of this**, and the July 2026 competitor doc that says otherwise is stale:
`LeaseCamTerm` carries `cap_type` (absolute / YoY / both), `cap_absolute_amount`, `base_year`,
`base_year_amount`, `yoy_pct`, `compounding`, effective-dated per year, with the tighter ceiling
winning when both apply ([`LeaseCamTerm.php`](../../../app/Models/LeaseCamTerm.php)). The unrecovered
difference is explicitly absorbed by the landlord (`cap_absorbed`) rather than silently
redistributed — which is correct. ~~**Missing:** controllable-only cap scoping … and
cumulative/unused-headroom carry-forward.~~ **Both SHIPPED**: `LeaseCamTerm::cap_scope`
(`total` / `controllable`, with the pool's `controllable_pct` or its ledger-derived share setting
the carve-out) and `cap_carry_forward` for banked headroom.

## A6. Admin / management fee

A % added on top of the recovered pool, commonly 10–15%, sometimes only on certain pools and
sometimes excluded from the cap base. **Atriom has it** — `admin_fee_pct` per pool, applied to the
*capped* cost so it cannot re-breach the cap, VAT-rated per pool. Good.

## A7. Estimate → reconcile → **re-estimate**

*(cited: "Commercial > Recovery > Recovery Reconciliation, select the property, fiscal year, and
recovery pool… review estimated vs actual expenses, tenant share calculations, and generate
reconciliation statements." And separately: a documented procedure for **updating recovery estimates
after completing the CAM & tax reconciliation**.)*

The loop is three steps, and Atriom has two of them:

1. **Estimate** — a monthly `CAMEST` charge on the lease schedule, derived from the budget.
   *Atriom: the monthly `service_charge` on the lease serves this role. It is still a negotiated flat
   number rather than one derived from a CAM budget — but the second half of this paragraph is
   **stale (re-checked 2026-08-31)**. `estimate_basis = billed` derives each tenant's estimate from
   THEIR OWN invoices (`SyncCamPoolFromLedgerService::estimateBilledFor()`), so the estimate billed
   and the estimate reconciled are the same number by construction. It was described here as "the
   single weakest link" after the link had been closed.*
2. **Reconcile** — actual share vs estimated billed → true-up charge or credit, with a tenant-facing
   reconciliation statement showing the pool, the share basis and the arithmetic.
   *Atriom: yes, and well done — positive true-up bills immediately on its own recovery invoice
   (correct: the lease may already have ended), negative becomes a credit note auto-applied FIFO.*
3. **Re-estimate** — push next year's monthly estimate up to the newly-known actual run rate.
   *Atriom: **built**, and this row said "absent" until it was re-checked 2026-08-31.*
   `generateAllocations()` writes `proposed_monthly_estimate` (the CAPPED cost ÷ 12, so a capped
   tenant is never asked to pre-pay more than their own clause allows), the whole mall's proposals
   are reviewable side by side, and the **Apply estimates** action puts an accepted one on the
   lease's charge schedule through `ApplyCamEstimateService`. Proposing is deliberately not
   applying.*

## A8. The reconciliation statement

The tenant receives a statement that shows: the pool's total expense, the exclusions and gross-up,
the denominator, their numerator and share %, their gross share, the cap applied, the admin fee, the
estimate they were billed, and the resulting balance. Many leases give the tenant an **audit right**
against exactly this document.

*Atriom: **built**, and this paragraph said "there is no statement" until it was re-checked
2026-08-31.* `CamStatementPdfService` renders the tenant-facing statement, and
`CamReconciliationService::explainAllocation()` backs the operator's **View working** panel with the
same ladder — share → allocated → cap ceiling / capped / absorbed → estimate → true-up → recovery
VAT → admin fee + VAT → net invoiced. The cap section is omitted when no cap applied, so a statement
never carries a "cap: none" row nobody needs.

---

# Part B — Percentage rent (turnover rent)

## B1. The retail parameters on a lease

*(cited: "tenant retail parameters that allow you to easily define **Sales Types, Reporting and
Billing Frequencies and Breakpoints**, including an automatic calculation of Natural Breakpoints,
and an **Overage** function that automatically calculates and creates your percentage rent
charges.")*

| Parameter | Why it exists |
|---|---|
| **Sales categories/types** | food vs merchandise vs services often carry different rates; some categories (gift cards, inter-store transfers, VAT, returns) are **excluded** from reported sales entirely |
| **Reporting frequency** | when the tenant must declare (monthly is standard) |
| **Billing frequency** | when overage is charged — monthly, quarterly or **annually in arrears**. *These are different, and a system that assumes they are the same cannot express the most common retail deal.* |
| **Breakpoint** | natural (= minimum rent ÷ rate, auto-computed) or artificial (negotiated) |
| **Tiers** | a breakpoint ladder |
| **Calculation basis** | period-by-period, or **cumulative YTD** with a settle-up |
| **Deductions / offsets** | recoverable charges (RE tax, CAM, marketing) creditable against percentage rent |
| **Estimated sales** | what to bill when the tenant does not declare |
| **Sales audit** | reconciling declared sales to audited figures, retro-billing the difference |

## B2. Tiers

*(cited example: 0–500K at 0%, 500K–900K at 5%, above 900K at 6%.)* Each band applies only to sales
*within* it. Anchors and large-format tenants are almost always tiered; a single rate + single
threshold cannot represent them.

**Atriom: single rate, single threshold**, artificial or natural
(`PercentageRentCalculationService`). No tiers.

## B3. Period vs cumulative — the difference is real money

Retail is seasonal. Take an artificial breakpoint of 1,000,000/month at 8%:

| Month | Sales | **Period basis** overage | **Cumulative YTD** overage |
|---|---|---|---|
| Jan | 600,000 | 0 | 0 (YTD 0.6M vs BP 1.0M) |
| Feb | 700,000 | 0 | 0 (YTD 1.3M vs BP 2.0M) |
| … | … | | |
| Dec | 3,000,000 | 160,000 | depends on the full-year total |
| **Full year** | **12,000,000** | **160,000** | **0** (12.0M = 12 × 1.0M breakpoint) |

Same sales, same lease. **Period basis charges 160,000; cumulative charges nothing.** Which is
correct is what the lease says — and a system that only implements one of them cannot bill the
other correctly, ever.

**CORRECTED 2026-08-09 — Atriom implements BOTH.** `percentage_rent_frequency` on the lease is
`monthly` (breakpoint applied fresh each month) or `annual` (cumulative). On the annual basis each
month carries its **canonical chronological marginal** — `overage(cumulative through this month) −
overage(through the prior month)`, each floored at 0 — so the months sum exactly to the year's
overage, a seasonal spike is netted against the slow months, and no month can be negative.
`retrueAnnualYear()` re-attributes the whole year whenever a declaration is locked or voided, so
adding or removing a month keeps the live invoices summing to the cumulative truth. That is the
settle-up, done continuously rather than once at year end.

*The first version of this file said "period basis only, annual reconciliation DEFERRED". It was
wrong — read off a stale line in module 09 rather than the code.* The real open question is a
**data** one: the column defaults to `monthly` and no lease is currently set to `annual`, so any
tenancy whose clause settles annually is on the wrong setting until somebody reads the clauses.

## B4. Deductions and offsets

Many leases credit recoverable charges against percentage rent: *"percentage rent is payable to the
extent it exceeds CAM and RE tax paid in the same period."* Voyager marks charge codes as deductible
and nets them. Atriom has no notion of it.

## B5. Non-declaration

Voyager can bill on **estimated sales** when a declaration is missing, and retro-bill on the true
figure when it arrives. Atriom's `sales:scan-missing-declarations` **chases** the tenant — a good
operational answer — but never bills, so a tenant who simply never declares pays no percentage rent.

## B6. Occupancy cost — the analytic that matters

*(cited: "Retail Analytics provide up to the minute reporting on Sales, comparing MTD, YTD, Moving
Annual Total (MAT) and calculates the tenants Occupancy Costs.")*

**Occupancy cost % = (base rent + CAM + marketing + percentage rent + utilities) ÷ sales.** Above
roughly 15–20% (category-dependent) a retailer is in trouble, and a mall that watches this number
knows which tenants will default *before* they miss a payment. It is a single query over data
Atriom already holds — invoices and sales declarations — and it is arguably the highest
value-per-line-of-code item in this entire benchmark.

---

## Sources

- [Yardi Voyager Commercial CAM reconciliation setup — CapVeri](https://www.capveri.com/resources/software/yardi-voyager/cam-setup) — recovery pool setup path, expense accounts, recovery types, gross-up rules, fixed/variable per account, Recovery Reconciliation screen
- [Yardi Commercial Suite brochure](https://resources.yardi.com/documents/commercial-suite-brochure/) — numerators/denominators, base years, caps, gross-ups, management fees
- [YARDI VOYAGER Commercial Property Management Software](https://silo.tips/download/yardi-voyager-commercial-property-management-software) — retail parameters, natural breakpoint, Overage function, tiered example, MAT & occupancy cost
- [How to update recovery estimates after completing the CAM & tax reconciliation](https://www.iorad.com/player/1947362/How-to-update-recovery-estimates-after-complete-the-CAM---Tax-Reconciliation) — the re-estimate step
- [CAM reconciliations — Yardi blog](https://www.yardi.com/blog/news/cam-reconciliations/21634.html) · [Percentage rent model — Yardi blog](https://www.yardi.com/blog/news/percentage-rent-model/29164.html)
- Atriom side: [`CamReconciliationService`](../../../app/Services/CamReconciliationService.php), [`LeaseCamTerm`](../../../app/Models/LeaseCamTerm.php), [module 08](../../modules/08-cam.md), [module 09](../../modules/09-tenant-sales-percentage-rent.md)
