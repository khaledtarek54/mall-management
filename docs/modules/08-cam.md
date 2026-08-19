# CAM (Common Area Maintenance) Reconciliation

> **⚠️ A contractually stated share could over-recover the pool, silently (fixed 2026-08-19).** A
> lease whose contract names its percentage takes that percentage (RC-03), and the other
> participants keep their own area share — deliberately, because their leases say *"your pro-rata
> share"* and re-cutting them to cover a discount a third party negotiated would over-bill them
> against their own terms. A stated share BELOW the area share therefore recovers less of the pool,
> with the landlord bearing the difference; that is the behaviour `CamDenominatorTest` pins and it is
> unchanged.
>
> **The other direction was never considered.** Measured on four equal 250 m² shops with one stated
> at 40%: Σ shares **115%**, and **1,150,000 recovered against 1,000,000 of actual common cost** —
> tenants billed 15% more than the cost incurred, on the tenant-facing recovery invoice. Recovery is
> capped at actual cost in almost every service-charge clause, so that is a commercial and legal
> exposure rather than a rounding question.
>
> `generateAllocations()` now projects what the shares WOULD sum to before writing anything, and
> **refuses** when that exceeds 100%. The test is on the projected total, not on `Σ stated`: a single
> lease stated at 12.5% against a 2% area share over-recovers by 10.5% while the stated figures sum
> to well under 100 — a guard reading only the stated shares passed the very pool that failed.
> Refused rather than clamped, because scaling somebody's agreed percentage down is a decision no
> engine may take on its own.
>
> **And the tie-out that should have caught it could not.** The residual is stored as
> `actual − Σ allocated`, so `billing:reconcile`'s check of `Σ + residual == actual` is an identity
> the generator has just made true. (It is not a pure tautology — mutation-tested, it catches an
> allocation tampered with AFTER generation — but it cannot see an over-recovery the generator itself
> produced.) `BooksReconciliationService` now also compares Σ allocated against the pool expense
> **directly**, independently of the stored residual. Pinned by
> `CamStatedShareDoesNotOverRecoverTest`.


> Allocates annual CAM expenses pro-rata by leased area among tenants in a mall property, reconciles what was estimated vs. actual, and bills the shortfall (or credits overpayment) as one-off charges.

## 1. Purpose & business context

Mall owners (Jawad) collect estimated CAM charges monthly throughout a calendar year from tenants (retailers). Once the year ends, actual CAM expenses are reconciled against what was estimated. The difference (true-up) is allocated fairly to each tenant based on their proportional leased area.

**CAM pools** capture the total actual and estimated amounts per property per year. **CAM allocations** distribute that variance pro-rata to each lease that **occupied the property during the reconciled year** — weighted by the days it did. The system creates one-time charges on leases for any shortfall (tenant owes more) or credits for overpayment (tenant gets a credit). This ensures fair cost-sharing: a large retail tenant pays more than a small one, proportional to their footprint.

---

## 2. Domain model

### Tables & columns

| Entity | Model | Key columns | Notes |
|--------|-------|-------------|-------|
| **CAM Expense Pool** | `CamExpensePool` | `id`, `asset_id` (FK), `period_year` (YYYY, 2020–2099), `total_actual_expense` (decimal:2), `total_estimated_collected` (decimal:2), `admin_fee_pct` (decimal:6,4, **nullable** — a FRACTION, 0.10 = 10%; null ⇒ no fee), `admin_fee_on_net` (bool, default true), `status` (enum), `notes`, `reconciled_at`, `reconciled_by_user_id`, `created_at`, `updated_at`, `deleted_at` (soft-delete) | A container for one year's CAM data on one property. Unique on `(asset_id, period_year)`. Index on `status`. |
| **CAM Allocation** | `CamAllocation` | `id`, `cam_expense_pool_id` (FK), `lease_id` (FK), `pro_rata_share_pct` (decimal:7,4), `allocated_amount` (decimal:2, **UNCAPPED** — the tie-out basis), `estimated_paid` (decimal:2), `true_up_amount` (decimal:2, = `capped_cost − estimated`), `admin_fee_amount` (decimal:2, default 0), `admin_fee_vat_amount` (decimal:2, default 0), `cap_amount` (decimal:2, nullable — the resolved ceiling that applied), `capped_cost_amount` (decimal:2, nullable — `min(allocated, ceiling)`), `cap_absorbed_amount` (decimal:2, default 0 — landlord-absorbed excess), `exclusions` (JSON), `status` (enum), `billed_charge_id` (FK, nullable), `billed_credit_note_id` (FK, nullable), `billed_admin_fee_charge_id` (FK, nullable), `created_at`, `updated_at`, `deleted_at` | One per participating lease per pool. Unique on `(pool_id, lease_id)`. Index on `status`. |
| **Lease CAM Term** | `LeaseCamTerm` | `id`, `lease_id` (FK), `effective_year` (YYYY), `cap_type` (`absolute`/`yoy`/`both`), `cap_absolute_amount` (nullable), `base_year` (nullable), `base_year_amount` (nullable), `yoy_pct` (decimal:6,4, fraction, nullable), `compounding` (bool, default true), `notes`, `created_at`, `updated_at` | Effective-dated per-lease cap config. **HARD-delete** (forward-looking config, not a financial record) so a removed term frees its `(lease_id, effective_year)` unique slot for re-creation. Unique on `(lease_id, effective_year)`. `resolveCeiling(int $year)` returns the ceiling; `Lease::resolveCamCeiling($year)` picks the latest term ≤ that year. |

### Relationships

- **CamExpensePool**
  - `belongsTo(Asset)` — the property to which this pool belongs
  - `hasMany(CamAllocation)` — all allocations in this pool
  - `belongsTo(User, 'reconciled_by_user_id')` — the admin who marked it reconciled

- **CamAllocation**
  - `belongsTo(CamExpensePool, 'cam_expense_pool_id')` — parent pool
  - `belongsTo(Lease)` — the tenant's lease
  - `belongsTo(Charge, 'billed_charge_id')` — the one-time true-up charge created if billed

---

### Several pools per property-year (RC-02)

A property runs many recovery pools in a year — CAM, real-estate tax, insurance, utilities, a
food-court pool — keyed `(asset_id, period_year, pool_code)`. `pool_code` defaults to `cam` and
`participant_scope` to `all`, so every pool written before RC-02 is the property's CAM pool with the
whole property participating, exactly as before.

**A pool's participants come from `units.area_id`, not a lease list.** `participant_scope = area` +
`participant_area_id` expresses Yardi's own example — everyone shares CAM, only the food court shares
grease-trap cleaning — and re-answers on its own when a lease moves units.

**Membership is defined once — `CamExpensePool::participantLeaseQuery()`.** Two callers need it and
they must not drift: the reconciliation picks allocation targets with it, and the billed-estimate
query subtracts with it. They *did* drift until 2026-08-11 — the estimate query had no participant
filter at all, so a food-court pool subtracted the whole property's billing from the food court's
allocation.

Three things to keep right when touching it, or `CamReconciliationService::participants()`:

- **A zone pool on a `gla` denominator divides by the ZONE's leasable area.** The property's GLA
  would spread the pool across the whole centre and recover a few percent of its cost.
- **Participation reads the `lease_unit` pivot, not `leases.unit_id`.** A lease whose master shop is
  outside the zone but whose annexe is inside it still participates.
- **`active` belongs to the allocation side, not the membership predicate.** An allocation target
  must be a live lease, but a tenant who left in July still paid six months of estimate; filtering
  them out of the *estimate* would understate collections and over-recover from whoever is still
  trading. `participantLeaseQuery()` deliberately carries no status filter — `participants()` adds
  its own.

Each pool ties out on its own: `Σ allocated + landlord_unrecovered_amount = total_actual_expense`.

## 3. Business rules & invariants

### Core allocation formula
```
> **⚠️ A derived total says when it has never been sourced (2026-08-17).** `expense_basis = ledger`
> and `estimate_basis = billed` mean the column is COMPUTED from documents — but only when someone
> runs **Sync from ledger**. Until then it holds whatever it was created with, and `variance()`
> subtracts it regardless. Found on a live pool: `estimate_basis = billed` with
> `total_estimated_collected = 0` while its three tenants had been invoiced 346,000, so the list
> read *actual 500,000 · estimated 0 · variance 500,000* against a true variance of **154,000**. The
> allocations were right throughout — they derive each lease's estimate from its own invoices — so
> only the header lied, which is the harder kind of wrong to see. `CamExpensePool::needsSourcing()`
> (derived AND `expense_synced_at IS NULL`) now colours the estimate and variance columns danger with
> a tooltip, adds a toggleable **Sourced at** column, and puts the timestamp on the form. It reads
> `isDerived()` — the same predicate the Sync action's own visibility uses, so the warning cannot
> point at a pool the action would not offer. **It reports, it does not correct**: substituting a
> computed figure for the stored one would make the column and the screen disagree.
> *Limit, stated rather than implied:* this catches "never sourced", not "sourced in January and
> since gone stale" — that needs a per-row comparison against the newest contributing document.
> `CamDerivedTotalsSayWhenTheyAreStaleTest`. **Caught by an operator's instinct that the pool
> "wasn't configured right", which is not a detection mechanism anyone should rely on.**

> **⚠️ The share follows OCCUPANCY, not lease status (fixed 2026-08-17).** Yardi computes recovery on
> the days a tenant occupied within the recovery period, and this got it wrong at both ends of a
> lease, in opposite directions:
>
> - **Arriving:** the area basis weighted on the `lease_unit` pivot window, which is dated only when
>   an expansion or contraction is recorded and is **null on an ordinary lease**. A lease commencing
>   1 October drew a FULL year's share — on a 500,000 pool a 100 m² lease three months in took
>   **23.81% / 119,048** against a correct **~6.2% / ~30,883**. It over-recovered from the newcomer
>   *and* under-charged everyone else, because the denominator counted their full area too.
> - **Leaving:** `participants()` filtered on `status = active`, so a tenant who traded January to
>   August and left was not an allocation target at all — the common-area cost of the months they DID
>   occupy was recovered from **nobody** and fell to the landlord silently. The old comment in that
>   method had already noticed the asymmetry ("a departed tenant's estimate is still part of what the
>   pool collected") without drawing the conclusion: if their estimate counts, so must their share.
>
> `Lease::totalAreaSqmForPeriod()` now narrows the window by the lease's own term via
> `occupancyWindow()`, and `participants()` selects leases that OVERLAP the year. The END is clamped
> only once the lease has **ended** — an `active` lease past its expiry is in **holdover**, still
> trading, and clamping it would hand its months to nobody. Numerator and denominator narrow
> together, so Σ allocated still equals the pool and the shares still sum to 100%.
> `CamProratesPartYearOccupancyTest`, both halves proven by removal.
>
> *(Found by the Chapter 11 walkthrough measuring the live system against Yardi, not by a red test —
> and `CamAnnualReconciliationCommandTest` had been green over a fixture that billed 2025's CAM to
> two leases commencing in 2026.)*

pro_rata_share_pct = (lease.totalAreaSqm() / total_leased_sqm) * 100   # ALL units on the lease
allocated_amount    = pro_rata_share_pct% * pool.total_actual_expense
estimated_paid      = pro_rata_share_pct% * pool.total_estimated_collected
true_up_amount      = allocated_amount - estimated_paid
```

**In words**: Each lease's share of the pool's actual and estimated amounts is weighted by its **total** leased area — summed over every unit on the lease via the `lease_unit` pivot, not the master unit alone — as a fraction of the asset's total leased sqm. True-up is the difference: positive (tenant under-paid), negative (tenant over-paid).

**The share denominator is a lease TERM now, not a convention (2026-08-09, story RC-03).**
It was hard-coded to the summed area of whoever happens to be trading, which recovers 100% of the
pool from them — so **a half-empty mall billed its remaining tenants for the whole service charge**.
Some leases say exactly that; many say share of GROSS leasable area.

- `denominator_basis = occupied` — the legacy basis and the **column default**, so no existing pool
  moves.
- `= gla` — the property's `leasable_area_sqm` (falling back to `Asset::totalUnitAreaSqm()`), so
  vacancy stays with the landlord.
- `= fixed` — a contractually pinned m². **Falls back to occupied when unset** rather than
  recovering nothing: a mis-typed pool should reconcile the way it always did, not silently
  allocate zero to everyone.
- `lease_cam_terms.stated_share_pct` — the per-lease override, for the many Egyptian leases that
  simply name the percentage. A stated share beats any derived one, and it does **not** inflate its
  neighbours: the others keep their area-derived shares and less of the pool is recovered.
- `unit_ownerships.assessment_basis` — **the same question for a SOLD unit** (2026-08-19, F-03). A
  handed-over ownership is an ordinary pool participant ([module 37 §7b](37-unit-owners.md)), and
  `participation` / `stated` both name a percentage of the pool exactly as a lease's stated share
  does — so they go through this same path and the same over-recovery guard. `area` is the default
  and changes nothing; `purchase_value` re-cuts the purchase-value owners among themselves and is
  aggregate-neutral, so no lease ever moves because a neighbouring unit was sold.

**Stated shares may not promise away more than the pool (2026-08-19, pre-staging QA F-08).** The
guard is on the TOTAL that WOULD be allocated, not on `Σ stated` — a single lease stated at 12.5%
against a 2% area share over-recovers by 10.5% while the stated figures sum to well under 100. It is
a **refusal**, not a clamp: scaling somebody's agreed percentage down is not a decision an engine may
take, and billing them all in full is the over-recovery itself. `billing:reconcile`'s CAM tie-out now
compares Σ allocated against the pool expense DIRECTLY rather than against the residual the generator
wrote, which is why it can fail at all — see `ReconciliationChecksCanFailConformanceTest`.

**Caps match the clause (2026-08-09, story RC-07).** Two refinements over "cap the whole share":

- **`cap_scope = controllable`** caps only the costs the landlord can manage. Most clauses carve out
  rates, insurance and utilities — a landlord cannot be asked to absorb a levy it does not set — so
  the uncontrollable part passes through ABOVE the ceiling. Capping everything was more protective
  than the contract, and the landlord absorbed money it was entitled to recover.
- **`cap_carry_forward`** makes the cap cumulative: a year under the ceiling banks the difference and
  a later spike draws on it.

**Controllable is a THIRD axis, not the fixed/variable one from RC-04.** A security contract is
fixed AND controllable; utilities are variable and NOT controllable; insurance is fixed and not
controllable. Conflating them caps the wrong half of the pool. The share comes from
`controllable_pct`, or per-account from `cam_pool_accounts.is_controllable` (default TRUE, so a
controllable-scoped cap on an unclassified pool behaves exactly like the unscoped cap it replaces).

**Headroom is read from the ALLOCATIONS, never recomputed from the terms.** A cap renegotiated in
year three must not retroactively change what year one banked, and the allocation is the record of
what the tenant was actually billed under (`cap_headroom_banked` / `cap_headroom_used`). Headroom
already drawn is netted off, so **a single cheap year cannot subsidise every spike that follows it**
— that double-spend is the trap, and it is pinned.

`cap_scope` defaults to `total` and `cap_carry_forward` to false, so no existing term changes.

**Gross-up (2026-08-09, story RC-04).** A GLA denominator leaves vacancy with the landlord — right,
but it over-corrects on the VARIABLE half: a mall at 40% occupancy spends less on cleaning and
common-area power than a full one, while its trading tenants consume those services at full
intensity. Left alone they pay 40% of a deflated number, which is LESS than they would pay in a busy
mall.

    basis = fixed + variable × (gross_up_pct ÷ actual occupancy)

`gross_up_pct` is the assumption (typically 95%); the variable share comes from `variable_pct`, or
per-account from `cam_pool_accounts.cost_nature` when the expense is ledger-sourced. The applied
basis is stored as `grossed_up_expense` so the tenant statement replays it. Occupancy is measured as
participants' area ÷ `denominator_used_sqm` — the same two numbers the shares divide, so the
gross-up can never disagree with the apportionment it feeds.

Four rules, each with a test:

- **Only when the denominator includes vacancy.** Under `occupied` the shares already sum to 100%,
  so grossing up would bill tenants MORE than the landlord spent. Refused, not silently applied —
  the form hides the field there too.
- **Never recovers more than was spent.** Algebraically `occupancy × fixed + variable × assumption
  ≤ pool` for any assumption ≤ 100%, and the most aggressive settings the form allows are pinned.
- **Never scales DOWN.** A centre fuller than the clause contemplated already shows that in the
  tenants' own shares; scaling down would be a discount the lease never promised.
- **`cost_nature` defaults to FIXED** — deliberately the opposite of `App\Support\CostNature`,
  whose default is `variable`. There, "variable" is the CONSERVATIVE reading of an unclassified cost
  (not a committed obligation). Here the same word is the AGGRESSIVE one: it grosses the account up
  and charges tenants more. The safe default flips with the question being asked, and an
  unclassified account must never quietly raise every bill the day gross-up is switched on.

**`landlord_unrecovered_amount` is what keeps the books honest — read this before touching either.**
`Σ allocated_amount = total_actual_expense` is a hard tie-out in `BooksReconciliationService`, and
it silently encoded *"the pool is always fully recovered"*, which is true only under `occupied`.
Under `gla` the shares deliberately sum to less than 100%. The remainder is therefore STORED on the
pool, and the check became **`Σ allocated + landlord_unrecovered = total`**. It is 0.00 on every
`occupied` pool, so the check is byte-identical where nothing changed.

Two consequences worth keeping:

- A **negative** value means the pool OVER-recovered — stated shares summing past 100%. That is a
  data problem worth seeing, so it is recorded rather than clamped to zero.
- `denominator_used_sqm` records the denominator that was actually applied, which is what the
  tenant statement reports. Re-deriving it later would show today's number, not the one used.

`CamDenominatorTest` verifies the tie-out on all three bases **through the real
`BooksReconciliationService`**, not a re-implementation — a GLA pool under the old check read as
drift, and a books report that cries wolf on every mall with a vacancy is one people switch off.

**The tenant gets a statement they can audit (2026-08-09, story RC-06).**
`CamStatementPdfService` renders one bilingual PDF per allocation, downloadable from the admin
allocation list **and by the tenant in the portal** — almost every commercial lease grants a
service-charge audit right, and the answer to "show me how you got this" used to be an invoice line
reading "CAM Recovery 2028".

Five sections: what the mall spent **and how that figure was arrived at** (the ledger accounts
behind it, or that it was typed); how much of it is yours (your area, **the denominator used**, your
share); the cap and what the landlord absorbed; the settlement against estimates already paid; and
next year's proposed estimate — telling the tenant the new monthly figure on the same document that
explains why it changed is the difference between a re-estimate and a surprise.

Two rules keep it honest:

- **Every figure is READ from the allocation, never recomputed.** A statement that re-derived its
  numbers would silently disagree with the invoice it explains the moment anything downstream moved
  — an area edit, a new participant, a re-run. `pro_rata_share_pct` and the capped/absorbed amounts
  are stored precisely so the arithmetic can be replayed years later. The **denominator is recovered
  from the stored share** (`area ÷ share`) for the same reason: it is the denominator that was used,
  not the one that would be computed today.
- **The cap section is omitted when no cap applied.** A "cap: none" row on every statement in the
  mall trains everyone to skip the section that matters on the few where it bites.

`CamStatementTest` asserts the FACTS through the public `facts()` seam rather than the rendering —
a test that only checks the PDF is non-empty passes just as happily when every number on it is
wrong. One rendering test covers both locales, because an RTL font failure is real and silent.

**The pool's two totals are DERIVED now, not typed (2026-08-09, stories RC-01/RC-05).**

- `expense_basis = ledger` → `total_actual_expense` is the sum of POSTED journal lines on the
  accounts attached via `cam_pool_accounts`, for this pool's property and year, **debits less
  credits** (a vendor credit note reduces the pool rather than being recovered from tenants). Run by
  `SyncCamPoolFromLedgerService`, which **writes** the total rather than exposing a live query: a
  bill that arrives in March for December must not silently restate allocations already billed to
  tenants. A `reconciled` or `closed` pool refuses to re-source.
- `estimate_basis = billed` → `estimated_paid` on each allocation is **what that lease was actually
  invoiced under THIS pool's charge codes** during the year, not a pro-rata slice of a hand-typed
  portfolio figure. This is what closes the loop: the estimate reconciled and the estimate billed
  become the same number by construction rather than by diligence. `cam_recovery` and
  `cam_admin_fee` can never be estimate codes — they are the RESULT of a reconciliation, and
  counting them would let last year's true-up inflate this year's estimate.

  **Which codes those are is per-pool (`estimate_charge_codes`), and it must be.** Until 2026-08-11
  it was one global constant, `ESTIMATE_ITEM_TYPES = ['service_charge']`, consulted by every pool on
  every property — so a mall running a `cam` pool *and* a `tax` pool for the same year had both
  subtract the tenant's entire year of billed service charge. The tax pool reconciled to
  (allocated 20,000 − estimate 100,000) = **−80,000**, issued a credit note, and auto-applied it FIFO
  against live AR. `BASIS_BILLED` was the form default, so the risky basis was the one you got, and
  `SeveralRecoveryPoolsTest` pinned every fixture to `BASIS_STATED`, so nothing saw it.

  The constant survives as the FLOOR for an undeclared **`cam`** pool — which is what every row
  written before the column is — and for nothing else. Any other pool on the billed basis that has
  not named its codes is **refused** (`DomainException`), not defaulted: the billed basis is a claim
  about what was billed, and a pool that cannot say what it bills has no business making it. Same
  floor-and-refuse shape as `Vat::EXEMPT_TYPES`. In the annual sweep the refusal logs
  `cam.pool_failed` and the run continues, so one undeclared pool cannot stop the others — it simply
  does not reconcile, which beats reconciling wrongly.

**Both bases default to `stated` on the COLUMN**, so every pool that already exists reconciles on
exactly the basis it was reconciled against; only a NEW pool is created on the derived bases (the
same split-default pattern as `Lease::$fit_out_scope`). `CamPoolFromLedgerTest` pins that, because
getting it wrong restates closed years.

**Next year's estimate is proposed, then applied (RC-05).** `generateAllocations()` writes
`proposed_monthly_estimate = capped_cost ÷ 12` — the **capped** cost, because a capped tenant must
not be asked to pre-pay an amount their own clause forbids the landlord to charge.
`ApplyCamEstimateService` opens the new `service_charge` schedule row effective **1 January of the
following year**, records it as a lease event, and is idempotent (`estimate_applied_at`). Proposing
and applying are separate acts: an operator who disagrees never applies, and the proposal stays on
the record as what the arithmetic suggested. Leases that have ended are skipped rather than having
their billing resurrected.

Before this, nothing ever moved the monthly estimate — so the reconciliation discovered the same
shortfall every single year.

**The area is TIME-WEIGHTED over the pool year** (`Lease::totalAreaSqmForPeriod()`, 2026-08-09).
A tenant who took an extra 300 m² on 1 November has not occupied it for the year and must not carry
a year of its CAM; a tenant who handed 300 m² back in July must still carry the half-year they had
it. Both sides of the fraction use the same basis, so the tie-out is untouched. **For every lease
whose `lease_unit` rows carry no dates — all of them until an expansion or contraction is recorded
— this is arithmetically identical to the flat total**, which is what makes the change safe for
existing pools; `LeaseSpaceChangeTest` pins that equality.

**…and it weights the MEASUREMENT as well as the tenure** (2026-08-11). Time-weighting originally
asked only "how long did this lease hold the unit", reading `units.area_sqm` — the denormalised
*current* measurement — for what the unit measured. So a shop remeasured after a year ended
apportioned that year on the new figure: unit-area versioning existed, and CAM never reached it,
because `totalAreaSqmForPeriod()` was the undated sibling of `totalAreaSqmOn()` and CAM calls the
former. Both halves now compose through `Unit::areaSqmDaysBetween()`, which returns **m²·days** so
tenure and measurement weight together without rounding in between — a wall that moved in July
splits the year between two measurements. The GLA/zone denominator is weighted the same way, so
numerator and denominator answer the same question about the same year.

> **Why no existing test saw it.** When every unit moves together the shares still sum to 100%, so
> `Σ(allocated) = total_actual_expense` stayed green and the tie-out saw nothing — it is the
> DISTRIBUTION between tenants that was wrong. That is precisely the trap `Lease::totalAreaSqm()`'s
> docblock warns about: *assert the SHARE*. `CamApportionsOnThePeriodsAreaTest` does, and keeps the
> tie-out assertion alongside it as a recorded example of coverage that cannot see the defect.
>
> The re-run path was never affected — it freezes each allocation's stored `pro_rata_share_pct` — so
> this only ever bit the **first** reconciliation of a past year. That is also the run that bills.

**The one input still undated:** `Asset.leasable_area_sqm`, used by the property-wide GLA
denominator, is a single operator-maintained column with no history.

**Decision 2026-08-11: dating it is NOT worth a register, and here is the measurement behind that.**
The obvious next move was an `asset_areas` table in the shape `unit_areas` already has. Against it:

- `denominator_basis` **defaults to `occupied`**, and no seeded or demo pool uses `gla` — so the
  exposure today is zero pools, not "a few";
- a property's gross leasable area changes when a mall is extended or re-surveyed, not on every
  fit-out. It is nothing like a shop's area, which was the case worth fixing;
- **the denominator is already frozen and recorded.** `denominator_used_sqm` is written on the
  pool's first reconciliation and reused verbatim on every re-run, so what the pool divided by is
  auditable after the fact and cannot drift afterwards. The window is one first run, and it leaves
  evidence.

A whole register — table, model, service, screen, tests — to date a number that changes every few
years, on a basis nothing currently uses, whose value is already recorded when used, is cost without
a defect behind it. Revisit if the operator moves a pool onto the GLA basis **and** the property's
GLA changes; at that point the register is a day's work and this note is the reason to do it.

**Every other reader of `area_sqm` was checked, and most are correct as they stand.** The register
answers "what did it measure THEN"; a rent roll, an occupancy percentage, the unit table, the
exports and the `/api/v1` unit payload all ask "what does it measure NOW", and rightly read the
denormalised column. Two did not, and were fixed alongside CAM: the **empty-pivot fallback** inside
`totalAreaSqmForPeriod()` itself (legacy leases with no `lease_unit` rows — the oldest data, least
likely to have been remeasured on purpose), and the dashboard's **sales density**, which divided a
declared month's sales by the MASTER unit's area today — wrong on both counts, and it reorders the
ranking operators read.

**Tests guard**:
- `CamScenarioTest::it('generates one pro-rata allocation per active lease...')` — core math
- `CamScenarioTest::it('allocated amounts and shares sum to the pool total...')` — sums partition 100% and exact pool totals (subject to rounding)

### Rounding & precision
- Pro-rata share is rounded to **4 decimal places** (`decimal:7,4`).
- Allocated and true-up amounts are rounded to **2 decimal places** (`decimal:2`).
- Sub-cent rounding residuals are acceptable (max ±1 cent total per pool).

**Test guard**: `CamScenarioTest::it('leaves only a sub-cent rounding residual...')`.

### Scope — only active leases of the pool's asset
- Only leases with `status = 'active'` are eligible.
- Only leases whose `unit.asset_id` matches the pool's `asset_id` are included.
- Draft or inactive leases are silently skipped (count 0, contribute 0 sqm).
- If total leased area is ≤ 0, the service returns 0 allocations (no-op).

**Test guard**: `CamScenarioTest::it('only allocates to leases of the pools own asset...')`, and `it('generates zero allocations...')`.

### Idempotency & the skip-billed guard
- Calling `generateAllocations()` twice on the same pool **must not duplicate** allocations.
- Used via `firstOrNew(['pool_id', 'lease_id'])` — reuses an existing allocation if found.
- **Critical**: If an allocation exists and its status is not `'pending'` (e.g., already `'billed'`), the generation pass **skips it** — does not touch or re-count it.
  - This prevents resetting a `'billed'` allocation back to `'pending'`, which would allow double-billing the same true-up.

**Test guard**: `CamAllocationDoubleBillTest::it('does not reset an already-billed allocation...')` (regression). `CamScenarioTest::it('re-generating allocations updates in place...')` and `it('re-generating after the pool expense changes...')` (positive).

### Only pending allocations can be billed
- A `CamAllocation` can only transition to `'billed'` if its status is `'pending'`.
- Once billed, `bill()` becomes idempotent — re-calling it returns the same allocation and its backing record, never a second one.

### Positive true-up → charge; negative true-up → credit note
- A **positive** true-up (tenant under-paid) creates a one-off `Charge` on the lease (`billed_charge_id`) and settles it immediately on a dedicated recovery invoice. That invoice's line item is typed **`cam_recovery`**, so the General Ledger books it to **CAM Recovery Revenue** (`cam_recovery_revenue`, إيرادات استرداد المصروفات المشتركة) rather than generic misc income. (The `Charge` itself stays `type='other'` — it is a non-billed, non-journalized traceability anchor; see [module 21](21-general-ledger.md).)
- A **negative** true-up (tenant over-paid) creates an **issued `CreditNote`** on the tenant's account (`billed_credit_note_id`) — *not* a negative charge. A negative charge could drive a January invoice total negative, which `Invoice::recomputeTotals()` floors to 0, silently losing the credit. The credit note preserves it and settles future AR via the normal credit-apply flow.
- The books reconciliation (`BooksReconciliationService`, CAM check) accepts a billed allocation backed by **either** a charge, a credit note, **or** (zero true-up + fee owed) the admin-fee charge.

**Test guards**: `CamScenarioTest::it('re-billing an already-billed allocation is a no-op...')`, `CamScenarioTest::it('billing a negative-true-up allocation issues a credit note...')`, and `Regression/CamNegativeTrueUpCreditTest` (large credit preserved + books tie out).

### Admin fee — a SIBLING of the true-up, never folded into it
The recovery-clause engine adds the routine **management fee** the landlord charges on top of the recovered pool (operator default **10%**, VAT at the settings-driven standard rate — 14% today), the first bookable-revenue clause. It is deliberately built as a *sibling* of the true-up, not a modifier of it:

- **`admin_fee_amount = admin_fee_pct × capped_cost`** (the *net* cost the tenant bears — see the caps rule below; with no cap `capped_cost = allocated_amount`, so the fee is on the full recovered cost share, not the true-up) and **`admin_fee_vat_amount = admin_fee_amount × 0.14`**, both computed in `generateAllocations` and stored on the allocation. `allocated_amount` — the value the books-check ties out against `total_actual_expense` — is **never** touched by the fee, so the pool's cost pass-through still balances exactly.
- The fee bills as its own invoice line, typed **`cam_admin_fee`**, which the GL routes to a **dedicated `cam_admin_fee_revenue` account** (`41108001`, إيرادات رسوم إدارة المصروفات المشتركة) — margin the landlord *sells*, kept distinct from the cost pass-through (`cam_recovery_revenue`). It rides the **existing `Invoice` GL source** (no new `LedgerPoster` journalizer).
- **Where it lands** depends on the true-up sign: a **positive** true-up carries the fee as a *second line on the same recovery invoice*; a **negative** (credit note) or **exactly-zero** true-up bills the fee on its *own fee-only invoice* (a credit note can't carry a positive fee, and the fee is owed regardless of estimate accuracy). Either way an `is_active=false` anchor charge is recorded in `billed_admin_fee_charge_id` for traceability + the books-check.
- **`admin_fee_pct` null ⇒ no fee**, byte-identically to before the clause existed (guarded by `Regression/CamNoClauseParityTest`, the keystone — if it ever needs relaxing, the fee stopped being a no-op for pass-through-only malls). `admin_fee_on_net` (default true) is reserved for the gross-up slice and has no effect until then.

**Test guards**: `Regression/CamAdminFeeTest` (10% + VAT across positive/negative/zero, real `bill()` + real `accounting:sync-ledger` sweep, trial balance ties out, fee lands in `41108001`), `Regression/CamNoClauseParityTest` (no-clause parity).

### Caps — a ceiling on the true-up + fee base, never on the allocation
A lease can carry an effective-dated **`LeaseCamTerm`** capping how much CAM cost its tenant bears in a year — the controllable-expense ceiling anchor tenants negotiate. The cap applies to the COST path only:

- **`capped_cost = min(allocated_amount, ceiling)`**; **`true_up = capped_cost − estimated_paid`**; the admin fee is charged on the **capped** cost (`admin_fee_amount = admin_fee_pct × capped_cost`) so it can never re-breach the cap.
- **`allocated_amount` is NEVER capped** — it stays the tenant's raw share of `total_actual_expense`, which is exactly what the `BooksReconciliationService` CAM check ties out (`Σ allocated = total_actual_expense`). The capped excess is recorded as **`cap_absorbed_amount = allocated − capped_cost`** — the landlord's auditable cost of the cap (revenue it chooses to forgo, not a GL expense).
- **A cap can flip a positive true-up to a credit**: if the ceiling falls below what the tenant already paid in monthly estimates, `true_up` goes negative and settles through the normal credit-note path — while the admin fee (a positive amount on the capped cost) still bills on its own fee-only invoice.
- **The ceiling** comes from `Lease::resolveCamCeiling($year)`, which selects the effective-dated term with the greatest `effective_year ≤` the reconciled year, then `LeaseCamTerm::resolveCeiling()`: **absolute** (a flat EGP ceiling), **yoy** (`base_year_amount` grown by `yoy_pct`, compounding or simple, over `year − base_year`), or **both** (the tighter/`min` of the two). **No term ⇒ null ceiling ⇒ `capped_cost = allocated`**, byte-identical to the no-cap slice.

**Where operators set it**: the *CAM Cap Terms* relation manager on the Lease resource (write-gated on `leases.edit`, delete super_admin-only).

**Test guards**: `Regression/CamCapTest` (absolute / compounding-YoY / both=min ceilings, `allocated` stays uncapped, cap-flips-to-credit, effective-dating, real books tie-out with a cap), plus the no-term parity case.

### Variance definition
```
pool.variance() = total_actual_expense - total_estimated_collected
```
- Positive → under-collected (sum of all true-ups > 0; tenants will owe).
- Negative → over-collected (tenants get credits).
- Zero → perfect match.

**Test guard**: `CamScenarioTest::it('variance() reports actual-minus-collected...')`.

---

## 4. Lifecycle / state machine

### Pool statuses

| Status | Meaning | Allowed transitions | Terminal? |
|--------|---------|-------------------|-----------|
| `draft` | Created, not yet reconciled. May edit expense figures. | → `reconciling` (manual via UI; or auto-generate) | No |
| `reconciling` | Allocations have been generated; awaiting manual review/billing. | → `draft`, → `reconciled` | No |
| `reconciled` | Admin marked it fully reconciled. All allocations are ideally billed. | → `closed` | No (but typically final) |
| `closed` | Archive/final state. | None | Yes |

**Triggers**:
- Create → `draft` (default)
- Admin clicks "Generate Allocations" button → calls `generateAllocations()` and bumps pool to `reconciling`
- Admin clicks "Mark Reconciled" button → flips to `reconciled`, records `reconciled_at` timestamp and `reconciled_by_user_id`
- CLI `cam:reconcile --auto-bill` → auto-generates, auto-bills, and if allocations exist, flips to `reconciled` directly

### Allocation statuses

| Status | Meaning | Transition source | Terminal? |
|--------|---------|-------------------|-----------|
| `pending` | Generated but not yet billed; a Charge has not been created. | `generateAllocations()` | No |
| `billed` | A Charge (`type='other'`) has been created and linked. | `bill()` | No (but typically final) |
| `disputed` | Tenant or owner has disputed the true-up amount. | Manual (admin action, not implemented here) | No |
| `closed` | Archive. | Manual (admin action, not implemented here) | Yes |

---

## 5. Services, jobs & scheduled commands

### `CamReconciliationService`

#### `generateAllocations(CamExpensePool $pool): int`

**Signature**: Generates one pending allocation per active lease in the pool's asset, weighted by leased sqm.

**Returns**: Count of allocations created/updated (not including skipped billed ones).

**Behaviour**:
1. Queries all `Lease` records with `status='active'` and `unit.asset_id = $pool->asset_id`, pre-loading `unit` **and `units`** (the pivot).
2. Sums leased sqm via `Lease::totalAreaSqm()`. If ≤ 0, return 0 (no-op).
3. For each lease (within a DB transaction):
   - Calculate pro-rata share = `(lease.totalAreaSqm() / total_sqm) * 100`, round to 4 decimals.
   - Calculate allocated = `round(pool.total_actual_expense * share, 2)`.
   - Calculate estimated = `round(pool.total_estimated_collected * share, 2)`.
   - Calculate true_up = `round(allocated - estimated, 2)`.
   - Use `firstOrNew(['pool_id', 'lease_id'])` to reuse or create.
   - **If allocation exists and status ≠ 'pending', skip (do not touch).**
   - Otherwise, fill and save with these amounts + `status='pending'`.
   - Increment counter.
4. Commit transaction.

**Idempotency**: Yes. Running twice on the same pool updates in-place; never duplicates. Skips non-pending allocations (billed, disputed, closed).

**Locking**: Wrapped in `DB::transaction()` for consistency.

**Common use**: Manual admin action or automated CLI.

#### `bill(CamAllocation $allocation): CamAllocation`

**Signature**: Creates a one-time Charge on the lease for the allocation's true-up amount.

**Returns**: The updated allocation (refreshed from DB).

**Behaviour**:
1. If allocation is already `'billed'`, return it as-is (no-op).
2. Within a DB transaction:
   - Retrieve the pool and year from the allocation.
   - Create a `Charge` record:
     - `lease_id` = allocation's lease
     - `name` = `"CAM Reconciliation — {year}"`
     - `type` = `'other'`
     - `amount` = allocation's `true_up_amount` (positive or negative)
     - `currency` = `'EGP'`
     - `frequency` = `'one_time'`
     - `vat_applicable` = `false` (CAM is typically pre-VAT)
     - `vat_rate` = `0`
     - `start_date` = `{year}-01-01`
     - `end_date` = `{year}-12-31`
     - `is_active` = `true`
   - Update allocation: `status='billed'`, `billed_charge_id=<charge_id>`.
   - Refresh and return.

**Idempotency**: Yes. Re-billing an already-billed allocation is a no-op (returns without creating a second charge).

**Locking**: Wrapped in `DB::transaction()`.

**Notes**: Negative true-ups create negative charges (credits). The charge appears on the next invoice generated for that lease.

#### `autoTrueUpForYear(int $year, bool $autoBill = false): array<int, array{pool_id, asset, allocations, billed, status}>`

**Signature**: Full annual reconciliation lifecycle. Generates allocations for all draft/reconciling pools in a given year, optionally bills all pending allocations, and bumps pool statuses.

**Returns**: Report array. Each entry is a dict with keys: `pool_id`, `asset` (name), `allocations` (count generated/updated), `billed` (count billed, 0 if `$autoBill=false`), `status` (pool's new status).

**Behaviour**:
1. Query all `CamExpensePool` with `period_year=$year` and `status IN ('draft', 'reconciling')`.
2. For each pool:
   - Call `generateAllocations()`, capture count.
   - If `$autoBill=true`:
     - Fetch all pending allocations.
     - Call `bill()` on each, count those billed.
   - Decide next status:
     - If `$autoBill && allocations > 0` → `'reconciled'` (set `reconciled_at=now()`).
     - Else if `allocations > 0` → `'reconciling'` (leaves for manual review).
     - Else → keep current status (no change).
   - Update pool if status changed.
   - Append entry to report.
3. Return full report.

**Idempotency**: Yes. Running twice with the same year/auto-bill setting:
- Already-reconciled pools are filtered out (not re-run).
- Already-billed allocations are skipped during generation.
- Second run returns empty report.

**Locking**: Each pool's state changes within `generateAllocations()` and `bill()` transactions.

**Entry point**: CLI command `cam:reconcile`.

---

### `CamAnnualReconciliationCommand`

**Signature**: `php artisan cam:reconcile {--year=YYYY} {--auto-bill}`

**Description**: Thin CLI wrapper around `autoTrueUpForYear()`. Defaults `--year` to previous calendar year if omitted.

**Options**:
- `--year=YYYY` — Target year (2020–2099). Defaults to `now()->year - 1`.
- `--auto-bill` — If set, runs full lifecycle (generate + bill + mark reconciled). If omitted, only generates allocations and bumps pools to `'reconciling'` for manual review.

**Output**: Prints a line per pool showing allocations generated/billed and new status. Ends with a summary line.

**Exit code**: `0` (success).

**Typical usage**:
```bash
# Generate allocations only; review in admin UI before billing
php artisan cam:reconcile --year=2025

# Fully automated: generate, bill, and mark reconciled
php artisan cam:reconcile --year=2025 --auto-bill
```

---

## 6. Filament resources & key fields

### Admin Resource: `CamExpensePoolResource`

**Class**: `App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource`

**Navigation**: "Accounting" group, icon building-office-2, sort 7.

**Scoping**: Property-scoped — `getEloquentQuery()` filters pools by the current asset (`TenantScope::currentAssetId()` / `visibleAssetIds()`), so a property-restricted operator sees only their malls' pools; only a fully-unrestricted admin sees all. `GuardsAssetInScope::assertAssetInScope()` guards the pool's `asset_id` on create + edit. Gated by module permission `'cam'`. *(The three write actions — generateAllocations / markReconciled / bill — gate their permission + status in **both** `visible()` and `action()` via a named predicate; `viewer`/`owner` hold `cam.view` but can't dispatch them. See `CamActionAuthzTest`.)*

**Permission module**: `'cam'` (overridden via `permissionModule()` from `RoleGatedActions`).

**Permissions required**:
- `cam.view` → `canViewAny()`, `canView()`
- `cam.create` → `canCreate()`
- `cam.edit` → `canEdit()`, `canRestore()`
- `cam.delete` → only `super_admin` (enforced globally)

#### Form fields (Create/Edit)

| Field | Type | Validation | Scope | Notes |
|-------|------|-----------|-------|-------|
| `asset_id` | Select | Required, searchable | TenantScoped | If current asset is scoped, auto-selects and disables. Otherwise, offers all selectable assets. |
| `period_year` | TextInput (numeric) | Required, min 2020, max 2099 | — | Defaults to current year. |
| `status` | Select | Required | — | Options: `['draft', 'reconciling', 'reconciled', 'closed']`. Default `'draft'`. |
| `total_actual_expense` | TextInput (numeric) | Required, min 0, step 0.01 | — | Prefix 'EGP'. Helper text explains this is the actual CAM spend for the year. |
| `total_estimated_collected` | TextInput (numeric) | Required, min 0, step 0.01 | — | Prefix 'EGP'. Helper text explains this is monthly estimates collected throughout the year. |
| `notes` | Textarea | Optional, rows 3 | — | Free-form notes (e.g. adjustments, explanations). |

#### Table columns & filters

| Column | Format | Sortable | Toggleable | Notes |
|--------|--------|----------|-----------|-------|
| `asset.name` | Text | — | — | Searchable. Medium weight. |
| `period_year` | Badge (gray) | ✓ | — | Year badge. |
| `total_actual_expense` | Money (EGP) | ✓ | — | Sum of all actual CAM costs. |
| `total_estimated_collected` | Money (EGP) | — | — | Sum of monthly estimates collected. |
| `variance()` | Money (EGP) | — | — | Computed on-the-fly. Color: warning if >0, success if <0, gray if =0. |
| `status` | Badge + color-coded | — | — | draft=gray, reconciling=warning, reconciled=success, closed=info. |
| `allocations_count` | Badge (gray) | — | — | Lazy-loaded count. |
| `reconciled_at` | Date (d/m/Y) | — | ✓ (hidden by default) | Null if not yet reconciled. |

**Filters**: `status` (select).

**Default sort**: `period_year DESC` (newest first).

#### Record actions

| Action | Visible if | What it does | Requires confirmation |
|--------|-----------|----------------|--------|
| `generateAllocations` | Status in `['draft', 'reconciling']` | Calls `CamReconciliationService::generateAllocations()`, bumps pool to `'reconciling'`, shows success notification with allocation count. | Yes |
| `markReconciled` | Status = `'reconciling'` | Updates pool: `status='reconciled'`, `reconciled_at=now()`, `reconciled_by_user_id=Auth::id()`. Shows success notification. | Yes |
| `EditAction` | Standard | Standard edit page. | — |

**Bulk actions**: Delete (only for super_admin, not enabled by default).

#### Relation Manager: `CamAllocationsRelationManager`

Embedded on the pool's edit page. Shows all allocations in this pool.

**Columns** (same as portal/owner, see below):
- `lease.tenant.name`
- `lease.unit.code`
- `pro_rata_share_pct` (formatted as `%.2f%`)
- `allocated_amount` (money, EGP)
- `estimated_paid` (money, EGP, toggleable)
- `true_up_amount` (money, EGP, semibold, colored by sign)
- `status` (badge)

**Filters**: `status` (select).

**Record actions**:
- `bill` — Visible if status = `'pending'`. Calls `bill()`, shows success notification with amount.

---

### Portal Resource: `CamAllocationResource` (Tenant view)

**Class**: `App\Filament\Portal\Resources\CamAllocations\CamAllocationResource`

**Navigation**: Sort 5. Icon: receipt-percent.

**Read-only**: `canCreate()`, `canEdit()`, `canDelete()` all return `false`.

**Scoping**: `getEloquentQuery()` filters to allocations of leases where `lease.tenant_id = Portal::tenantId()`. Pre-loads `pool.asset`, `lease.unit.asset`.

**List page**:
- **Columns**:
  - `pool.period_year` (badge)
  - `pool.asset.name` (toggleable, hidden by default)
  - `pro_rata_share_pct` (suffix '%', numeric(2), right-aligned)
  - `allocated_amount` (money, EGP, right-aligned)
  - `estimated_paid` (money, EGP, right-aligned, toggleable)
  - `true_up_amount` (money, EGP, right-aligned, bold, colored: danger if >0, success if <0)
  - `status` (badge)
- **Filters**: `status` (select)
- **Record actions**: `ViewAction` only
- **Default sort**: `pool.period_year DESC`
- **Empty state**: Receipt-percent icon + localized heading/description

**View page** (Infolist):
- **Section "Period"** (3 cols):
  - `pool.period_year` (badge)
  - `pool.asset.name`
  - `lease.unit.code` (badge)
- **Section "Breakdown"** (2 cols):
  - `pro_rata_share_pct` (suffix '%', numeric 2)
  - `pool.total_actual_expense` (money, EGP)
  - `allocated_amount` (money, EGP)
  - `estimated_paid` (money, EGP)
  - `true_up_amount` (money, EGP, colored by sign, helper text explaining the meaning)
  - `status` (badge)

---

### Owner Resource: `CamAllocationResource` (Owner view)

**Class**: `App\Filament\Owner\Resources\CamAllocations\CamAllocationResource`

**Navigation**: "Portfolio" group, sort 4. Icon: receipt-percent.

**Read-only**: Same as portal (no create/edit/delete).

**Scoping**: `getEloquentQuery()` filters to allocations of pools whose `pool.asset.owners` include the current user. Pre-loads `pool.asset`, `lease.unit.asset`, `lease.tenant`.

**List & View pages**: Identical to portal version.

---

## 7. Notifications & integrations

**Notifications fired** (Filament in-app only):
- When admin clicks "Generate Allocations": `allocations_generated` (title + body with count)
- When admin clicks "Mark Reconciled": `pool_reconciled` (title only)
- When admin clicks "Bill Allocation" on a row: `allocation_billed` (title + body with amount)

**External integrations**: None currently. CAM charges are created as standard `Charge` records and flow through the normal invoice/billing pipeline (Paymob integration, if applicable, occurs downstream at invoice time).

**Notifications NOT sent to tenants**: Allocations are visible to tenants in their portal once the pool is `'reconciled'` or later. No email/SMS is triggered — tenants see the CAM charge on their next invoice.

---

## 8. Extension points — how to change/extend SAFELY

### To add a new allocation field

1. **Add column to migration**: Edit the `create_cam_allocations_table` migration (or create a new one). Define type and cast.
   ```php
   $table->decimal('new_field', 14, 2)->default(0);
   ```

2. **Update model**: Add to `$fillable` array and `$casts` in `CamAllocation`.

3. **Update generation logic**: In `CamReconciliationService::generateAllocations()`, compute and assign the new field when filling the allocation.

4. **Update UI**: Add a column/field in the Filament admin/portal infolist or form as appropriate.

5. **Test**: Add test cases in `CamScenarioTest.php` to verify the new field's calculation and any new invariants.

**Do NOT**: Break the invariant that `generateAllocations()` skips non-pending allocations.

### To add a new pool field or validation rule

1. **Add column** to migration if it's persisted (or add to `$fillable` if it's just a parameter).

2. **Update model** `$fillable` and `$casts`.

3. **Update form** in `CamExpensePoolForm::configure()`.

4. **Update table** if it should be visible (add column to `CamExpensePoolsTable`).

5. **Test**: Add cases in `CamScenarioTest.php` or a new file.

**Do NOT**: Add fields that would change the historical record (e.g. editing `total_actual_expense` after allocations are billed should be blocked or require de-billing first).

### To change the pro-rata weighting (e.g. weight by revenue instead of area)

1. **Modify allocation formula** in `CamReconciliationService::generateAllocations()`. The key lines are:
   ```php
   $sqm = $lease->totalAreaSqm();   // ALL units on the lease, never $lease->unit->area_sqm
   if ($sqm <= 0) continue;
   $share = $sqm / $totalSqm;
   ```
   Replace `$sqm` and `$totalSqm` with your new metric.

2. **Update tests**: Rewrite `CamScenarioTest::it('generates one pro-rata allocation...')` and related cases to reflect the new weighting.

3. **Document**: Update this section and the "Domain model" section to explain the new formula.

4. **Verify invariants**: Ensure sums still partition to 100% and that rounding residuals remain bounded.

**Do NOT**: Break backward compatibility with existing allocations (they are immutable once billed).

### The recovery-clause engine (admin fee → caps → basis/gross-up/exclusions)

The admin fee (**slice 1**) and caps (**slice 2**) are shipped; the engine is phased (full design folded into §8 of this doc). The keystone constraint across every slice: **`allocated_amount` stays the uncapped cost pass-through** the books-check ties out against — clauses attach as siblings (the admin fee) or adjust only `true_up_amount` (caps), never the allocation. Every clause is off-by-default (`admin_fee_pct` null, no `LeaseCamTerm`, `exclusions` unset ⇒ byte-identical no-clause billing):

- ✅ **Slice 1 — admin fee**: see the admin-fee rule above.
- ✅ **Slice 2 — caps** (`LeaseCamTerm`): see the caps rule above — the cap adjusts the true-up + fee base only.
- **Slice 3 — configurable basis / gross-up / exclusions** (designed, not yet built): alternative pro-rata weightings, a gross-up to a target occupancy, and per-lease exclusion sets (`exclusions` JSON). `admin_fee_on_net` decides whether the fee is charged on the gross or net (post-gross-up) share.

**To add a new clause**: add its column(s) defaulting to a no-op, compute inside `generateAllocations`, apply in the correct order (SOURCE → EXCLUDE → GROSS-UP → ALLOCATE → CAP → ADMIN-FEE → TRUE-UP), and add a parity assertion to `CamNoClauseParityTest` proving the default is still byte-identical.

### Caps and exclusions

**Caps are SHIPPED** (slice 2) — see the *Caps* rule in § 3. `cap_amount` / `capped_cost_amount` /
`cap_absorbed_amount` are populated by `generateAllocations` from the lease's effective-dated
`LeaseCamTerm` (`Lease::resolveCamCeiling`); the cap trims the true-up + admin-fee base only,
**never** `allocated_amount` (the books-check tie-out basis). *(This section previously said caps
were "not used" — stale; corrected in the close-out.)*

**`exclusions`** (the JSON column) belongs to **slice 3** (per-lease non-recoverables) and is still
unused — see the recovery-clause engine note below and the [plan](08-cam.md).

**Do NOT**: change `allocated_amount` for a cap/exclusion — only the true-up + fee base (the keystone
invariant).

### To link CAM charges to a tax/VAT regime

1. CAM charges are created with `vat_applicable=false, vat_rate=0`. To change:
   ```php
   $charge = Charge::create([
       // ...
       'vat_applicable' => true,
       'vat_rate' => 0.15, // or from config
       // ...
   ]);
   ```

2. **Test**: Verify invoice generation includes VAT on CAM.

**Do NOT**: Assume all jurisdictions treat CAM the same; consider a config or lease-level flag.

### To disable auto-billing or require approval

1. **Modify `autoTrueUpForYear()`**: Even if `$autoBill=true`, check a permission or flag before calling `bill()`.

2. **Add UI gate**: In `CamAllocationsRelationManager`, wrap the "bill" action in a visibility check.

3. **Test**: Add a case like `it('respects billing approval flag...')`.

**Do NOT**: Leave allocations stuck in 'pending' indefinitely (implement a workflow for approval).

---

## 9. Gotchas, edge cases & recently-fixed bugs

### Double-bill regression (FIXED)

**Bug**: `generateAllocations()` always set `status='pending'`, so re-running it on a pool with a 'billed' allocation would reset the status back to 'pending'. The next billing pass would then create a second charge.

**Fix**: Added the skip-billed guard: if an allocation exists and status ≠ 'pending', skip it entirely.

**Test guards**: `CamAllocationDoubleBillTest::it('does not reset an already-billed allocation...')`.

**Lesson**: Any idempotent operation that reuses state must check state before overwriting.

---

### Share-basis drift on re-run after partial billing (FIXED — clause-review #1)

**Bug**: `generateAllocations()` re-run pinned the participant *set* but recomputed the sqm denominator from *live* unit areas. After partial billing (some allocations frozen at `billed`), correcting a unit's `area_sqm` — or soft-deleting a participant — then re-running recomputed the still-pending shares against the new denominator: `Σ(allocated_amount) ≠ total_actual_expense` (broken tie-out) and mis-billed tenants. The pool freeze guard protected the *expense* basis but had no counterpart for the *area* basis.

**Fix**: On a re-run (existing allocations present), REUSE each allocation's stored `pro_rata_share_pct` instead of recomputing from sqm — the share basis is established on the first run and frozen thereafter. `capped_cost`/`admin_fee` derive from the frozen `allocated`, so they stay consistent too.

**Test guards**: `CamClauseReviewHardeningTest` (unit-area edit + soft-deleted participant between runs both keep `Σ = total_actual_expense`).

---

### Recovery-basis freeze now covers the admin fee (FIXED — clause-review #2)

The pool `updating` freeze guard (can't change the basis after any allocation is billed) now also blocks `admin_fee_pct` / `admin_fee_on_net` — otherwise billed rows keep the old fee while re-generated pending rows recompute on the new rate, charging equal shares different fees. `admin_fee_pct` is also in the activity log now. To change a fee rate mid-cycle, void the billed allocations first.

---

### Cap terms hard-delete (clause-review #3/#4/#5)

`LeaseCamTerm` is **hard-deleted** (not soft) so a removed cap frees its `(lease_id, effective_year)` unique slot — a soft-deleted row would collide with the index and permanently block re-adding that year's cap. Safe because a cap term is forward-looking config; historical allocations already froze the `cap_amount` they applied.

---

### Rounding residuals

When total sqm is not evenly divisible (e.g. three equal units = 1/3 each on 100,000), rounding to 2 decimals can accumulate. The system accepts **up to ±1 cent** residual per pool.

**Example**:
```
100,000 / 3 = 33,333.33 each (per allocation)
Sum = 99,999.99 (1 cent short)
```

If this is unacceptable in your jurisdiction, implement a "penny-pinch" algorithm: allocate the residual to the largest allocation.

**Test guard**: `CamScenarioTest::it('leaves only a sub-cent rounding residual...')`.

---

### Negative true-ups (credits)

When a tenant over-paid their estimate, `true_up_amount` is negative. The charge is created with the negative amount. On invoicing, this nets as a credit. No special handling needed; just ensure downstream invoice generation correctly interprets negative charges.

**Test guard**: `CamScenarioTest::it('produces a negative true-up (credit)...')` and `it('billing a negative-true-up allocation creates a negative (credit) charge')`.

---

### Soft-deleted leases and units

If a lease is soft-deleted after an allocation is billed, the allocation still references it. The allocation persists, the charge remains on the lease. No action needed.

If a unit is soft-deleted, active leases using it are no longer queried in `generateAllocations()` (because `Lease::whereHas('unit', ...)` would skip them). This is safe but unusual; check if soft-deletes are your intended behavior.

---

### Multi-unit leases (not yet relevant, but future-proof)

The current model assumes each lease has one master unit. If multi-unit leases become common, the allocation logic must sum area across all units in the `lease_unit` pivot. Update:
```php
$sqm = (float) $lease->units()->sum('area_sqm');
```

---

### Year boundaries

The pool's `period_year` is a single year (e.g. 2025). CAM collected month-by-month in one calendar year; reconciliation happens in January/February of the next year. No cross-year logic is needed.

---

### Permission scoping gotcha

Portal tenants see only their own allocations (filtered in `getEloquentQuery()`). Owner users see allocations for assets they own. Admin sees all. This is enforced in the resource query, not in the service; the service is permission-agnostic. If you expose the service via an API without applying the same query filters, you'll leak data.

---

### Allocation state transitions are not enforced by the model

A malicious API client could manually set an allocation's status to 'closed' without billing. The code trusts the status. If transitions should be rigid, add a state machine helper or model scope:
```php
public function markBilled() {
    if ($this->status !== 'pending') {
        throw new \Exception('Can only bill pending allocations');
    }
    // ...
}
```

---

## 10. Tests & related modules

### Test files

| File | Coverage |
|------|----------|
| `tests/Feature/Scenarios/CamScenarioTest.php` | Pro-rata math, rounding, scope, idempotency, state transitions, variance. **CORE TEST FILE**. ~337 lines, 17 test cases. |
| `tests/Feature/Regression/CamAllocationDoubleBillTest.php` | Double-bill regression (fixed). 1 test case pinning the fix. |
| `tests/Feature/Services/CamAutoTrueUpTest.php` | `autoTrueUpForYear()` with/without auto-bill, year skipping, empty report, idempotency, CLI command wiring. ~86 lines, 6 test cases. |
| `tests/Feature/Resources/PortalCamAllocationScopingTest.php` | Portal tenant isolation (Tenant A sees only their allocations). 2 test cases. |

**Total coverage**: ~27 test cases, all passing.

### Running tests

```bash
# All CAM tests
php artisan test --filter=Cam

# Specific test file
php artisan test tests/Feature/Scenarios/CamScenarioTest.php

# Single test case
php artisan test --filter='generates one pro-rata allocation'
```

---

### Related modules & docs

| Module | Relationship |
|--------|--------------|
| **Lease** (`docs/modules/03-lease.md`) | CAM allocations bind to active leases. Lease status, unit area, and tenant determine eligibility. |
| **Charge** (`docs/modules/05-charge.md`) | CAM allocations create one-time charges. The charge's amount, currency, dates are controlled by the true-up and pool year. |
| **Invoice & AR** (`docs/modules/06-invoice.md` / `09-ar.md`) | CAM charges flow into the next invoice. Negative charges (credits) offset rent or are refunded. |
| **Asset** (`docs/modules/01-asset.md`) | Pool is scoped to an asset. Asset properties (e.g. name, currency) may influence CAM. |
| **Permissions & Roles** (`docs/modules/11-permissions.md`) | CAM actions are gated by module permissions (`cam.*`). Only users with the right role can view/create/edit pools or bill allocations. |


## 11. Close-out (2026-07-20) — what changed

The property+facility close-out ([gap-analysis](../gap-analysis/README.md)); plain-language business model: [business-model/08](08-cam.md). The engine (slices 1–2) was found correct; the fixes are around it.

### Authz double-gate (was: dispatch hole)
`generateAllocations` / `markReconciled` (`CamExpensePoolsTable`) + `bill` + the new `void` (`CamAllocationsRelationManager`) now re-assert a **named predicate** — `canGenerate` / `canMarkReconciled` / `canBill` / `canVoid` (permission **and** status) — in **both** `visible()` and `action()` (`abort_unless`). `mountAction()` never checks `isVisible()`, and seeded `viewer`/`owner` hold `cam.view`, so the old visible()-only gate let them dispatch these (and `generateAllocations` re-opened a reconciled pool via its unconditional `status=reconciling`). Tested via `mountAction`+`callMountedAction` in `CamActionAuthzTest` (not `callAction`, which false-passes).

### Per-pool recovery VAT (`recovery_vat_rate`, default 14%)
The monthly CAM estimate bills at 14% VAT; the year-end recovery used to bill at 0% — an output-VAT under-collection inconsistent with the estimate. `billChargeImmediately` now VATs the recovery line and `billCredit` VATs the over-collection credit note (the credit-note journalizer reverses it: Dr Sales Returns + Dr VAT Payable / Cr AR). VAT rides **on top** — `allocated_amount` / `true_up_amount` are untouched, so `Σ allocated = total_actual_expense` still ties out. Frozen with the rest of the basis (`CamExpensePool::booted`). `recovery_vat_rate = 0` ⇒ byte-identical to the pre-VAT pass-through. Tested through the real sweep in `CamRecoveryVatTest`. *(A tax call for the accountant; the default matches the estimate, 0% available per pool.)*

### `voidAllocation` (un-bill / correction path)
Reverses a billed allocation's documents — the recovery invoice (`VoidInvoiceService`, which also carries the fee line for a positive true-up), the credit note (`CreditNoteService::reverseAllApplications` then `void`), and the fee-only invoice — and resets the allocation to `pending`, which **unfreezes the pool basis** (the freeze guard checks non-pending allocations) for a correct-and-re-bill. A recovery invoice the tenant already **paid** blocks it (`VoidInvoiceService` refuses captured cash — refund first). Lock-safe + idempotent; the whole reversal is one transaction. Surfaced as the `void` action on the allocations relation manager (gated `cam.bill_allocation`). Tested in `CamVoidAllocationTest`. This is the action the freeze guard's error message ("void the billed allocations first") always referenced.

### Operator UX pass (2026-07-22)
A UX audit found the reconciliation numbers weren't verifiable and one modal misinformed. Fixed: **(H1)** the Bill confirm modal said the true-up rides "the next monthly invoice" — it's billed **immediately** as a dedicated recovery invoice (or an auto-applied credit); reworded. **(H2)** editing a reconciled pool's basis fields threw an uncaught `DomainException` → Livewire 500; the four basis fields are now **disabled with a "frozen — void billed allocations first" hint** once an allocation is billed (`CamExpensePoolForm::basisFrozen()`), plus `EditCamExpensePool::handleRecordUpdate()` catches the guard as a clean toast backstop. **(M1)** the cap + VAT legs were invisible and the columns stopped adding up when a cap bit — added `CamReconciliationService::explainAllocation()` + a native-Filament **"View working"** breakdown action (share → allocated → cap ceiling/capped/absorbed → estimate → true-up → recovery VAT → admin fee + VAT → net invoiced/credited) and toggleable `capped_cost`/`cap_absorbed` columns. **(M2)** the billed-allocation notification now branches recover / credit / fee-only (was "true-up added" for all three). **(L1)** allocations relation-manager empty state. Tests: `CamAllocationBreakdownTest`.

### Deferred (with triggers)
Slice 3 (gross-up / GLA basis / alternative bases / per-lease `exclusions`); per-tenant estimate derivation from actual billed service charges; move-in/out occupied-days proration; a tenant reconciliation-statement PDF. See the closure record for the triggers.

---

## Correction — the `visible()` dispatch premise (2026-07-31)

**Correction 2026-07-31 — the "still dispatchable" half of this was WRONG on the version we ship.** `mountAction()` does check `isDisabled()`, and `CanBeDisabled::isDisabled()` returns true when `isHidden()` does (`vendor/filament/actions/src/Concerns/CanBeDisabled.php:24`), so on **Filament v4.11.8 a `visible()`-only action IS refused at dispatch**. Found by mutation-testing the module-08 fix: deleting CAM's `abort_unless` left `CamActionAuthzTest` fully green — those tests never exercised the gate they describe. **Double-gate anyway**, for a reason that survives the correction: `->authorize()` is a stated intent, while hidden-implies-disabled is an upstream implementation detail that can change in a release and would silently reopen every `visible()`-only write at once. `FilamentActionDispatchContractTest` pins that upstream behaviour so an upgrade that changes it turns the build red; `ActionAuthzConformanceTest` enforces the layer we control.

---

## Deletion policy

Operator decision 2026-07-31, following Yardi/MRI/Entrata: a record that carries history is
**refused**, not warned about — the damage lands on the reports and audit trail that referenced
it, none of which are in front of whoever clicks the button. The single register is
[`App\Support\DeletionPolicy`](../../app/Support/DeletionPolicy.php); `DeletionPolicyConformanceTest` fails the build if a model here ships unclassified or a Delete
button reappears on a money record.

| Model | Rule | Instead / why |
|---|---|---|
| `CamExpensePool` | **Only while unreferenced** — blocked by `allocations` | void the allocations first — they are what tenants were billed from |
| `CamAllocation` | Deletable (super_admin) | operational: voided through the pool, not removed |
