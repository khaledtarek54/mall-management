# Atriom — the gap analysis

> **One document.** What the benchmark does that Atriom does not, what Atriom does that the
> benchmark does not, and what is deliberately declined. **Re-verified against the code
> 2026-08-19.**
>
> **It replaces nine overlapping documents** — the per-module round-1/2/3 audits, the Yardi
> row-by-row, the Yardi phase plan, the competitor deep-dives, the Odoo comparison, the
> property+facility closure ledger, the accounting gap analysis and the final pre-staging sweep's
> parity lens. Those said the same things in different states of staleness, and the staleness was
> the problem: three separate readers were sent to rebuild something that already shipped.

**The benchmark, and where it applies.**

| Domain | Benchmarked against | Reference material |
|---|---|---|
| Leasing · charges · AR · recoveries · percentage rent · reporting · GL | **Yardi Voyager Commercial** — the deepest lease-administration and recoveries engine in the market | [`docs/benchmarks/yardi/`](../benchmarks/yardi/README.md) |
| Facility · work orders · SLA · assets · parts · procurement · vendors | **Facilio · ServiceChannel · IBM Maximo · Fiix · MaintainX** — the FM/CMMS/EAM specialists | this document, §4 |
| Generic ERP — GL · AP · inventory · fixed assets · HR · treasury | **Odoo Community + Enterprise** | this document, §5 |
| Post-dated cheques (module 33) | **no benchmark exists** — a MENA instrument the Western tools do not model | judged against Egyptian practice, and says so |

---

## 0. How to read this

**Verdicts**

| | Meaning |
|---|---|
| ✅ **KEEP** | At or above the benchmark. Do not touch it |
| ➕ **EXTEND** | The structure is right; it is missing capability |
| ♻️ **REBUILD** | The *model* is wrong. Fixing it inside the current shape produces a worse system |
| ⏭️ **DECLINE** | The benchmark does it, Atriom should not. Reason stated |
| ❓ **DECIDE** | Blocked on a business or accounting ruling, not on engineering |

**Severity** — 🔴 wrong money or a blocked workflow · 🟠 real operator pain or a misstatement ·
🟡 capability gap · ⚪ cosmetic or none.

### The one rule this document is written under

> **A gap row is a claim about code. Check it against code.**

This is not a style note. Every published version of this analysis has carried rows that were
false in one direction or the other, and the cost is asymmetric — a *missing* row costs a feature
nobody built, a *stale* row costs a day rebuilding something that already works, plus the
confidence lost when the rebuild collides with the original.

The failures are on record. The July 2026 competitor analysis ranked "automated rent escalation"
and "recovery caps" as top-five gaps; both shipped before it was published. The August Yardi
analysis produced two false gaps — CAM caps and cumulative percentage rent — and both came from
trusting a module doc instead of grepping. The roadmap's 2026-08-18 verification sweep found
**thirteen** rows claiming something was missing that the code already had, one of them the
#2-priority item.

**Writing a row here means grepping first and saying what you grepped** — and grepping for the
CAPABILITY, not for the name you would have given it. Four capabilities came back "absent" on a
first search here and were present under another name: low-stock alerting (`LowStockAlert`, not
`low_stock`), asset criticality (`Equipment::CRITICAL`), the post-month override
(`posting_month_overrides`), and the three-way match (`PurchaseRequest::billingVariance()`, not
`match_status`).

The last of those was published as an open row in this document and corrected within the day —
so this is not a rule the document has earned the right to state from above. An absence claim is
a hypothesis until the capability has been searched for under the names someone else would
plausibly have chosen.

### What this document is not

It is not the go-live gate ([`operations/GO-LIVE.md`](../operations/GO-LIVE.md) — configuration,
credentials and decisions), not the prioritised worklist ([`ROADMAP.md`](../ROADMAP.md)), and not
the per-module reference ([`modules/`](../modules/)). This answers one question only: *measured
against the software a mall operator would otherwise buy, what is missing?*

---

## 1. The verdict, in one page

**Atriom is not a smaller Yardi. It is a mall billing-and-books engine that grew a competent
facility arm.** It wins on exactly the half the specialists push out to an external finance
system: a real double-entry general ledger *underneath* the property engine, so a CAM true-up, a
security deposit, an inventory movement, a depreciation run and — the signature move — a **vendor
SLA penalty charged straight onto the AP bill** are all first-class, auditable, property-dimensioned
money events, in EGP, with Egyptian tax treatment and bilingual books.

**Where it is at or above the benchmark**

| | Why |
|---|---|
| **Settlement correctness** | `Invoice::recomputeTotals()` is one derivation over four channels; both over-allocation guards read under a lock; a credit note **un-applies the original** instead of stacking a second document |
| **Tie-out discipline** | Every money source is in one registry with a build-failing gate; four dispatch paths derive from it; the reconciliation checks are themselves mutation-tested so a check that *cannot fail* fails the build |
| **Money-record immutability** | Deletion refused at the model, per-model with a stated correction path, gated in CI. Yardi uses soft controls |
| **Property isolation** | One `asset_id` dimension, conformance-gated on reads, writes and pickers. One operator runs many malls off one chart |
| **Egyptian fit** | Dated tax-rate ladders (VAT · stamp · schedule · withholding, both directions), base rent VAT-exempt, 5% marketing levy, ETA e-invoicing pipeline. **No Western benchmark ships any of it** |
| **Post-dated cheques** | Full register, lodging, maturity, clear/bounce with an invoice lock. Not a parity item — nothing in the benchmark set models it |
| **CAM settlement hygiene** | Positive true-up bills immediately on its own invoice (so it cannot strand on a rolled-off lease); negative becomes an auto-applied FIFO credit; participant set and shares frozen on re-run |

**Where it is behind**

Three clusters, and none of them is the money core:

1. **Lease-document lifecycle** — no generation, no template merge, no e-signature, no clause
   abstract. Leases only *hold* an uploaded PDF. Re-Leased owns this workflow.
2. **The field and vendor edge** — no technician mobile app, no vendor self-service surface, no
   in-house labour cost on a work order, no reliability analytics. The CMMS specialists are
   mobile-first here.
3. **Forward-looking analytics** — budget-vs-actual ships, but there is no revenue *forecast*
   projected from the charge ladder, and no reliability history to forecast failure from.

**The honest one-line:** the books, the settlement invariants and the Egyptian fit exceed the
specialists; the lease-document lifecycle and the field/vendor edge are behind; and the money core
should not be reopened to close either.

---

## 2. The complete open list

Everything not closed, ranked. **This is the only table most readers need** — §3–§5 are the
evidence behind it.

**Owner** — 🧑‍💻 code (buildable now) · 🔑 external (a decision or credential someone else holds) ·
⚙️ ops.

### 🔑 Blocked on a decision — small in code, unstartable without the answer

| # | Gap | Module | Blocked on | Effort |
|---|---|---|---|---|
| B1 | **Management fee is recorded and charged by nothing** | 32 · 37 | The accountant: which GL account takes management-fee income (it is Eltizam's revenue, not the property's — a guess puts the operator's income in the owner's P&L), and the sinking-fund liability account. [OPEN-QUESTIONS §B2.1/B2.2](../OPEN-QUESTIONS.md) | M |
| B4 | **Live tenant payment rail** | 06 | Paymob certification — external, not code. Ships `PAYMOB_ENABLED=false` | — |
| B5 | **ETA e-invoicing is live-blocked** | 16 | A signing certificate and production credentials. Ships `ETA_MOCK=true` | — |

**On B1:** the interim is honest rather than silent — the field states that the fee is recorded and
not billed, pinned by `UnitOwnerManagementFeeIsNotChargedYetTest`, which fails the day someone
builds it. This is the one row where Atriom is behind **both** Yardi Investment Manager and
AppFolio on the deliverable that sits at the centre of the operator-for-owner relationship.

**On B3, read the distinction:** this is *not* the lease-shaped-assumption class that produced
three defects in module 37. Those inferred a lease where the money core already accepted a
`BillableAgreement`, so the fix was a lookup. Here the **relationship itself** is lease-shaped —
a pivot column, a migration and a backfill.

### 🧑‍💻 Open, buildable now — ranked by what an operator would feel

| # | Gap | Module | Benchmark | Sev | Effort |
|---|---|---|---|---|---|
| O1 | **Lease document generation + e-signature** — a lease only holds an uploaded PDF; nothing generates or signs one. A daily workflow for an operator onboarding continuously | 04 | Re-Leased, Yardi Smart Lease | 🟡 | M–L |
| O2 | **Vendor self-service portal** — no accept/quote/update/evidence loop; dispatch is an internal column change. Needs its **own** permission set, not `imports.execute` | 12 · 26 | ServiceChannel (the product *is* this loop) | 🟡 | L |
| O3 | **Field-technician mobile app** — `/api/v1` is tenant-facing; engineers close work orders from a desktop screen | 20 · 26 | Facilio · MaintainX · Limble, all mobile-first | 🟡 | L |
| O4 | **Fit-out permit: conditions on the grant** — the decision ships (approve/reject with a mandatory reason, recorded); what is missing is *what was granted* — permitted hours, a security deposit, contractor details, and an audit trail of the permit itself rather than of the request carrying it | 11 | ServiceChannel compliance | 🟡 | M |
| O7 | **Capex bid / quote comparison** — one vendor per request, no tender, no "three quotes compared" on tier-3 spend. A governance gap owners ask about | 29 | Maximo · Odoo Enterprise | 🟡 | M |
| F1–F8 | **The facility & operations close-out** — the work order as a cost object, trade as master data, PM compliance, tenant confirmation, failure codes, NTE/proposals, routes, job-plan estimates. Ranked, with reasons, in [§4](#4-facility-vendors-assets--vs-the-fm-standard); benchmarked in [docs/benchmarks/fm/](../benchmarks/fm/) | 26 · 11 · 12 · 22 · 23 · 29 · 30 | Maximo · ServiceChannel | 🟠 | L |
| O16 | **Mobile / barcode parts issue + guided cycle counts** — issue, receive and count are web forms; counts are ad-hoc adjustments with no guided workflow or freeze | 22 | every CMMS | 🟡 | M |
| O18 | **Repeat-violation ladder** — fines are priced by hand; the register makes the pattern visible and nothing reads it | 31 | none | ⚪ | S |

### ⚙️ Hygiene the analysis argues for

| # | Item | Why | Effort |
|---|---|---|---|
| H1 | **Switch on `FixtureColumnsExistConformanceTest`** | Built on a real AST walk and **correct**; it ships `skip()`ed because the tree it landed in has ~58 pre-existing ghost fixture keys. Turning the build red on an inherited backlog is how a gate gets deleted rather than obeyed. Clear the ghosts in one pass — each rename can change what a test proves — then remove the skip | S–M |
| H2 | **A workability gate** | Reachability proves a service can be *started*; it does not prove it *works*. The NSF fee was reachable and refused every real input. Two regex shapes were prototyped and both failed (one needs to understand PHP, the other mis-attributed 820 "ghosts") — the AST approach in H1 is the viable route | M |
| H3 | **Search `LIKE` at scale** | Every blob search is a leading-wildcard `LIKE` — correct, unindexable, and never measured at real row counts. Measure before optimising | S |

---

## 3. Leasing, AR and the money core — vs Yardi Voyager Commercial

> Rows verified against the code across 2026-08-09 → 08-11 during the Yardi cycle, and the open
> ones re-verified 2026-08-19. **All 43 stories of that cycle shipped**; the closed rows are kept
> because a scorecard rewritten to say "done" everywhere teaches nothing about what was hard.

### 3.1 The lease record

| Capability | Yardi | Atriom | Verdict | Sev |
|---|---|---|---|---|
| Lifecycle state **and** type as separate axes | Status × type | `previous_lease_id` was always in the database; `Lease::leaseType()`/`isRenewal()` now derive the type axis — **never stored**, because a `lease_type` column would disagree the first time a renewal was created by a path that forgot to set it | ✅ KEEP | ⚪ |
| Occupancy as a projection of lease state | ✅ | `LeaseObserver` → `Unit::recomputeStatus()`, plus `leases:expire` on a timer — because a stored function of *today* goes wrong on a day when nothing happened | ✅ KEEP | ⚪ |
| Multi-unit lease, date-ranged spaces | ✅ | `lease_unit` carries `effective_from`/`effective_to`; CAM apportions on time-weighted area | ✅ KEEP | ⚪ |
| Per-space rent | ✅ | Flat amount **or** EGP/m²/yr, re-derived when area moves | ✅ KEEP | ⚪ |
| The six dates | ✅ | `possession_date` + `rent_commencement_date` added, `fit_out_months` **dropped** with an exact backfill so no lease changed what it bills. Sign date is unmodelled and drives nothing | ✅ KEEP | ⚪ |
| Area as a first-class, date-ranged number | ✅ | `Lease::totalAreaSqmOn($date)` and the time-weighted period form; `unit_areas` versions the unit's own measurement so remeasuring a shop cannot shift the area a past period was billed on | ✅ KEEP | ⚪ |
| Double-booking prevention | ✅ | `lockForUpdate()` on the **contended unit row**, and the guard behind the lock is itself a locking read — a plain read there answers from the pre-wait snapshot under MySQL REPEATABLE READ | ✅ KEEP — above benchmark | ⚪ |
| Terminal-lease immutability | ✅ | `Lease::updating` blocks non-allow-listed changes once terminal | ✅ KEEP | ⚪ |
| Lease documents · abstraction · clause library | ✅ | Media upload on a private disk, gated. Nothing generates, merges or signs | ➕ **EXTEND — O1/O9** | 🟡 |

### 3.2 Charges

| Capability | Yardi | Atriom | Verdict | Sev |
|---|---|---|---|---|
| Date-ranged charge schedule, many rows per code | ✅ | `charges` is date-ranged; `ChargeScheduleService` closes the row in force and opens the next; the whole ladder is written at signing | ✅ KEEP | ⚪ |
| Billing reads the row effective for the period | ✅ | `chargeAppliesToPeriod()` honours `start_date`/`end_date` | ✅ KEEP | ⚪ |
| Charge codes as configuration | ✅ | `charge_codes` — code, bilingual label, posting role, active, order, with its own screen. Adding "key money" is a row, not a deploy. **Behaviour stays in code**: codes the engine has logic for remain `InvoiceItemType` constants and a gate asserts the catalogue covers every one | ✅ KEEP | ⚪ |
| Escalation: stored (%, amount, floor/ceiling) | ✅ | `escalation_floor_rate`/`escalation_ceiling_rate` clamp the rate before it applies; fixed-**amount** escalation ships too. A floor above the ceiling is refused at the model | ✅ KEEP | ⚪ |
| Escalation: applied automatically | ✅ | `leases:apply-escalations` — idempotent, row-locked, re-checked in-transaction, and it **appends** a schedule row instead of overwriting one | ✅ KEEP | ⚪ |
| CPI / index escalation | ✅ with a feed | Absent by design — no feed, and the system refuses to invent a number | ➕ **EXTEND — O14** | 🟡 |
| Free rent / abatement | Per code, date-ranged | `fit_out_scope` — `rent_only` (net abatement, default) or `gross`. Per-charge-code abatement rows remain future work | ✅ KEEP | ⚪ |
| Billing frequency | Per charge row | Per lease (monthly/quarterly/semiannual/annual), cycle-anchored, billed in advance, capped at expiry | ✅ KEEP | ⚪ |
| Proration — both ends | ✅ | `monthsCovered()` is the ONE rule for both the bill and the trailing credit | ✅ KEEP | ⚪ |
| Holdover billing | ✅ 125–200% | `ConvertLeaseToHoldoverService` at the contracted multiple (default 150%, settings-driven). Operator-confirmed, never automatic | ✅ KEEP | ⚪ |
| Batch review before posting | ✅ edit the batch | `/admin/billing-run-preview` — a dry run computed by the same `planInvoiceForLease()` the post persists. Yardi lets you EDIT the batch; Atriom's is review-then-commit | ✅ KEEP | ⚪ |
| Double-bill prevention | Batch + post month | `Cache::lock` on the period + `WithoutOverlapping` + a period-overlap guard, and the manual action contends on the same lock | ✅ KEEP — triple defence | ⚪ |

### 3.3 Amendments, events, options

| Capability | Yardi | Atriom | Verdict | Sev |
|---|---|---|---|---|
| Mid-term amendment as a first-class record | ✅ five kinds | Append-only `lease_events` with all five plus abatement and termination | ✅ KEEP | ⚪ |
| Point-in-time reconstruction | ✅ | `Lease::eventsAsOf($date)` + the date-ranged schedule and premises | ✅ KEEP | ⚪ |
| Renewal | Amendment **or** new record | New lease chained by `previous_lease_id`, carrying the unit set and duplicating charges | ✅ KEEP — defensible for a mall | ⚪ |
| Termination with a final account | ✅ | `MoveOutStatementService` draws it and **names the figures not yet knowable** rather than quietly omitting them; `SettleMoveOutService` disposes of the deposit in one act and freezes the account | ✅ KEEP | ⚪ |
| Options with notice windows | ✅ | `LeaseOption` + `leases:scan-option-windows` daily — opening · closing · lapsed | ✅ KEEP | ⚪ |
| Space encumbrance | ✅ | An option marks the unit spoken-for; the picker **warns rather than blocks**, because a landlord may legitimately let encumbered space | ✅ KEEP | ⚪ |
| Tenant insurance-certificate expiry | ✅ | `tenant_documents` + `tenants:scan-document-expiry` — COI, بطاقة ضريبية, سجل تجاري, رخصة تشغيل, خطاب ضمان, each with expiry and a private copy. Deliberately **non-blocking**: unlike a vendor COI there is no dispatch decision to gate — you cannot un-let a shop over a lapsed policy | ✅ KEEP | ⚪ |

### 3.4 AR, cash and deposits

| Capability | Yardi | Atriom | Verdict | Sev |
|---|---|---|---|---|
| Single source of truth for settlement | Charge open balance | `Invoice::recomputeTotals()` over **four** channels — payments, credit notes, tenant credit, netted deposit | ✅ **KEEP. Do not touch** | ⚪ |
| Over-allocation race guard | — | `lockForUpdate` + re-check inside the pivot-sync transaction, and both guards read under the lock | ✅ KEEP — above benchmark | ⚪ |
| Credit-note reversal | Offsetting charge | **Un-applies the original** via `credit_note_applications` rather than stacking a second document | ✅ KEEP — better than benchmark | ⚪ |
| On-account / open credit | Auto-applies to next charge | `TenantCreditApplication` — its own dated GL document, auto-applying on issue. Configurable, because a credit raised in dispute should not silently disappear into next month's rent (Voyager makes it configurable for the same reason) | ✅ KEEP | ⚪ |
| Receipt application order | Credits → priority → oldest | `InvoiceItemSettlement::TYPE_PRIORITY` — rent first, penalties last — with explicit per-line allocation overriding it | ✅ KEEP | ⚪ |
| Item-level allocation | Charge-level natively | `invoice_item_payment`, **derived** from `paid_amount` so item outstandings always sum to `balance` | ✅ KEEP | ⚪ |
| Line-level dispute | ✅ | `invoice_items.disputed_at` — out of the late-fee base, shown beside the aged figure, visible on the portal | ✅ KEEP | ⚪ |
| Bad-debt write-off | ✅ | `InvoiceWriteOff` — its own GL source, reversible | ✅ KEEP | ⚪ |
| Late fees | Per code, per-lease override | Idempotent, lock-safe, per-lease grace/rate/minimum overrides | ✅ KEEP | ⚪ |
| Bounced / NSF | Reverses + fees | No `Payment` exists until a cheque clears, so a bounce has nothing to un-apply — **better than Voyager**, which enters a receipt then reverses it. The fee half ships as `BillBouncedChequeFeeService` + an `nsf_fee` charge code, defaulting to 0 = off | ✅ KEEP | ⚪ |
| Post-dated cheques | 🟡 regional builds | Full register, lodging, maturity, clear/bounce, invoice lock + over-allocation backstop | ✅ KEEP — exceeds the benchmark for this market | ⚪ |
| AR aging | By charge code | Bucket summary + drill-down + per-tenant collections worklist + aging **by charge type** | ✅ KEEP | ⚪ |
| Deposit as a GL liability, refund/forfeit | ✅ | `DepositTransaction` journalized, numbered, bilingual; both disposal paths posted | ✅ KEEP | ⚪ |
| Itemised move-out disposition | ✅ one document | One statement nets AR against the deposit and freezes into the termination event | ✅ KEEP | ⚪ |
| Deposit requirement tracks rent | 🟡 Yardi tracks the requirement against rent | ✅ **ROW CORRECTED 2026-08-19 — built, and at a better seam than a form.** `security_deposit_months` re-derives `security_deposit` in `Lease::saving`, so the escalation sweep, the Change Rent action, a renewal, the importer and the API are all covered by one hook — only one of those five writers is a form. **Null means flat and nothing moves**, deliberately: a deposit agreed as a sum unrelated to rent is a real deal, and dividing deposit by rent to infer a multiple would invent a term nobody agreed to | ✅ KEEP | ⚪ |
| Bank deposit batches | ✅ | Absent | ⏭️ **DECLINE** — PDCs and transfers dominate this market | ⚪ |
| Interest-bearing / segregated deposits | ✅ | Absent | ⏭️ **DECLINE** — not an Egyptian requirement | ⚪ |

### 3.5 Recoveries and percentage rent

| Capability | Yardi | Atriom | Verdict | Sev |
|---|---|---|---|---|
| Pool sourced from GL expense accounts | ✅ with drill-down | `cam_pool_accounts` + `expense_basis = ledger`; the total is a query over posted lines | ✅ KEEP | ⚪ |
| Multiple pools per property | ✅ | Keyed `(asset_id, period_year, pool_code)`, each with its own participants, fee, VAT and cap | ✅ KEEP | ⚪ |
| Configurable denominator | GLA / occupied / fixed | `denominator_basis`, frozen onto the pool as `denominator_used_sqm` | ✅ KEEP | ⚪ |
| Gross-up | ✅ | `gross_up_pct` × the variable share, derived per **account** from `cost_nature` | ✅ KEEP | ⚪ |
| Caps — absolute, YoY, base year, compounding | ✅ | `LeaseCamTerm`, effective-dated, tighter ceiling wins, landlord absorbs `cap_absorbed`. **The 2026-07-18 analysis calling this absent was stale** | ✅ KEEP | ⚪ |
| Cap scope + cumulative headroom | ✅ | `cap_scope` + `cap_carry_forward`; unused headroom banks forward | ✅ KEEP | ⚪ |
| Admin / management fee on the pool | ✅ | `admin_fee_pct` per pool, applied to the **capped** cost so it cannot re-breach the cap | ✅ KEEP | ⚪ |
| Share freezing / tie-out on re-run | — | Frozen participant set and frozen `pro_rata_share_pct`; the tie-out is mutation-tested so it is *able* to fail | ✅ KEEP — above benchmark discipline | ⚪ |
| True-up settlement | ✅ | Positive bills immediately on its own invoice (the lease may have ended); negative becomes an auto-applied FIFO credit | ✅ KEEP — hard-won, do not reopen | ⚪ |
| Re-estimate next year | ✅ | The reconciliation moves next year's estimate; `estimate_basis = billed` reads what tenants were actually invoiced | ✅ KEEP | ⚪ |
| Tenant reconciliation statement | ✅ auditable | Shows the pool, exclusions, gross-up, share basis and the arithmetic | ✅ KEEP | ⚪ |
| % rent — natural + artificial breakpoint | ✅ | Both | ✅ KEEP | ⚪ |
| % rent — cumulative YTD + annual settle-up | ✅ | `percentage_rent_frequency = 'annual'` — chronological marginals that sum to the year's overage, re-attributed on any lock/void | ✅ KEEP | ⚪ |
| % rent — tiers, deductions, estimated sales | ✅ | Tier ladder charging only the sales within each band; `percentage_rent_deductible_types` floored at 0; `sales:estimate-missing` uses the tenant's own trailing average, **marked as an estimate and never auto-locked** | ✅ KEEP | ⚪ |
| POS / automated sales feed | ✅ | Deliberately absent | ⏭️ **DECLINE** — Egyptian tenants will not expose a POS; file-first + mobile declaration is the correct market fit | ⚪ |

### 3.6 Reporting

| Report | Yardi | Atriom | Verdict | Sev |
|---|---|---|---|---|
| Rent roll | ✅ the most-used commercial report | `/admin/rent-roll`, as-at-a-date, reading the same schedule row billing does | ✅ KEEP | ⚪ |
| Lease expiration schedule | ✅ | Area **and** income at risk per year, holdovers in their own bucket | ✅ KEEP | ⚪ |
| **Stacking plan** | ✅ | ✅ **ROW CORRECTED 2026-08-19 — this was a false gap.** `/admin/occupancy-map` renders every unit as a card grouped by floor and coloured by status, with per-status counts, status filtering and search by unit or tenant. That is a stacking plan; earlier versions of this analysis called it absent because they grepped for the word | ✅ KEEP | ⚪ |
| **Occupancy / vacancy** | ✅ | ✅ **ROW CORRECTED 2026-08-19.** `App\Support\Occupancy` computes occupied m² and percentage by area (not a unit count) and feeds the mall stats, the floor and the asset; the occupancy map carries the per-status breakdown | ✅ KEEP | ⚪ |
| AR aging | By charge code | Page + drill-down + CSV, and by charge type | ✅ KEEP | ⚪ |
| Occupancy cost % | ✅ | `ReportService::occupancyCost()` + page | ✅ KEEP | ⚪ |
| Sales MTD/YTD/MAT, like-for-like | ✅ | MAT and like-for-like side by side, because the gap between them is the story | ✅ KEEP | ⚪ |
| Straight-line schedule | ✅ | `StraightLineRentService::scheduleFor()` — possible only because the ladder is written at signing | ✅ KEEP | ⚪ |
| Owner statements | Investment Manager | Three-tier accrual-GL spine, journalized — **except the management fee (B1)** | ➕ EXTEND | 🟠 |
| Budget vs actual | ✅ | `Budget` — per P&L account, per property, per month; paste-entry because a budget is written in a spreadsheet and re-typing forty lines is where mistakes come from | ✅ KEEP | ⚪ |
| **Revenue forecast** | ✅ Forecast Manager | Budget answers *"is this what we planned?"*; nothing projects forward lease revenue from the charge ladder | ➕ **EXTEND — O10** | 🟡 |

### 3.7 Accounting and the GL

| Capability | Yardi | Atriom | Verdict | Sev |
|---|---|---|---|---|
| Every money source posts to the GL | ✅ | `LedgerPoster::JOURNALIZERS` as a **single registry**, with a gate that fails the build on an unregistered source and four dispatch paths that derive from it | ✅ KEEP — stricter than benchmark | ⚪ |
| Closed-period refusal | ✅ | Declared **on the model** per source, conformance-gated, with a documented exemption for dates that can never be operator-typed | ✅ KEEP | ⚪ |
| Post month ≠ document date | ✅ | `posting_month_overrides` — one override for every GL source; the entry moves, the document keeps its date | ✅ KEEP | ⚪ |
| Charge code → GL account as data | ✅ | The catalogue resolves the posting role, which resolves through `account_mappings`, so a new code inherits the per-property override | ✅ KEEP | ⚪ |
| Straight-line / deferred rent | ✅ | `StraightLineRentService` (EAS 49 / IFRS 16), settings-gated and shipped **OFF**; invoices are byte-identical either way | ✅ KEEP | ⚪ |
| Tax | Region packs | A dated `tax_codes` + `tax_rates` catalogue — VAT · stamp · schedule · withholding, both directions — resolved for the **document's** date, with rate literals banned by a gate. Output stamp/schedule are liabilities, **input stamp/schedule are expenses**, because neither has a credit mechanism the way input VAT does | ✅ KEEP — no benchmark ships this | ⚪ |
| Bank reconciliation | ✅ | `BankStatement` + import + matcher. Suggested matches deliberately held (§6) | ✅ KEEP | ⚪ |
| Multiple books | ✅ | Single book | ⏭️ **DECLINE** | ⚪ |
| Money-record deletion | Soft controls | **Refused at the model**, classified per model by attribute with a stated correction path, gated in CI | ✅ KEEP — exceeds benchmark | ⚪ |

### 3.8 The one architectural decision — invoice-level vs charge-level AR

**Yardi:** the posted charge is the receivable. **Atriom:** the invoice is the receivable, with
items beneath it. **This is settled and should not be reversed.**

1. **ETA e-invoicing is invoice-centric.** Egypt's regime files a *document*. An AR model with no
   invoice must synthesise one to file, then reconcile the synthetic document against the
   receivables it represents — a second source of truth, which is the exact failure
   `recomputeTotals()` exists to prevent.
2. **VAT is computed and reported per document**, not per receivable.
3. **The invariants are already built and tested against the invoice** — recompute, both
   over-allocation guards, credit durability, the credit-note un-apply, the AR-exclusion status
   rules, the journalizer. Moving the AR atom invalidates all of them at once.
4. **The tenant experience is document-shaped.** Tenants pay, dispute and file *invoices*.

What the choice costs has been bought back beneath the invoice rather than beside it: aging by
charge type, item-level allocation, and line-level dispute all ship, and **item allocation is
detail, not truth** — if item allocations and the invoice total can ever disagree, the design is
wrong.

---

## 4. Facility, vendors, assets — vs the FM standard

> **There is now a yardstick.** Until 2026-08-20 this section compared Atriom to "the FM
> specialists" in prose with no document behind it, which made every claim here something to be
> believed rather than checked — exactly the condition `docs/benchmarks/yardi/` was created to end
> for leasing. The standard is now written down in **[docs/benchmarks/fm/](../benchmarks/fm/)**:
> **IBM Maximo** for the work-and-asset core (where the money lives) and **ServiceChannel** for the
> contractor and tenant-facing loop (which is Atriom's actual business shape), with Facilio and
> Corrigo named where either leads. Eight end-to-end scenarios with numbers are in
> [03-scenarios.md](../benchmarks/fm/03-scenarios.md).
>
> The Atriom column is re-derived from code (2026-08-20), **not** carried from the July 2026
> competitor deep-dives, whose Atriom columns had gone stale on at least six rows.

### Ahead of the benchmark

| Capability | vs the specialists |
|---|---|
| **Vendor SLA penalty charged onto the AP bill, GL-tied** | Facilio/ServiceChannel/Maximo *track* SLA compliance; they do not post liquidated damages. Atriom docks Dr AP / Cr the same expense the bill charged, on configurable bases, re-assessed per scan, frozen on close — accounting the CMMS tools defer to finance |
| **Perpetual inventory + GRNI + fixed-asset depreciation twin** | Fiix/Facilio push financial posting to an external ERP. Here every stock movement posts; GRNI nets to zero against the bill; `equipment.fixed_asset_id` keeps the finance and maintenance registers separate with no double entry |
| **Per-property SLA overrides** (property → settings → config fallback) | Most CMMS restate SLA per site. Record only the malls that genuinely differ; deleting the override returns a property to default |
| **Owner↔operator ticketing + unknown-caller intake** | PM tools handle owner comms through generic messaging and under-model walk-in intake. `caller_name`/`caller_phone` are model-enforced when there is no tenant — how a mall actually receives work |
| **Zone-routed work orders** | `area_id` derives at the model layer from the linked request, else the unit, and only fills a null so a plan's zone is never overridden; supervisors are notified, not just the assignee |

### At parity — built, verified 2026-08-19

| Capability | Evidence |
|---|---|
| Vendor COI / compliance-document tracking with a **dispatch guard** | `VendorDocument` + `isDispatchable()` — ServiceChannel's flagship, and the one place a blocking guard is right, because there *is* a dispatch decision to gate |
| Meter / usage-based PM triggers | `GeneratePreventiveWorkOrdersService` runs a calendar round **and** a counter round, evaluated in PHP because the usage baseline is what makes a usage plan non-repeating |
| Vendor scorecards | `VendorScorecardService` + `/admin/vendor-scorecard`. Built with seven regression tests and **no screen** until 2026-08-18 — the service existed and could not be reached, which is why service reachability is now its own gate |
| Asset criticality | `Equipment::CRITICAL` — three values, and it **changes behaviour** rather than rendering a badge: a fault on critical plant starts at urgent, and the tenant-reported path takes the *higher* of the two priorities so it can only raise a job, never quietly lower one |
| Weighted-average costing + warehouse transfers | `StockMovementService::weightedAverageCost()` values stock on hand; `TRANSFER_TYPES` deliberately posts nothing for an intra-company move |
| Low-stock alerting | `LowStockAlert` + `inventory:scan-low-stock` daily at 07:30, per property |
| Contractor permit to work | `work_permits` + `facility:scan-open-permits` (hourly). **An extension, not a Yardi construct** — Voyager models no safety permit; this follows the FM/CMMS standard. Bounded to the hour, and the finding it exists to raise is an issued permit past its window with **no closure**, which no screen showing what exists can see |
| Fit-out permit decision | Approve/reject recorded with `decision`, `decision_reason`, `decided_at`, and a mandatory rejection reason — the *conditions* half is O4 |

### Behind — and it is two ideas, not a list of features

Reading the standard rather than a feature list changes the shape of this section. **Six of the
eight benchmark scenarios fail on one of two structural absences**, and most of what used to be
listed here as separate gaps is downstream of them.

#### The first: **a work order is not a cost object** *(Maximo §4)* — ✅ CLOSED 2026-08-20

`facility_work_orders` carries no cost at all. `job_value` exists solely to feed the SLA-penalty
percentage basis, and `FacilityWorkOrder` is **not** in `LedgerPoster::JOURNALIZERS`. Parts post
through `StockMovement`, contractor work through `VendorBill`, in-house wages through `Payroll` —
every figure is correctly in the ledger, and **none of them can be attributed to the job, the
machine or the shop.**

Note what this is *not*: it is not a posting gap. The money is posted. The gap is that the
**management dimension** — which job, which asset, which trade consumed it — was never recorded,
so the ledger can say what the mall spent and cannot say what it spent it on.

The most consequential single consequence: **in-house labour was captured nowhere**, so internal
work cost zero on every report, insourcing always looked free and every outsourcing decision was
made on a number wrong by the whole wage bill.

**Closed.** `facility_work_orders` now carries planned and actual cost in three buckets, fed by
`recomputeCosts()` — one source of truth, three channels, the same discipline as
`Invoice::recomputeTotals()`. It is deliberately **not** a GL source: the money is already posted by
`StockMovement`, `VendorBill`/`Expense` and `Payroll`, so a journalizer here would post every
maintenance cost twice *and balanced*. A gate keeps it that way.

#### The second: **trade is not master data** *(ServiceChannel §2)* — ✅ CLOSED 2026-08-20

The work order's `category` — HVAC, plumbing, electrical — is a Select populated from
`__('admin.facility.categories')`, **a translation array**. It is not in `ValueSets`, so the column
is unenforced; it cannot be extended without a deploy in two languages; and **`vendors` has no
trade at all** (only `type`: contractor / supplier / service_provider / consultant / other, verified
against the live schema).

Three capabilities are impossible as a direct result, none of which presents as an error:
dispatch eligibility (the vendor picker offers the stationery supplier for an HVAC fault), spend by
trade (the first question an owner asks), and a scorecard that compares like with like.

#### What remains after those two

| # | Gap | Standard | Sev |
|---|---|---|---|
| F5 | **A plan targets one asset, not a route** — 42 extinguishers means 42 plans, or one checklist whose failures cannot be attributed to a device | Maximo §6 (routes) | 🟡 |
| F6 | **The doer closes the job** — no tenant confirmation or reopen, though the portal and `tenant_request_id` linkage already exist | ServiceChannel §4/§6 | 🟠 |
| F7 | **PM compliance is unmeasured** — `scheduled_for` and `completed_at` are both stored; nothing computes whether the preventive programme is actually being done | Maximo §6 | 🟡 |
| F8 | **Job plans carry no estimates** — `ServicePlan.checklist` is a method with no planned labour, material or duration, so there is no planned side to compare actuals against | Maximo §3 | 🟡 |
| O2 | **Vendor self-service portal** — dispatch is an internal column change; no accept / quote / update / evidence loop | ServiceChannel (the product *is* this loop) | 🟡 |
| O3 | **Field-technician mobile app** — `/api/v1` is tenant-facing; engineers close work orders from a desktop | Facilio · MaintainX | 🟡 |
| O4 | **Fit-out permit: conditions on the grant** | ServiceChannel compliance | 🟡 |
| O16 | **Barcode parts issue + guided cycle counts** | every CMMS | 🟡 |

**The pattern worth keeping from the previous round:** these are not wrong-arithmetic gaps. They are
**capabilities with no surface**, and the round-3 sweep found four services fully built, fully
tested and impossible to start, with the *tests* being what hid them. Two conformance gates now
fail the build on that class: `ServiceReachabilityConformanceTest` (can a person or a schedule
start it?) and `BillableAgreementIsConfigurableConformanceTest` (can the data it needs be
created?).

### The close-out order, and why it is this order

| Step | What | Why here |
|---|---|---|
| **1** ✅ | **Trade as master data** — `trades` + `trade_vendor`; work orders, plans and equipment classify by a row; the vendor picker groups by eligibility; `category` dropped with a code-matched backfill. **Shipped 2026-08-20** | Must precede the cost object: in the standard a labour rate belongs to a *craft*, and building labour first would invent a second rate concept and then have to refactor it |
| **2** ✅ | **The work order as a cost object** — planned and actual in four buckets (labour · material · service · tool), labour captured as reported hours × rate, material from the existing part draws, service from the existing vendor bills, rolled up to the asset and the location. **Shipped 2026-08-20** — three buckets, not four (tools are hired here, so they arrive as a bill and land in `service`) | The spine. Six of eight scenarios and every "what did this cost" question |
| **3** | **PM compliance** | Cheapest real gap in the module — both dates are already stored |
| **4** | **Tenant confirmation of completion** | A control failure, and the portal + linkage already exist |
| **5** | **Failure vocabulary + repeat-visit detection** | The reliability primitives. Worth nothing on the day they ship and everything two years later, which is the argument for shipping them early |
| **6** | **NTE + the proposal loop** | The *before-the-money* control. Atriom's *after* control (three-way match) is arguably stronger than the benchmark's; the before control does not exist |
| **7** | **Routes**, then **job-plan estimates** | Both need the cost object to be worth having |

---

## 5. The generic ERP layer — vs Odoo

> **Status: correct and frozen since 2026-07-18.** GL · AP/vendor bills · inventory · procurement ·
> fixed assets · HR/payroll · treasury/عهدة · marketing spend · deposits.

**The headline:** Atriom is not a smaller Odoo — it is a narrower, deeper one. On the generic
modules it either matches Odoo or matches Odoo **Enterprise** (the paid edition) on several
property-fit capabilities Community lacks. The honest gaps were a short cluster — bank
reconciliation, Egyptian tax depreciation, employer social insurance / gratuity — and the first two
have since shipped (`BankStatement` + matcher; `/admin/tax-depreciation`).

**From here, build effort goes to property and facility, not here.** That is a standing decision,
recorded so nobody re-audits a closed layer.

**One caution that must travel with the word "closed".** A 2026-08-11 money-lens pass over three of
these modules found **six** correctness defects after they had been declared closed — inventory
relieving at the catalogue cost rather than what stock was loaded at, a disposed asset's cost
staying editable, a settled عهدة staying editable. "Closed" means *no verified open gap under the
lenses applied*, not *proved correct*. The remaining generic-layer question is the accountant's:
employer social insurance and gratuity, which could still move the books.

---

## 6. Declined — with reasons, so they are not re-raised

| Item | Why declined |
|---|---|
| **Multiple books / second ledger** | One book, one property dimension. A second book is an audit surface with no Egyptian requirement behind it |
| **Bank deposit batches** | PDCs and transfers dominate this market; batching cash deposits solves a problem the operator does not have |
| **Interest-bearing / segregated deposits** | Not an Egyptian requirement |
| **Automated POS / sales feed** | Egyptian tenants will not expose a POS. File-first + mobile declaration is the correct market fit — and the analytics on the declared figures (occupancy cost, sales/m²) *are* worth doing, and ship |
| **IoT / BMS condition monitoring, predictive maintenance** | Facilio's core pitch and Maximo Predict. There is no sensor estate to feed it |
| **Vendor marketplace / vetted provider network** | A network effect for multi-client platforms; irrelevant to one operator with a known contractor bench |
| **Statutory tax-rate automation** | The mechanism already exists and is better than the request imagined: `tax_rates` is a dated ladder, so a decreed rise is entered in advance and starts applying by itself, with back-dated documents keeping the rate in force. "Automation" would mean an external feed of Egyptian statutory rates, and there is no such feed to consume |
| **Bank reconciliation — suggested matches** | The manual path works. A suggester that is *usually* right is exactly the thing that stops being read, and a wrong match marks money as verified — the failure the module exists to prevent. Worth building only after someone has reconciled a real month by hand and can say where the tedium actually is |
| **Deep reliability-engineering analytics at full depth** | Build the primitives (O13) before the dashboard. Maximo Health is a product, not a feature |

---

## 7. What changed in this update — 2026-08-19

| Change | Why it matters |
|---|---|
| **Stacking plan: ❌ → ✅** | `/admin/occupancy-map` is one. Earlier analyses grepped for the word and reported absence |
| **Occupancy / vacancy: 🟡 → ✅** | `App\Support\Occupancy` reports occupied m² and percentage **by area**, not a unit count, which was the substance of the original row |
| **Fit-out permit: ❌ → 🟡** | The approve/reject decision with a mandatory reason shipped 2026-08-15; only conditions-on-the-grant remain. The stale *"NO approval step"* comment the round-3 plan flagged is also fixed |
| **Revenue forecast: refined** | `Budget` (plan vs actual per account/property/month) ships; the open half is specifically the forward projection from the charge ladder |
| **Deposit top-up: confirmed open** | `deposit_shortfall` exists but belongs to the move-out statement — a different mechanism that a keyword search reads as coverage |
| **3-way match: ❌ → ✅ (2026-08-19, same day)** | `PurchaseRequest::billingVariance()` ships, is shown live on the vendor-bill form, and has its own regression test. The row was written off a grep for `match_tolerance` / `variance_hold` / `match_status` — none of which is what it is called. **This document warned about exactly that failure two sections earlier and then committed it**, which is why §0's rule is now stated as *search for the capability, not the spelling*. Worth keeping on the record: the feature's own docblock had already reasoned to the same design conclusion this analysis was about to re-derive — that blocking would be wrong, because a bill legitimately covers freight and labour beyond the goods, so the variance is a number to SHOW |
| **Deposit-on-escalation: ❌ → ✅ (same day)** | `security_deposit_months` re-derives the deposit whenever rent moves. The row was written off a grep that hit `deposit_shortfall` — the move-out statement's reconciliation — and concluded the capability was absent because the *name* it expected was missing |
| **Bounce-after-clearing: 🟡 → ✅ (same day)** | The row called it "modelled as impossible … the remedy is manual". Both halves were wrong: `PostDatedCheque::updating` carries an explicit carve-out for `cleared → bounced`, and `Payment::saved` drives it automatically when the clearing payment is voided, so a bank returning a cheque after provisional credit is a modelled path rather than a stranded row |
| **PDC series exhaustion: ❌ → ✅ (built 2026-08-19)** | `pdc:scan-coverage` — weekly, property-scoped, notifying the mall's own team. Built rather than deferred because the failure is the ABSENCE of a row: every lodged cheque clears on time and the register stays green right up until the month the money stops, so no query over existing cheques could ever have surfaced it |
| **Work-order evidence: ❌ → ✅ (built 2026-08-19), and the row understated it** | Filed as a missing completion *gate*; `FacilityWorkOrder` did not implement `HasMedia` at all, so there was no way to attach a photograph to a job. Both halves now ship: a private `evidence` collection, and a requirement that is a setting shipped OFF — turning it on mid-flight would refuse completions on jobs already finished and produce photographs of walls |
| **Public-mall listing: decided and built (2026-08-19)** | The operator chose **default-listed**, so nothing changed for any shopper on deploy; `assets.is_publicly_listed` now separates publication from operation, and `resolveMall()` — not the menu query — is the gate, because a mall code is guessable |
| **Reorder auto-purchase: 🟡 → ✅ (decided and built 2026-08-19)** | The open half was never plumbing, it was *who approves a system-raised commitment?* — answered **the scan drafts, a human submits**. Implementing that faithfully needed a `draft` status the model did not have, because `requested` is already in the ladder and a system-raised request would have had its approval tier chosen by a value nobody entered |
| **Owner-held parking bay: decided and BUILT (2026-08-19)** | The pivot is polymorphic over `BillableAgreement`, so a tenant holds a bay through a lease and an owner through his ownership, with the charge riding his monthly assessment. Grounded in the benchmark rather than designed: Voyager assigns rentable items to the customer RECORD, and its condo product makes the unit owner that record — Atriom had narrowed "customer record" to "lease" only because a lease was once the only agreement. The double-let guard now asks per holder, which is the invariant the refactor could most easily have broken |
| **CPI escalation: 🟡 → ✅ (built 2026-08-19)** | `rent_indices` is the register, and a lease states its index, base value and publication lag — Voyager's index source / base index value / publication lag, built to the spec rather than designed. S4's warning was honoured: the collar was in place before any index-derived step could apply. The refusal survives — an unpublished month leaves the lease alone and does **not** roll the anniversary, so a late statistic is never a year the tenant skips |
| **Clause abstract: 🟡 → ✅ (built 2026-08-19)** | `lease_clauses` holds the benchmark's own twelve types, with typed numbers for the four that carry one. The deliverable was a QUESTION — the benchmark's *"nothing can even report how many of our leases have a co-tenancy trigger tied to the anchor we are about to lose"* — and `scopeLiveExposure()` answers it — bundling clause type, clause-in-force AND lease-still-live, because the first version composed only the first two and reported a terminated lease as exposed (found by running it, fixed same day). Automatic rent abatement on a co-tenancy trigger is deliberately NOT built: the condition is a legal reading an occupancy percentage only approximates, and a wrong abatement is a credit clawed back from a tenant who already banked it |
| **Revenue forecast: 🟡 → ✅ (built 2026-08-19)** | `/admin/revenue-forecast` sums `LeaseBillingForecastService` across the portfolio, so it inherits the real billing method and ties out to the per-lease tabs exactly. **The speculative half — Voyager's assumed renewals and re-lets — is deliberately NOT built**: it needs a renewal probability and a market rent this system does not hold, and a guessed figure is indistinguishable from contracted income on a page an owner may be shown |
| **Permit to work (O5): ❌ → ✅ (built 2026-08-19)** | The one row in this document with no Yardi lineage, and it says so rather than borrowing authority it does not have — Voyager is lease administration and the benchmark folder has zero hits for hot work or isolation. Two properties carry the control and both were designed for rather than inherited: bounded **to the hour**, because a permit good for a whole day is one somebody uses at 19:00 after the fire officer has gone home; and there is deliberately **no `expired` status**, because expiry is a fact about the clock and a sweep that flipped permits to it would quietly close the audit question the register exists to ask. Issuing reuses `Vendor::isDispatchable()`, so the permit cannot become the one door left open after the work-order path was closed. Reviewing it on a live database found what 21 green tests did not: the global search returned **zero hits for the permit's own reference**, because the resource searched a folded blob with an unfolded query — the gates all proved the STORED side folds and none proved the QUERY side did. That gate now exists |
| **Trade as master data: ❌ → ✅ (built 2026-08-20)** | Close-out step 1, and the first thing the new FM yardstick asked for. `trades` is now a register with both languages on the row and a `standard_hourly_rate` — the craft rate the cost object will read — replacing a `Select` fed from a **translation array** that was not in `ValueSets`, could not be extended without a deploy, and had been hardcoded a second and third time in `EquipmentForm` and `EquipmentTable` with four trades missing from both. `vendors` gained the trade link that made "who may we dispatch to an HVAC fault?" answerable at all. The picker **groups** rather than filters, because Filament validates a Select with `Rule::in` and refusing the unusual-but-legitimate pick is worse than suggesting the usual one. The change surfaced a live defect: `RaiseCorrectiveWorkOrderService` copied a tenant request's category onto the work order as if the two vocabularies were one, so `noise`, `parking` and `lease_copy` had been saving into the trade column silently — the tenant picks a PROBLEM, not a trade, and only the Maintenance type's subcategories are trades |
| **The work order as a cost object: ❌ → ✅ (built 2026-08-20)** | Close-out step 2, and the structural idea six of the eight benchmark scenarios failed on. Three buckets — labour · material · service — planned and actual, with `recomputeCosts()` as the single source of truth and all three channels wired to it. The headline gap was **in-house labour, captured nowhere at all**: `facility_work_order_labour` asks how long it took and who did it, and the craft rate frozen at entry turns that into money, because a hand-typed cost is a guess with a decimal point. `vendor_bills` and `expenses` gained the job link that made contractor work attributable in the first place. **It is emphatically not a GL source** — the money is already posted three other ways, and posting it again would double every maintenance cost *and balance*, which is what makes that mistake dangerous rather than obvious; a gate now fails the build on it. `job_value` was replaced rather than kept beside `est_service_cost`, and the SLA percent-of-value basis now prefers what the contractor ACTUALLY charged — impossible before, because there was no actual. Tools are Maximo's fourth bucket and are deliberately folded into service: a mall hires the scissor lift, so it arrives as a bill |
| **Nine documents merged into this one** | The retired set had the same rows in three states of staleness. Every open row above now names its module and its evidence |
| **Census metric retired** | `atriom:dump-system-census` counted `docs/gap-analysis/NN-*.md` to surface the modules never audited. Round 3 closed that hole; a metric whose question has been answered goes on printing a number about the shape of a directory |

---

## 8. Provenance and confidence

**Yardi claims** are from the benchmark folder, which grades every claim by source layer —
published Yardi material, practitioner documentation, or inference. Yardi does not publish its user
guides openly, so a claim marked as inferred is exactly that. See
[`benchmarks/yardi/README.md`](../benchmarks/yardi/README.md) §Sourcing.

**FM and ERP competitor claims** are edition-, region- and version-sensitive. Treat any specific
capability as *verify before quoting to a client*.

**Atriom claims** are grounded in code, and the grounding is uneven — stated here rather than
implied, because the first version of this section overstated it.

| Rows | How well grounded |
|---|---|
| §3 ✅ KEEP rows | Verified during the Yardi cycle (2026-08-09 → 08-11), carried forward since |
| §2 open rows | Each one **read in the source** on 2026-08-19 — see the caveat below |
| §7 corrections | Read in the source, and the correcting evidence is named in the row |

**The caveat, because it cost four rows in one day.** The first pass over the open list was done
with a keyword grep, and a keyword grep answers *"is this word here?"* rather than *"does this
capability exist?"*. Four rows were published as open and corrected within hours: the three-way
match (`billingVariance()`), deposit-on-escalation (`security_deposit_months`), bounce-after-
clearing (a carve-out in `PostDatedCheque::updating`), and the stacking plan (`/admin/occupancy-map`).
Each existed under a name the grep did not guess, and in three cases the shipped design was better
reasoned than the row proposing to build it.

**So: if you are about to act on a row, open the code first.** Not to be careful — because the base
rate of error on this page has been measured, and it is not low.

**Every ✅ KEEP row is carried forward without individual re-verification.** That is the honest
statement of what this document is: a map that is right about the shape of the system and can be
wrong about any single square.

**Where each module's own detail lives:** [`docs/modules/NN-*.md`](../modules/README.md) — business
rules, extension points and gotchas, per module. This document says what is *missing*; those say
how what exists actually works.
