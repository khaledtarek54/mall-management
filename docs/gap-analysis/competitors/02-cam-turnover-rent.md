# CAM reconciliation & turnover rent — Atriom vs the mall specialists

> Domain 2 of the competitor gap analysis. Benchmarks **Yardi Voyager** (CAM/expense recovery is its
> crown jewel: budget → estimate → reconcile → recover, with caps/gross-ups/admin fees) and
> **Re-Leased** (commercial PM; "outgoings" recovery + turnover rent). Atriom side is grounded in
> `docs/modules/08-cam.md`, `docs/modules/09-tenant-sales-percentage-rent.md`,
> `docs/money/04-cam-reconciliation.md`, `docs/money/05-percentage-rent.md`, and the services they
> cite (`CamReconciliationService`, `PercentageRentCalculationService`). Competitor cells are from
> general product knowledge (cutoff ~Jan 2026) and marked **(verify)** where version/edition/pricing-
> sensitive. Frame: a **single-entity, EGP, multi-property Egyptian mall operator**.

**Legend:** ✅ full · 🟡 partial · ❌ absent · ⏭️ N/A or deferred.

## 1. Capability matrix

| Capability | Atriom | Yardi Voyager | Re-Leased | Gap note (who's ahead, does it matter here) |
|---|---|---|---|---|
| CAM/recovery pool per property·year | ✅ `CamExpensePool`, unique `(asset_id, period_year)` | ✅ | ✅ *(verify)* | **Even on existence.** |
| Pool sourced from posted GL/AP expense accounts | ❌ two hand-keyed totals (`total_actual_expense`, `total_estimated_collected`) entered by accounting | ✅ expense accounts roll into recovery pools *(verify)* | 🟡 budget-vs-actual outgoings *(verify)* | **Yardi.** No drill from a CAM charge to the GL expenses it recovers; re-keying is manual + error-prone. |
| Multiple pools / expense categories per property | ❌ one pool = one annual number | ✅ expense groups, many recovery clauses *(verify)* | 🟡 *(verify)* | **Yardi.** Can't recover different cost buckets on different bases. |
| Pro-rata allocation by leased area | ✅ `area_sqm ÷ Σ active-lease area`, 4-dp share | ✅ | ✅ *(verify)* | **Even.** |
| Configurable allocation basis (GLA vs occupied, fixed %, custom denominator) | ❌ hard-coded to **occupied** active-lease area (changing basis = code edit per the module's extension-point doc) | ✅ multiple share methods *(verify)* | 🟡 *(verify)* | **Yardi.** Denominator is occupied-only, so vacancy is implicitly absorbed by sitting tenants — landlord can't elect to eat it. |
| Pool exclusions / capped-tenant handling | ❌ `cap_amount`/`exclusions` columns exist on `CamAllocation` but are **never read** by the service (schema-present, unwired) | ✅ *(verify)* | 🟡 *(verify)* | **Yardi.** Anchor exclusions / carve-outs unsupported. |
| Recovery caps (annual-increase, cumulative/compounding) | ❌ | ✅ *(verify)* | 🟡 *(verify)* | **Yardi.** Controllable-CAM caps are standard lease terms. |
| Gross-up to an occupancy assumption (e.g. 95%) | ❌ no GLA/occupancy denominator exists at all | ✅ *(verify)* | 🟡 *(verify)* | **Yardi.** |
| Admin / management fee add-on (% on recoverable) | ❌ | ✅ *(verify)* | 🟡 *(verify)* | **Yardi.** A 10–15% CAM admin fee is routine mall revenue Atriom can't book. |
| Base year / expense stop | ⏭️ office-lease concept, marginal for EG retail malls | ✅ *(verify)* | 🟡 *(verify)* | **N/A-ish here.** |
| Estimate-vs-actual **annual true-up** → recovery invoice / credit | ✅ positive → **immediate** `issued` recovery invoice; negative → `CreditNote` auto-applied FIFO (never a floored negative charge) | ✅ | 🟡 *(verify)* | **Even — Atriom's settlement is genuinely well-engineered** (handles ended-lease leak). |
| Monthly recovering **estimate** (auto-bill + re-forecast) | 🟡 estimate is collected in service charges through the year but there's no CAM estimate schedule object; `total_estimated_collected` is keyed at reconciliation | ✅ monthly recovery estimate, re-forecast mid-year *(verify)* | ✅ *(verify)* | **Yardi.** |
| VAT treatment of recoveries | 🟡 true-up booked VAT-free (`vat_applicable=false`); the monthly service-charge estimate *does* carry 14% VAT — split treatment | ✅ configurable *(verify)* | ✅ *(verify)* | **Nuance, not a hole** — matches the project's rent-exempt / service-charge-taxable rule. |
| Idempotent, lock-safe true-up + books tie-out gate | ✅ `lockForUpdate` re-checks, `BooksReconciliationService` CAM check, `billing:reconcile` gate + ETA e-invoice on the recovery invoice | 🟡 batch recovery + audit reports *(verify)* | 🟡 *(verify)* | **Atriom edge on control discipline + Egyptian e-invoicing.** |
| Percentage / turnover rent | ✅ per-lease config | ✅ | ✅ turnover rent *(verify)* | **Even.** |
| Breakpoint types: artificial **and** natural | ✅ both; null defaults to artificial, floors at 0 | ✅ | 🟡 *(verify)* | **Even / slight Atriom.** |
| Tiered / multiple breakpoints (marginal rates) | ❌ single rate above one breakpoint (a 3rd formula = code change) | ✅ *(verify)* | 🟡 *(verify)* | **Yardi.** |
| Annual / YTD-cumulative breakpoint (monthly-on-account, annual reconcile) | ❌ each period computed **independently** vs a per-period threshold; no YTD cumulative, no annual turnover reconcile | ✅ *(verify)* | 🟡 *(verify)* | **Yardi — real retail gap.** Standard leases set an annual breakpoint paid monthly and trued-up. |
| Tenant sales declaration capture (self-service + audit) | ✅ portal **+ mobile API**, file-first upload, staff keys the figure, lock/dispute/void, activity log | ✅ | 🟡 *(verify)* | **Even / Atriom-fit** (mobile + file-first suits Egyptian tenants). |
| POS / automated sales feed | ❌ sales arrive as an uploaded PDF/image; `declared_sales` is hand-keyed | ✅ Yardi Retail / POS import *(verify)* | 🟡 via integrations *(verify)* | **Yardi.** |
| Sales analytics (occupancy-cost ratio, sales/m², unreported-sales) | ❌ | ✅ *(verify)* | 🟡 *(verify)* | **Yardi.** Operators lease-manage on sales/m². |
| Immediate overage billing + revenue-leak guards | ✅ own `issued` invoice, monthly-run exclusion, concurrency `lockForUpdate`, paid-invoice void block | ✅ | 🟡 *(verify)* | **Even / Atriom edge on correctness engineering.** |

## 2. Architecture read

**Atriom is a strong *settlement* engine bolted onto a *primitive* recovery-clause model.** The
money path — `CamReconciliationService` and `PercentageRentCalculationService` — is arguably better
engineered than what a specialist exposes: the positive true-up settles on an **immediate issued
recovery invoice** (not a back-dated `one_time` charge the monthly run would strand for an
ended-term tenant — the exact revenue-leak Yardi users hit when a lease rolls off before recovery);
the negative true-up is a **`CreditNote` auto-applied FIFO**, deliberately *not* a negative charge
that `Invoice::recomputeTotals()` would floor to zero; every step is `lockForUpdate`-idempotent; and
an independent `BooksReconciliationService` CAM check + the `billing:reconcile` tie-out gate assert
the recovery actually reached AR before a close or tax filing. That control discipline, plus **ETA
e-invoicing and correct EGP/VAT** on the recovery invoice, genuinely *matches or exceeds* the
specialists on the back half of the workflow — the part where a naive implementation loses money.

**The front half — the recovery-*clause* engine — is where Atriom is thin, and it's precisely
Yardi's crown jewel.** A CAM "pool" here is **two numbers a human types in** (actual + estimated),
not a roll-up of posted expense accounts; there is **one** pool per property per year (no cost
categories, no per-clause bases); the allocation basis is **hard-coded to occupied leased area**;
and caps, gross-ups, admin fees, and pool exclusions are **absent or schema-only** (`cap_amount` and
`exclusions` sit on `CamAllocation` unread — the module doc itself flags them as not wired). Real
Egyptian mall leases negotiate exactly these: anchor-tenant caps, a management/admin fee on the
recoverable, controllable-CAM increase caps, and gross-up to an occupancy assumption. Atriom cannot
express any of them today without code. Because the denominator is occupied-area-only, it also can't
model "landlord absorbs vacancy" — sitting tenants always split 100% of actual cost.

**Turnover rent mirrors the same shape: correct core, shallow retail depth.** Both breakpoint types
(artificial + natural) are implemented, floored, rounded, and the lock → bill → void/re-lock
lifecycle is hardened against double-bill and stale-snapshot races — solid, mall-appropriate work.
But it is a **per-month, single-rate, single-breakpoint** calculation: no tiered/marginal rates and
**no YTD-cumulative annual breakpoint** (the retail-standard "pay monthly on account, reconcile the
annual breakpoint at year end"), so leases written that way are mis-billed. Sales capture is
**file-first + mobile** — a deliberate, sensible fit for Egyptian tenants who won't expose a POS
feed — but that means **no automated POS integration and no sales analytics** (occupancy-cost ratio,
sales/m², unreported-sales estimation) that a Yardi retail operator leans on for leasing decisions.

**Net architectural call:** the derived-truth + tie-out spine is sound and, on settlement + audit +
Egyptian compliance, ahead of the field. The gap is real and clustered on the **clause/configuration
layer** (CAM recovery terms; annual turnover mechanics; sales data ingestion) — completeness, not a
rewrite.

## 3. Top 5 gaps for a mall operator

1. **No CAM recovery-clause engine — caps, gross-ups, admin fee, exclusions, configurable basis.**
   *What:* the negotiated levers of a real recovery clause are absent (or schema-only). *Why it
   matters:* anchor caps, a 10–15% CAM admin fee, and gross-up-to-occupancy are standard mall lease
   economics and real landlord revenue Atriom can't book or honor. *Effort:* **L.**
2. **CAM pool is a hand-keyed total, single-category, per year.** *What:* no roll-up from posted
   GL/AP expenses, no cost categories, no drill-through. *Why:* error-prone re-keying, no audit line
   from recovery back to the expenses recovered, and no ability to recover different pools on
   different bases. *Effort:* **M–L.**
3. **No YTD/annual cumulative turnover breakpoint + no tiered rates.** *What:* each month is
   computed independently against a per-period threshold; only a single rate above one breakpoint.
   *Why:* the retail-standard annual-breakpoint-paid-monthly lease is mis-billed, and tiered
   percentage schedules can't be expressed. *Effort:* **M.**
4. **No POS/automated sales feed + no sales analytics.** *What:* sales come as uploaded PDFs a staffer
   keys by hand; no occupancy-cost ratio or sales/m² reporting. *Why:* manual keying doesn't scale to
   a full mall, and operators lease-manage on sales-per-area — a blind spot today. *Effort:* **M**
   (feed) + **M** (analytics).
5. **No first-class monthly CAM estimate / re-forecast.** *What:* the "estimate collected" is just a
   number typed at reconciliation; there's no estimate schedule that auto-bills and re-forecasts
   mid-year. *Why:* operators bill and adjust a monthly recovery estimate throughout the year; Atriom
   has no object for it. *Effort:* **M.**

## 4. Net verdict

**Behind** the specialists on the CAM recovery-clause engine (caps / gross-ups / admin fees /
configurable basis / expense-account pooling) and on retail turnover depth (POS feed, annual
cumulative breakpoints, sales analytics); **at-par-to-ahead** on true-up *settlement* correctness,
lock-safe idempotency + books tie-out discipline, and Egyptian fit (ETA e-invoicing, VAT/EGP,
mobile file-first sales capture).
