# 04 — Scenarios: what Yardi does, what Atriom does today

> Fifteen end-to-end situations from a real Egyptian mall, with real numbers. Each one states the
> deal, what **Yardi** does, what **Atriom** does today (grounded in the code), and **the delta** —
> what actually goes wrong. Together they are the acceptance test for this cycle: when the phase
> plan is done, every "delta" below should read *none*.
>
> Standing assumptions: EGP · VAT 14% on service charges, base rent VAT-exempt · marketing levy 5%
> of base rent · payment terms 7 days.

> ### ✅ ALL FIFTEEN DELTAS ARE CLOSED — re-verified against the code 2026-09-01
>
> **Read the CLOSED note under each scenario before you read its delta.** The "Atriom" and "Delta"
> paragraphs are preserved as written, in the state the code was in when each was measured; they are
> the *case that was made*, not a description of the system. Two of them called out live money bugs —
> **S5** (*"CAM mis-allocates on every multi-unit lease"*) and **S9** (*"117,971/month of contracted
> revenue not billed"*) — and **both shipped**. A reader who stops at the delta walks away believing
> this system is losing money every month.
>
> Only S4 and S12 carried correction notes before this pass; thirteen did not, and the closing table
> still argued for building a structure that had already been built. That is the same drift
> [gap-analysis §0](../../gap-analysis/README.md) is written against — *a gap row is a claim about
> code* — and this document is where it survived longest, because a scenario reads like a story
> rather than like a checklist.

---

## S1 — New lease: fit-out grace + stepped rent

**The deal.** Zara Egypt, unit A-14, 900 m². Handover 1 Jan 2026, 3 months rent-free fit-out, rent
commences 1 Apr 2026. Base rent 180,000/month, escalating 7% each anniversary. Service charge
36,000/month. Marketing levy 5% of base rent. Term 5 years to 31 Mar 2031.

**Yardi.** On the day the lease is abstracted, the schedule is complete — five `RENT` rows
(180,000 → 192,600 → 206,082 → 220,508 → 235,943), one `CAMEST` row, one `MKTG` row, all
date-ranged; possession date 1 Jan, rent commencement 1 Apr, expiration 31 Mar 2031. The straight-
line schedule is computable immediately. The rent roll shows 2029's rent today.

**Atriom.** `LeaseCreationService` seeds exactly **two** charges — base rent 180,000 (VAT-exempt)
and service charge 36,000 (14%) — plus the marketing levy charge, each a **single row with today's
amount**. `fit_out_months = 3` suppresses the *whole invoice* for Jan–Mar. `next_escalation_date`
is armed at commencement + 1 year and the nightly sweep will **overwrite** `amount` when it fires.

**Delta.**
- Nobody can see 2027's rent until the night the sweep changes it. Budgeting, owner reporting and
  forecasting are all guesswork.
- Nothing records that 180,000 was the rent for Apr 2026 – Mar 2027 once the sweep has run;
  only `leases.notes` and the activity log carry a trace.

> **CLOSED 2026-08-19.** The contracted ladder is projected at origination:
> `ChargeScheduleService::projectTermEscalations()` writes one dated charge row per step, called
> from `CreateLease`, `LeaseCreationService`, `LeaseRenewalService` and `LeaseExtensionService`, so
> 2029's rent is visible on the rent roll the day the lease is abstracted. `atriom:project-lease-schedules`
> backfills leases that pre-date it. The fit-out question in the third bullet is a *deal* question,
> not a code one, and is still the operator's to answer.
- **The fit-out grace suppresses the service charge and the marketing levy too.** That is a
  deliberate operator decision (2026-07-19), but the far more common mall deal is *rent-free,
  service charge payable* — the tenant still consumes cleaning, security and A/C during fit-out.
  Atriom cannot express it at all. **Re-validate this with Eltizam.**

---

## S2 — Mid-month commencement

**The deal.** Same lease, but rent commences **15 Apr 2026** (30-day month).

**Yardi.** The `RENT` charge is flagged to prorate; the April posting bills 16/30 of 180,000 =
96,000, and the recurring schedule is untouched.

**Atriom.** Correct, and carefully built: `daysBilled = 16`, `factor = 16/30`, the factor is kept at
full precision and only the money is rounded, `period_start` is stored as 15 Apr so the idempotency
guard still recognises April as billed
([`MonthlyBillingService.php:276-292`](../../../app/Services/MonthlyBillingService.php#L276)).

**Delta.** One: **the bulk run never prorates.** `prorate` defaults to `false` and only the manual
single-lease "Generate Invoice" action passes `true`. So a lease that commences mid-month is billed
a *full* month by the scheduled run unless a human remembers to bill it by hand first. That is a
silent overcharge to the tenant, and it happens on exactly the leases nobody is watching.

> **CLOSED.** The bulk path passes `prorate: true`
> ([`MonthlyBillingService.php:95`](../../../app/Services/MonthlyBillingService.php#L95)), so the
> scheduled run and the manual action price a stub month the same way. Which *method* it prorates by
> is now the lease's own (`App\Support\ProrationMethod`, EG-29 — actual / 30-day / 365 / whole
> month), and whether a given charge prorates at all is `charges.prorate`, so a flat signage or
> parking fee bills in full for any month the lease runs into.

---

## S3 — Annual escalation

**The deal.** 1 Apr 2027 arrives. Rent steps 180,000 → 192,600.

**Yardi.** Nothing happens on the night. The 2027 row was always there; April's posting simply
reads it. The escalation was reviewed and approved when the lease was abstracted, a year earlier.

**Atriom.** `leases:apply-escalations` sweeps leases with `next_escalation_date <= today`, row-locks
each, re-checks due-ness inside the transaction, calls `LeaseRentChangeService::apply()` — which
**overwrites** `Lease.base_rent_monthly` and the base-rent `Charge.amount` — and rolls the date
forward a year. It is idempotent, lock-safe, one step per run, and CPI is deliberately skipped
rather than invented. As a *sweep* it is well built.

**Delta.** The mechanism is sound; the **model** is wrong. An escalation is a scheduled future fact,
and Atriom implements it as a destructive nightly mutation. Consequences: no review-before-it-bills,
no forward visibility, no history, and a bug in the sweep silently re-prices a live tenancy with no
document to point at.

> **CLOSED 2026-08-19.** The ladder is projected forward at origination (see S1), so the step for
> 2027 exists as a dated row from day one and the sweep reads it rather than inventing it. Every
> change to a lease's rent is also an append-only `lease_events` row carrying its own reason — the
> "no history" half — and the narrative is a KEY resolved at read time, so the timeline reads in the
> reader's language rather than in whichever one the sweep happened to run in.

---

## S4 — CPI escalation with a collar

**The deal.** A lease escalates by Egyptian CPI, **floor 3%, ceiling 8%**, compounded, measured on
the September index published in October, effective 1 January.

**Yardi.** An index-method escalation with an index source, a publication lag, a base index value,
a floor and a ceiling. It generates the row when the index publishes.

**Atriom.** `escalation_type = 'cpi'` exists as an enum value and the sweep **deliberately skips
it** — there is no index feed, and the module doc is explicit that inventing a CPI number would be
inventing data. That call is right.

**Delta.** No floor/ceiling fields anywhere, so even a manual CPI application cannot be recorded
correctly, and there is no index register to apply. In Egypt, where CPI has run 20–35%, a collar is
not optional — an uncollared CPI clause is a clause no tenant signs. **Any CPI work must ship the
collar with it, or it is worse than nothing.**

> **CLOSED 2026-08-19.** The collar shipped first (`escalation_floor_rate` /
> `escalation_ceiling_rate`), and the index register followed: `rent_indices` holds the published
> figures, and a lease states its index, base value and publication lag. The warning above was
> honoured — the collar was already in place before a single index-derived step could be applied,
> and the sweep still refuses to invent a figure: an unpublished month leaves the lease alone and
> does not roll its anniversary.

---

## S5 — Mid-term expansion

**The deal.** Oct 2028: Zara takes the adjacent 300 m² unit A-15. Rent on the extra space is
55,000/month from 1 Nov 2028; the CAM share re-bases from 900 to 1,200 m².

**Yardi.** An **expansion amendment**: the lease–space link for A-15 opens 1 Nov 2028 with 300 m²,
a new `RENT` row opens for the incremental amount, the CAM numerator re-bases from that date. The
lease code, term and history are unchanged. The rent roll before and after 1 Nov 2028 are both
correct.

**Atriom.** Add A-15 to `additional_unit_ids`; `syncUnits()` attaches the pivot row and recomputes
occupancy. Then a human runs "Change Rent" to set the blended rent to 235,000, which **overwrites**
the base-rent charge with no effective date. CAM share reads `lease.unit.area_sqm` — **the master
unit only** — so the CAM allocation does *not* pick up the extra 300 m².

**Delta.** Three, and the third is a money bug:
1. The rent change has no effective date, so November's invoice is correct only if a human runs it
   on exactly the right day.
2. There is no record that this was an *expansion* — it is indistinguishable from a renegotiation.
3. **CAM mis-allocates on every multi-unit lease.** `CamReconciliationService` reads
   `$lease->unit?->area_sqm` — the **master unit only** — for both the numerator
   ([line 72](../../../app/Services/CamReconciliationService.php#L72)) and the denominator
   ([line 55](../../../app/Services/CamReconciliationService.php#L55)). There is no
   `Lease::totalArea()` and the `lease_unit` pivot is never summed. Zara's 1,200 m² is treated as
   900 m².

   **Why no test caught it:** because both sides use the same wrong basis, the shares still sum to
   100% and the pool still ties out exactly — `Σ(allocated) = total_actual_expense` is green. The
   *total* is right; the *distribution* is wrong. Every multi-unit lease is under-charged in
   proportion to its non-master area and every single-unit tenant absorbs the shortfall. A tie-out
   assertion cannot see this, which is precisely the trap the GL-registry invariant warns about in
   a different module.

   **This is a live money bug, not a benchmark gap** — it is independent of everything else in this
   cycle and should be fixed on its own, with a regression test that asserts a two-unit lease's
   share equals its *summed* area over the *summed* denominator.

> **CLOSED — and the money bug in point 3 was fixed first, exactly as this scenario asked.**
> `CamReconciliationService` resolves area through `totalAreaSqmForPeriod()`, which sums the
> `lease_unit` pivot over the days each unit was actually held, so a lease that expands mid-year is
> weighted for what it occupied when it occupied it — numerator and denominator both. Point 1 is
> closed by the dated charge ladder (S1) plus `LeaseSpaceChangeService`, which re-derives the rent
> from the rate over the new area **honouring a holdover uplift** (EG-40); point 2 by the
> `lease_events` amendment types, of which expansion is one.

---

## S6 — Negotiated mid-term relief

**The deal.** A struggling tenant gets 50% rent relief for 6 months (Jul–Dec 2027), then reverts.

**Yardi.** A rent-modification amendment: close the current row at 30 Jun, open a relief row Jul–Dec
at the reduced amount, open the original schedule again from 1 Jan. Reason and document reference
attached. Straight-line rent recalculates from the modification date.

**Atriom.** "Change Rent" down in July, and **a diary note to change it back in January**. If the
diary fails, the tenant pays half rent forever and nothing in the system objects.

**Delta.** The temporary is indistinguishable from the permanent. This is the single most
convincing argument for the schedule model to a non-technical stakeholder: *the system cannot tell
the difference between a six-month discount and a permanent rent cut.*

> **CLOSED.** `LeaseReliefService` overlays a bounded relief window on the charge ladder through
> `ChargeScheduleService::overlayWindow()`: the original row closes, a relief row runs Jul–Dec at the
> reduced amount, and the original schedule **resumes on its own** in January — no diary. Relief is
> its own `lease_events` type, so it reads as temporary on the timeline. The relief and resumed rows
> carry `billing_timing` **and** `prorate` forward (D3-01, 2026-08-24): dropping them flipped an
> arrears service charge to advance and double-billed the crossover month.

---

## S7 — Renewal option with a notice window

**The deal.** The lease carries one 5-year renewal option at last-rent + 10%, exercisable by
written notice **no earlier than 12 and no later than 9 months** before expiry (i.e. between
1 Apr and 1 Jul 2030).

**Yardi.** The option is a record with a notice window and a rent basis. Critical-date alerts fire
ahead of **1 April 2030** to the leasing manager. Space A-14 is flagged **encumbered** so nobody
markets it. On exercise, the renewal amendment writes the new charge rows at +10%.

**Atriom.** No option record of any kind. `leases:remind-expiring` fires at 90 days before
**expiry** — which is *six months after the option window closed*. The option itself lives only in
the uploaded PDF.

**Delta.** **The option cannot be exercised on time by anyone relying on this system.** By the time
Atriom speaks, the tenant has either lost a below-market renewal or the landlord has lost a
contracted rent step, and the space has been marketed while encumbered. Cheap to fix, expensive to
miss — this is why options rank so highly in the phase plan.

> **CLOSED.** `LeaseOption` records the window and the rent basis, and
> `leases:scan-option-windows` ([`routes/console.php:324`](../../../routes/console.php#L324)) alerts
> on the **notice window opening**, not on expiry. Exercising it is its own act with its own
> `lease_events` entry, and the renewal it creates is a new chained lease whose rate follows the
> negotiated rent rather than the other way round (EG-39).

---

## S8 — Termination mid-month and the final account

**The deal.** A tenant terminates effective **18 Sep 2028**. Held deposit 540,000. Outstanding: one
unpaid invoice of 120,000; damages assessed at 35,000; the 2028 CAM true-up will not be known until
March 2029.

**Yardi.** Charges prorate to the 18th. A **move-out disposition** nets the deposit: 540,000 −
120,000 unpaid − 35,000 damages = 385,000 refunded, itemised on one document, with the CAM true-up
either estimated and reserved or explicitly deferred. Unamortised TI and commission feed the
termination penalty if the lease has one.

**Atriom.** `LeaseTerminationService` sets the status, deactivates charges with
`end_date = termination_date`, and optionally cancels **fully-unpaid** invoices (correctly refusing
to cancel partially-paid ones, which would orphan `paid_amount`). But:
- **September was already billed in full on 1 Sep.** There is no trailing proration anywhere —
  proration is commencement-only. The tenant is billed 12 days they do not owe, and the fix is a
  manual credit note.
- Deposit refund and forfeit are **two separate manual `DepositTransaction` events**; nothing nets
  them, nothing itemises them, nothing checks the balance held against the lease's contractual
  `security_deposit`.
- The 2028 CAM true-up will be generated in 2029 against a terminated lease. Atriom's
  bill-immediately design handles this correctly — which is a genuine strength — but nothing
  *reserves* for it at move-out, so the final account is settled before the last number is known.

**Delta.** No trailing proration; no final-account document. Move-out is the moment a tenancy's
money is settled, and it is currently a sequence of unconnected manual acts.

> **CLOSED.** Trailing proration is `CreditUnearnedBillingService`, which credits the unearned tail
> of an already-billed month on the **same** `monthsCovered()` rule the invoice was billed on — a
> credit computed on a different rule than its invoice disagrees by days on exactly the mid-month
> move-out it exists for. The final account is `MoveOutStatementService` (one document, itemised)
> and `SettleMoveOutService` nets the deposit through `DepositApplication`, which is a *settlement
> channel* on the invoice rather than a second cash path. `LeaseTerminationService` defaults
> `credit_unearned = true`.

---

## S9 — Holdover

**The deal.** The lease expires 31 Mar 2031. The tenant does not leave and does not sign. The lease
says holdover rent is 150% of the last rent, month to month.

**Yardi.** A **holdover amendment** converts the lease to month-to-month, opens a `RENT` row at
353,914 (150% of 235,943), and it bills automatically until someone signs or leaves.

**Atriom.** `Module04HoldoverAlertTest` confirms the design: an active lease past its end date **is
surfaced, not billed**. The operator sees a flag.

**Delta.** The mall bills nothing, or bills the old rent, while a tenant occupies at 100% instead of
150%. On a 235,943 rent, every month of holdover is **117,971 of contracted revenue not billed** —
and holdovers routinely run six months. This is real money and a simple fix once a schedule exists.

> **CLOSED — the 117,971/month is billed.** `ConvertLeaseToHoldoverService` converts the lease to
> month-to-month and prices it at `holdover_rate_pct` **as a percentage of the contracted rent**
> (150 means 150%, the shipped default), leaving `base_rent_rate_per_sqm_year` contractual — a rate
> is what the parties agreed, and re-rating on conversion would bake a temporary penalty into it.
> Every later derivation honours the premium: EG-40 fixed `LeaseSpaceChangeService` silently
> dropping it back to 100% when a tenant took extra space mid-holdover.

---

## S10 — Partial payment against a disputed line

**The deal.** September invoice: rent 235,943 + service charge 36,000 + VAT 5,040 + marketing
11,797 = **288,780**. The tenant disputes the service charge and pays **247,740** (everything but
the service charge and its VAT), in writing.

**Yardi.** Four charges, four receivables. The receipt is applied to `RENT` and `MKTG` in full; the
`CAM` charge stays open, ages on its own, is flagged disputed, and is chased separately. Aging
reports show one disputed CAM item, not a delinquent tenant.

**Atriom.** One invoice, one balance. The payment allocates 247,740 to it; the invoice goes
`partially_paid` with a 41,040 balance. Which line is unpaid is knowable only from the tenant's
letter. The late-fee sweep will eventually charge a fee on a *disputed* balance, and the tenant
appears in AR aging as generically delinquent.

**Delta.** **This is the real cost of invoice-level AR** — and it is still the right choice for
Egypt (the invoice is the ETA document). The fix is not to abandon invoice-level AR but to allocate
payments **to invoice items** and let an item carry a disputed flag. See
[gap §8](../../gap-analysis/README.md).

> **CLOSED — built exactly as prescribed.** `invoice_item_payment` (MF-06) records how an
> already-counted settlement splits across the lines, and `invoice_items.disputed_at` flags the
> contested one. It is deliberately **not a fifth settlement channel**: every per-line figure is
> derived from the invoice's own `paid_amount` by `App\Support\InvoiceItemSettlement`, so the item
> outstandings always sum back to `balance` and there is never a second truth about the same money.

---

## S11 — CAM year-end reconciliation

**The deal.** Mall GLA 40,000 m²; occupied 32,000 m² (80%). 2027 CAM actuals 14,400,000, of which
9,000,000 variable. Zara: 900 m², estimate billed 36,000/month = 432,000. Cap: 5% YoY over a 2026
base of 380,000. Admin fee 10%.

**Yardi.**
- Pool = the CAM GL accounts, **queried**, so every charge drills to its vendor invoices.
- Gross-up of the variable half to 95% occupancy: 9,000,000 × (95/80) = 10,687,500 → grossed-up
  pool 16,087,500.
- Denominator per the lease. On **GLA**: share = 900/40,000 = 2.25% → 361,969. On **occupied**:
  900/32,000 = 2.8125% → 452,461. *Same lease, same costs, 90,492 apart — decided entirely by which
  denominator the lease specifies.*
- Cap: 380,000 × 1.05 = 399,000 → capped at 399,000, landlord absorbs the excess.
- Admin fee 10% on the capped cost = 39,900.
- True-up = 399,000 + 39,900 − 432,000 = **6,900 credit**.
- **Re-estimate:** 2028's monthly estimate is raised toward the new run rate.
- The tenant receives a statement showing every line of the above.

**Atriom.** Steps 4, 5 and 6 are implemented and implemented well — `LeaseCamTerm` resolves the
ceiling (absolute / YoY / both, with base year and compounding, tighter ceiling wins), the landlord
explicitly absorbs `cap_absorbed`, the admin fee applies to the *capped* cost so it cannot re-breach
the cap, VAT is per-pool, shares are frozen on re-run so the tie-out cannot drift, a negative true-up
becomes a credit note auto-applied FIFO and a positive one bills immediately on its own invoice.
**This is genuinely good work and the July 2026 gap doc that calls caps absent is stale.**

**Delta.** Steps 1, 2, 3 and 7:
1. `total_actual_expense` and `total_estimated_collected` are **two numbers typed into a form**. No
   GL link, no drill-down, no audit trail for a tenant exercising an audit right.
2. No gross-up — though `Asset` already holds GLA (`totalUnitAreaSqm()`, `occupiedAreaSqm()`,
   `areaOccupancyRate()`, and declared `leasable_area_sqm`); the CAM service just never reads them.
3. Denominator hard-coded to **occupied active-lease** area — the landlord can never elect to
   absorb vacancy, even though the GLA figure to absorb it against is sitting on `Asset`.
4. No re-estimate loop, and the estimate *billed* (the lease's service charge) is disconnected from
   the estimate *reconciled* (`total_estimated_collected`) — two numbers a human must keep equal.
5. One pool per property per year; no per-category pools.
6. No tenant-facing reconciliation statement showing the working.

> **CLOSED — all six.** (1) `SyncCamPoolFromLedgerService` builds the pool **from the GL by
> account**, so every line drills to its postings and pointing an expense category at an account
> inside a pool starts recovering those costs through it. (2) Gross-up and (3) the denominator basis
> are both columns on `cam_expense_pools`, so the landlord can elect to absorb vacancy. (4) The
> re-estimate loop is an action on the pool record (`CamExpensePoolActions`). (5) Pools are
> per-category, keyed by `pool_code` — and a cap now belongs to a **pool**, not to a year
> (`03133a13`, 2026-09-01). (6) `CamStatementPdfService` renders the tenant statement, in the
> reader's own language. Since 2026-09-01 a lease may also carve named accounts out of its own share
> and be excluded from the denominator (Yardi's adjusted basis).

---

## S12 — Percentage rent for a seasonal tenant

**The deal.** A confectioner. Artificial breakpoint 1,000,000/month, rate 8%. 2027 sales: eleven
quiet months averaging 818,000, then 3,000,000 in December (Christmas + New Year). Full year
12,000,000.

**Yardi (cumulative YTD, billed annually in arrears).** YTD sales 12,000,000 vs YTD breakpoint
12,000,000 → **overage = 0**.

**Yardi (period basis, billed monthly).** December alone breaches: (3,000,000 − 1,000,000) × 8% =
**160,000**.

**Atriom.** **Both, per lease.** `percentage_rent_frequency` is `monthly` (breakpoint fresh each
month → 160,000) or `annual` (cumulative → 0). On the annual basis each month carries its canonical
chronological marginal, `overage(YTD) − overage(prior YTD)` floored at 0, so the months sum to the
year's overage and `retrueAnnualYear()` re-attributes them all on any lock or void.

**Delta — CORRECTED 2026-08-09.** This scenario originally read "period basis only… over-bills by
160,000". **That was wrong**, taken from a stale line in module 09 rather than the code. The
capability is built, tested and settable on the lease form.

What is genuinely open is a **data** question, not an engineering one: the column defaults to
`monthly` and **0 of 24** percentage-rent leases are set to `annual` today. If a clause settles
annually, that lease is on the wrong setting — and only reading the clauses will find it.

Still missing here: **tiers**, **deductions/offsets**, and billing on **estimated sales** when a
tenant never declares (today they pay nothing).

> **CLOSED 2026-09-01 — all three of those.** Tiers are `LeasePercentageRentTier` (a `tiered`
> calculation type); deductions are `percentage_rent_deductible_types` resolved per declaration; and
> `sales:estimate-missing` ([`routes/console.php:246`](../../../routes/console.php#L246)) estimates
> from a trailing average for a tenant who never declares — **marked as an estimate and never
> auto-locked**, because billing a figure nobody declared as though they had is the one thing the
> operator must decide.
>
> **The data question in the paragraph above is still open and is still not code.** The column
> defaults to `monthly`; a clause that settles annually is billed on the wrong basis until someone
> reads it. Re-probed 2026-09-01: **24 percentage-rent leases, 0 on `annual`** — unchanged. It
> belongs on the go-live checklist, and no gate can find it.

---

## S13 — An uncollectible tenant

**The deal.** A tenant abandons the unit owing 480,000 across four invoices, two of them from the
prior financial year. Legal advises it is uncollectible.

**Yardi.** A `WRTOFF` charge per open receivable: **Dr Bad Debt Expense / Cr AR**. Revenue stays
recognised in the period it was earned; the loss lands in the period it was recognised as a loss.
AR aging clears.

**Atriom.** There is no write-off path. The two options are both wrong:
- **Cancel the invoices** — reverses the revenue, in the *current* period, including revenue earned
  and recognised last year. Prior-period revenue is understated, this period's is overstated, and
  the bad debt never appears as bad debt.
- **Leave them** — AR aging carries 480,000 of fiction forever, and every collection KPI is wrong.

**Delta.** A missing accounting instrument, not a missing feature. Every mall has bad debt.

> **CLOSED.** `WriteOffInvoiceService` posts **Dr Bad Debt / Cr AR** against an `InvoiceWriteOff`
> row, so revenue stays recognised in the period it was earned and the loss lands where it was
> recognised — Yardi's `WRTOFF`, with the reason recorded. Recovery has a button too:
> `reverse()` re-opens the debt when a written-off tenant later pays, instead of forcing the
> operator to re-bill (double-counting revenue) or book it as miscellaneous income (losing the AR
> history).

---

## S14 — An invoice keyed after the period closed

**The deal.** A recovery invoice dated **28 Feb 2028** is raised on **3 Mar**, after February closed.

**Yardi.** Document date 28 Feb, **post month March**. The tenant's invoice reads February; the GL
sees March; the closed book is untouched. Nobody is blocked, nothing is backdated.

**Atriom.** `PostingDateGuards` refuses it — correctly, because `entry_date` *is* the document date
and February is closed. The operator's only escapes are to reopen February or to re-date the
document to March, which changes the tenant's invoice and its ETA filing.

**Delta.** The guard is right; the missing piece is the **second time coordinate**. Yardi's answer —
"keep the date, move the post month" — is not expressible in Atriom. This is a real workflow blocker
the first time a real close happens on a real deadline.

> **CLOSED (MF-05).** The second coordinate is `posting_month_overrides` — **one table, not a
> `post_month` column on each of the 24 sources** — set through `SetPostMonthService` and offered on
> every source document by one shared action factory (`App\Filament\Actions\PostMonthAction`), so
> the behaviour cannot drift into 24 slightly different versions of itself. The tenant's invoice
> keeps 28 Feb; the GL sees March; February stays closed.

---

## S15 — Straight-line rent over the whole term

**The deal.** S1's lease, simplified to 3 years: 3 months free, then 100,000/month escalating 7%.
Total cash over the term 3,557,880; straight-line 98,830/month.

**Yardi.** Recognises 98,830 every month from month 1, including the three rent-free months, and
posts the difference to deferred/accrued rent. The balance peaks at 296,490 and unwinds to zero at
expiry. Tenant invoices are unaffected.

**Atriom.** Revenue = the invoice as issued. Months 1–3 recognise **zero**; year 3 recognises
114,490/month.

**Delta.** Under IFRS 16 / **EAS 49** lessor accounting, Atriom's books understate early-term
revenue and overstate late-term revenue, **systematically and on every lease** — because every
Atriom lease has both escalation and fit-out grace by default. Whether that matters is the
accountant's ruling, and it is question 1 of this cycle
([02 §7](02-yardi-money-flow.md#7-straight-line-rent--the-lessors-revenue-recognition)). What is
*not* optional is that the prerequisite — a forward rent schedule — is the same prerequisite as
everything else in this document.

> **CODE CLOSED; the ruling is still the accountant's.** The prerequisite shipped (S1), and so did
> the engine: `accounting:post-straight-line-rent`
> ([`routes/console.php:74`](../../../routes/console.php#L74)) recognises the level charge and posts
> the deferred/accrued difference. **It ships switched OFF**, because whether EAS 49 straight-lining
> applies to these leases is a ruling, not a default — flipping the setting is the whole deployment.
> It is question 1 of the accountant briefing and it is still unanswered.

---

## What the fifteen say together

**The table below is the diagnosis as it stood when these fifteen were written, kept because the
*reasoning* is what a future change has to respect.** Every row is closed; the right-hand column
names what closed it, re-verified against the code 2026-09-01.

| Root cause | Scenarios it broke | Closed by |
|---|---|---|
| **No rent/charge schedule** (state, not schedule) | S1 S3 S4 S5 S6 S9 S15 | `ChargeScheduleService::projectTermEscalations()` at every origination point + `atriom:project-lease-schedules` for the backfill |
| **No lease events / amendments** | S5 S6 S9 | append-only `lease_events`, eight amendment types, narrative resolved at read time |
| **No options / critical dates** | S7 | `LeaseOption` + `leases:scan-option-windows` on the **notice window**, not on expiry |
| **Proration is one-ended, and the bulk run never prorates** | S2 S8 | `prorate: true` on the bulk path; `ProrationMethod` (four methods, per-lease) + per-charge `prorate`; `CreditUnearnedBillingService` for the trailing end |
| **No final account at move-out** | S8 | `MoveOutStatementService` + `SettleMoveOutService` netting through `DepositApplication` |
| **No write-off** | S13 | `WriteOffInvoiceService` (Dr Bad Debt / Cr AR) with a recovery reversal |
| **No post month** | S14 | `posting_month_overrides` + `SetPostMonthService` + one shared `PostMonthAction` |
| **Recoveries: pool not from the GL, no gross-up, fixed denominator, no re-estimate** | S11 | `SyncCamPoolFromLedgerService`; gross-up and denominator basis as pool columns; re-estimate on the pool record; per-category pools |
| **Percentage rent: no cumulative basis, no tiers, no deductions, no estimated sales** | S12 | cumulative annual basis; `LeasePercentageRentTier`; `percentage_rent_deductible_types`; `sales:estimate-missing` |
| **Invoice-level AR with no item-level allocation** | S10 | `invoice_item_payment` + `invoice_items.disputed_at`, derived from `paid_amount` so it is not a fifth channel |
| **Independent bug: CAM reads master-unit area only** | S5 | `totalAreaSqmForPeriod()` — the pivot summed, day-weighted, on both sides of the share |

**Seven of fifteen traced to one missing structure, and that structure was built.** The schedule was
made phase 1 and almost everything else did follow from it — which is the strongest evidence this
document was worth writing, and the reason it must not be read as a live defect list.

**What these fifteen still leave open is not code:**
- **S12's data question** — 24 percentage-rent leases on the `monthly` default; only reading the
  clauses will find the ones that settle annually. Go-live checklist, not a gate.
- **S15's ruling** — whether EAS 49 straight-lining applies here. The engine ships off, awaiting the
  accountant.
- **S1's fit-out question** — whether the service charge and marketing levy really should be
  suppressed during a rent-free fit-out, or only the rent. An operator decision, re-raised here
  because the far more common mall deal is *rent-free, service charge payable*.
