# Atriom vs the mall/retail specialists — competitive gap analysis

> **What this is.** A capability + architecture comparison of Atriom's *property* and
> *facility* modules — the moat — against the software a mall operator would actually shortlist
> instead of a generic ERP: **Yardi Voyager**, **Re-Leased**, **AppFolio** (commercial/retail
> lease + property management) and **Facilio**, **ServiceChannel**, **IBM Maximo**, **Fiix**,
> **MaintainX/Limble** (facility/CMMS/EAM). Produced 2026-07-18.
>
> **Scope — the property + facility overlap.** This is the mirror of the [Odoo analysis](../odoo/README.md),
> which covered the *generic* ERP modules (GL/AR/AP/inventory/HR/treasury) where Odoo is the fair
> benchmark. Here we test the differentiators: lease lifecycle & billing, CAM & turnover rent,
> deposits/utilities/portal/owner, work orders (PPM+CM) & SLA, the asset/parts/procurement spine,
> and vendors/areas/permits/violations. The generic modules are out of scope — see the Odoo file.
>
> Each of the six domain deep-dives (linked in §6) carries the full capability matrix, an
> architecture read, and its own top-5 gaps. Competitor claims are edition/region/version-sensitive
> and marked *(verify)* in the source docs; the Atriom side is grounded in the code and module docs.

---

## 1. Headline verdict

**Atriom is not a smaller Yardi or a lighter Maximo — it is a mall billing-and-books engine that
grew a competent facility arm, and the moat is real but lopsided.** Where it wins, it wins on the
half those specialists deliberately push out to an external finance system: a real double-entry GL
*under* the property engine, so a CAM true-up, a security deposit, an inventory movement, a
depreciation run, and — the signature move — a **vendor SLA penalty charged straight onto the AP
bill** are all first-class, auditable money events, per-property-isolated, in correct 14%-VAT EGP
with bilingual books and an ETA e-invoicing pipeline no Western specialist ships. On billing
*correctness* (derived-AR single source of truth, idempotent lock-safe monthly run, durable credit
notes, dual-method percentage rent) and on tie-out discipline it is at or above the field. Where it
is genuinely thin is the *configuration and workflow breadth* those products were built around — the
CAM recovery-clause engine and owner statements/disbursements on the property side, and the
field-technician mobile app, vendor compliance/dispatch loop, and reliability analytics on the
facility side. **The honest one-line: the accounting and the Egyptian fit exceed the specialists;
the lease-admin depth, the owner deliverable, and the field/vendor edge are behind — and one of
those (owner statements + payouts) sits at the exact center of the operator-for-owner relationship
Atriom exists to serve.**

---

## 2. Where Atriom is ahead — the moat (don't trade it away)

| Capability | vs the specialists | Why it matters for an Egyptian mall operator |
|---|---|---|
| **Vendor SLA penalty → charged onto the AP bill, GL-tied** | Facilio/ServiceChannel/Maximo *track* SLA compliance; they don't auto-post liquidated damages | Late vendor is docked Dr AP / Cr the same expense the bill charged, on 3 configurable bases, re-assessed per scan, frozen on close. Accounting the CMMS tools defer to finance. |
| **A real double-entry GL under the whole property engine** | Yardi has it; Re-Leased/AppFolio lean on Xero/QBO; the CMMS tools have no books at all | Deposit-as-liability, CAM recovery, inventory perpetual costing, GRNI clearing, depreciation twin — all post automatically, property-dimensioned, swept, tie-out-gated. |
| **Egyptian ETA e-invoicing + 14% VAT / rent-exempt / 5% levy** | None of Yardi/Re-Leased/AppFolio/Facilio ships native ETA | Correct-by-construction per-charge tax split; a full B2B ETA pipeline (EGS codes, T1/V009). *Built but currently disabled/mock — see risks.* |
| **Airtight per-property (`asset_id`) isolation, conformance-gated** | Maximo uses an org/site tree; Yardi multi-entity; CMMS multi-site config | One operator runs many malls off one chart, one catalog, one item register — cleaner than a multi-company workaround. |
| **CAM true-up *settlement* correctness** | Yardi's recovery engine is deeper on clauses, not on settlement hygiene | Positive → immediate issued recovery invoice (no stranded charge on a rolled-off lease); negative → auto-applied FIFO credit note; lock-safe, books-tie-out-gated. |
| **Percentage-rent depth (artificial + natural breakpoint, immediate overage)** | Re-Leased shallower; Yardi comparable | Both breakpoint methods, floored/rounded, lock→bill→void/re-lock hardened against double-bill. |
| **Perpetual inventory + GRNI + fixed-asset depreciation twin** | Fiix/Facilio push financial posting to an external ERP | Every stock movement posts to the books; GRNI nets to zero against the bill; `equipment.fixed_asset_id` keeps finance and maintenance registers separate with no double entry. |
| **Deposit-as-liability register** | Parity with Yardi/AppFolio *ledger*; ahead of the CMMS tools | Receipt/refund/forfeit posted Dr/Cr through the same journalizer+sweep spine — auditable, cancellable-with-void. |
| **Per-property SLA overrides (property → settings → config fallback)** | Most CMMS restate SLA per site; ServiceChannel per trade | Record only the malls that genuinely differ; deletion returns a property to default. |
| **Owner↔operator ticketing + unknown-caller/multi-channel intake** | PM tools handle owner comms via generic messaging; walk-in intake is under-modelled | `OwnerRequest` lifecycle + `caller_name`/`caller_phone` model-enforced when there's no tenant — matches how a mall actually receives work. |

**The through-line (same as the Odoo finding):** Atriom layered a real GL *under* an existing
property/billing engine and hardened it with controls a generic tool doesn't need because *its move
is the record*. That is the moat. The gaps below are completeness, not a rewrite — but two of them
(owner statements, ETA being dormant) are close enough to the moat's own promise to be risks, not
just to-dos.

---

## 3. The consolidated gaps — clustered, ranked, honest

Ordered by value to a **single-entity, EGP, multi-property Egyptian mall operator**. Split into the
two moats, then a hard line between *real gaps an operator would feel* and *enterprise-only / N/A*
breadth you should not mistake for a to-do list.

### 🏢 PROPERTY (leasing · CAM · deposits · utilities · portal · owner)

#### 🔴 Real gaps an operator would feel

1. **Owner statements + disbursements/distributions** *(effort L)* — **the single most important
   gap in the whole analysis.** Eltizam runs malls *for* Jawad; the periodic owner statement
   (income − expenses − management fee = net) and the payout/distribution cycle *is* the deliverable
   of that relationship, and Atriom ships **none** of it. There's a read-only `/owner` panel and a
   portfolio KPI widget, but no statement, no payout tracking, and the widget ignores
   `ownership_percentage` (a 50% co-owner sees the whole property's MRR/AR). Yardi Investment
   Manager and AppFolio owner accounting are built to produce exactly this. Compounded by F-80
   (per-property close posts consolidated-only → per-owner retained earnings reads 0). *[03](03-deposits-utilities-portal-owner.md)*
2. **CAM recovery-clause engine — caps, gross-ups, admin fee, exclusions, configurable basis**
   *(effort L)* — Yardi's crown jewel, and Atriom's thinnest CAM half. Anchor caps, a routine
   10–15% CAM **admin fee** (real landlord revenue Atriom can't book), gross-up-to-occupancy, and
   pool exclusions are absent or schema-only (`cap_amount`/`exclusions` sit on `CamAllocation`
   unread); the allocation basis is hard-coded to occupied leased area, so the landlord can't elect
   to absorb vacancy. *[02](02-cam-turnover-rent.md)*
3. **Automated rent escalation (stepped + CPI)** *(effort M)* — the fields exist
   (`escalation_rate`/`escalation_type`/`next_escalation_date`); nothing applies them. No scheduled
   job, no CPI index feed — every anniversary increase is a manual `LeaseRentChangeService` call
   that leaks revenue when missed. A `next_escalation_date ≤ now` sweep over the existing service is
   a low-risk build. *[01](01-lease-billing.md)*
4. **Post-dated cheque (PDC) register + lifecycle** *(effort M)* — the Egyptian norm: a tenant
   lodges a year of PDCs up front. Atriom captures one cheque number on a *received* payment; there
   is no forward instrument register, maturity schedule, or bounce lifecycle. **None** of the
   Western benchmarks fill this either — so it's a differentiation opportunity, not just parity.
   (Confirmed open in OPEN-QUESTIONS A7.1.) *[03](03-deposits-utilities-portal-owner.md)*
5. **CAM pool sourced from posted GL/AP expenses (+ cost categories, drill-through)** *(effort M–L)*
   — today a pool is **two numbers a human types in**, one category, per year. No roll-up from the
   real ledger the operator already keeps, no audit line from a recovery charge back to the expenses
   it recovers, no recovering different buckets on different bases. *[02](02-cam-turnover-rent.md)*
6. **Utility tariff / recharge automation** *(effort M)* — meters, readings, delta consumption and
   cost all exist, but a human reads the meter and hand-sets the charge; there's no reading→charge
   bridge and no rate engine. Yardi's convergent/RUBS billing is the benchmark; even "rate ×
   consumption → utility charge" kills most of the monthly labour. *[03](03-deposits-utilities-portal-owner.md)*
7. **Lease document / contract generation + e-signature** *(effort M–L)* — leases only *hold*
   uploaded PDFs; there's no generator, template merge, or e-sign. For an operator onboarding
   tenants continuously this is a daily workflow Re-Leased owns and Atriom lacks entirely.
   *[01](01-lease-billing.md)*
8. **First-class rent-free / fit-out / stepped-rent schedules** *(effort M)* — retail deals routinely
   include a fit-out/rent-free period and a stepped schedule; today they're *faked* via a charge's
   `start_date` window — fragile, invisible to reporting, can't express a step. *[01](01-lease-billing.md)*
9. **Annual/YTD-cumulative turnover breakpoint + tiered rates** *(effort M)* — each month is computed
   independently against a per-period threshold, so the retail-standard "annual breakpoint paid
   monthly, trued-up at year end" lease is mis-billed; tiered/marginal percentage schedules can't be
   expressed. *[02](02-cam-turnover-rent.md)*
10. **Deposit balance + lease reconciliation + itemised refund** *(effort S)* — the *ledger* is right,
    but nothing derives a per-lease held-balance, reconciles it against the contractual
    `security_deposit`, or nets damages-then-return in one disposition (a forfeit + a refund are two
    disconnected events). Cheap on top of the existing register; high operator value. *[03](03-deposits-utilities-portal-owner.md)*
11. **Live, richer tenant payment rail** *(effort M)* — Paymob is card-only, config-gated, and not
    certified live; no stored-card/autopay/InstaPay-recurring. Payments UX is AppFolio's headline
    strength and the most visible thing a tenant touches. *[03](03-deposits-utilities-portal-owner.md)*
12. **Bill the violation fine to AR** *(effort S–M)* — `fine_amount` is recorded but never billed;
    an assessed fine is usually money the operator intends to collect. The module doc already
    prescribes the fix (raise a `Charge` so `recomputeTotals()` stays the source of truth). *[06](06-vendors-areas-permits-violations.md)*

#### 🟡 Real but lower / owner-reporting depth

- **Lessor-side lease accounting (straight-line rent / IFRS 16 smoothing)** *(effort L)* — revenue is
  cash/billed-basis; free-rent and step-ups aren't smoothed over the term. An auditor raises this once
  incentive-heavy deals exist; it's the owner-reporting parallel to the accounting "second book" theme.
  *[01](01-lease-billing.md)*
- **Flexible billing cadence + per-unit rent + trailing-month proration** *(effort M)* — no per-lease
  billing anchor day (all invoices date to the 1st; `billing_day` is reserved-unused), no
  weekly/semi-annual cadence, no move-out proration, one `base_rent` across all units of a multi-unit
  lease. Individually small; together it's "our lease doesn't bill the way the contract says." *[01](01-lease-billing.md)*
- **First-class monthly CAM estimate / re-forecast object** *(effort M)* — the "estimate collected" is a
  number typed at reconciliation, not a schedule that auto-bills and re-forecasts mid-year. *[02](02-cam-turnover-rent.md)*

### 🔧 FACILITY (work orders · assets · parts · procurement · vendors · permits)

#### 🔴 Real gaps an operator would feel

1. **Field-technician mobile app (offline checklists + photos)** *(effort L)* — **the biggest
   day-to-day usability gap.** The `/api/v1` surface is tenant-request intake; engineers close WOs
   and mark pass/fail from a desktop Filament screen, and push-to-supervisor is declared but inert
   (no device tokens). Facilio/MaintainX/Limble are mobile-first for techs — this is what a switcher
   feels on day one. *[04](04-work-orders-sla.md)*
2. **Vendor insurance / COI / license & compliance-doc tracking (+ expiry alerts + block-non-compliant)**
   *(effort M)* — a mall is legally on the hook for the contractors it lets touch fire systems,
   lifts, and electrical. Atriom stores only `tax_id`/`legal_name` and will happily **assign a
   blacklisted vendor**. This is ServiceChannel's flagship (Compliance Manager) and a genuine
   liability exposure, not just a missing feature. Reuse the `vendors:expire-contracts` scan + a
   dispatch-time guard. *[06](06-vendors-areas-permits-violations.md)*
3. **Meter / usage-based PM triggers** *(effort M)* — PM is calendar-only. Escalators, lifts,
   generators and chillers wear by run-hours and cycles; time-only PM over-services light assets and
   under-services heavy ones. The `MeterReading` primitive exists but feeds billing, not maintenance.
   Every EAM/CMMS benchmark does this. *[04](04-work-orders-sla.md) · [05](05-assets-parts-procurement.md)*
4. **Vendor scorecards / performance analytics** *(effort M)* — Atriom can penalize one late job but
   can't rank contractors over time (on-time %, breach history, cost → renew-or-drop). The penalty +
   breach flag + activity data already exist; this is aggregation + a view.
   ServiceChannel/Facilio lead. *[04](04-work-orders-sla.md) · [06](06-vendors-areas-permits-violations.md)*
5. **Asset criticality ranking** *(effort S)* — no `criticality` field exists anywhere (verified in
   the migration). Nothing tells the operator a fire pump outranks a car-park fan, so PM priority,
   SLA tiering, and spare-stocking depth are all set by feel. Cheap to add, disproportionately
   useful. *[05](05-assets-parts-procurement.md)*
6. **Fit-out permit approval workflow** *(effort M)* — Atriom's permit is capture-only (a validity
   window, no grant/reject/conditions). A mall fit-out permit is a *control gate* — contractor
   access, permitted hours, security deposit, sign-off. The `ApprovalPolicy` ladder already exists to
   borrow from. *[06](06-vendors-areas-permits-violations.md)*
7. **Reorder-driven auto-purchase** *(effort S/M)* — low-stock already fires per-property, but the
   signal only rings a bell; it never drafts a purchase request. Turning it into a draft PR closes the
   loop the benchmarks close automatically and reuses the existing procurement flow. *[05](05-assets-parts-procurement.md)*
8. **Vendor dispatch / provider portal (accept / quote / update / evidence)** *(effort L)* — external
   CM is assigned to a *known* `vendor_id` with no way for the vendor to accept, quote, update status,
   or upload evidence. ServiceChannel is built on exactly this loop. Lower priority than 1–7 for a
   single operator, but real for outsourced HVAC/cleaning/lifts. *[04](04-work-orders-sla.md) · [06](06-vendors-areas-permits-violations.md)*

#### 🟡 Real but lower / control depth

- **Condition / downtime / failure-code history → reliability analytics (MTBF, top offenders)**
  *(effort L)* — only `is_active` today; no "which chiller fails most, what does it cost." Real, but
  it's the analytics layer Maximo Health / Fiix Foresight are *sold* on — build the primitives
  (downtime/failure codes) before the dashboard. *[05](05-assets-parts-procurement.md)*
- **Internal labor / job-cost on a WO + photo-evidence completion gate** *(effort M)* — parts and
  external quotes are costed and GL-posted, but in-house labor hours aren't captured (internal
  maintenance cost is understated), and a WO closes on its checklist without required evidence
  (deferred, open question E.4). *[04](04-work-orders-sla.md)*
- **Mobile / barcode parts issue & receipt + guided cycle counts** *(effort M)* — issue/receive/count
  are all web forms; no scan, and counts are ad-hoc adjustment movements with no guided workflow or
  freeze. *[05](05-assets-parts-procurement.md)*
- **Multi-quote / RFQ bid comparison for capex** *(effort M)* — one vendor per request; no
  tender/bid-compare on the tier-3 spend (chiller, fit-out) where owners expect "3 quotes compared."
  A governance gap. *[05](05-assets-parts-procurement.md)*
- **Finish the warehouse-transfer stub + weighted-average costing** *(effort S/M)* — `transfer_in/out`
  enum values exist with no paired action wired; standard-costing drifts if `unit_cost` is edited
  between receipt and consumption. Both are known, bounded cleanups. *[05](05-assets-parts-procurement.md)*
- **3-way-match tolerance / variance hold** *(effort M)* — the bill clears GRNI up to received value,
  but there's no tolerance, no variance hold, no match status, so overbilling above the receipt passes
  silently as expense. *[05](05-assets-parts-procurement.md)*

### ⏭️ Enterprise-only or N/A to a single-EGP mall operator — decline explicitly

Real in the benchmarks, but *not* a to-do list here — chasing these is how Atriom becomes a worse
generic Yardi/Maximo instead of a great Egyptian mall system:

- **IoT / BMS condition monitoring + predictive maintenance** (Facilio's core pitch, Maximo
  Predict) — deliberately out of scope; no sensor estate to feed it.
- **Automated POS / sales feed** — Egyptian tenants won't expose a POS; the file-first + mobile
  sales-declaration capture is the *correct* market fit. Keep it. (Sales analytics on the declared
  figures — occupancy-cost ratio, sales/m² — is the part worth doing; the POS integration is not.)
- **Vendor marketplace / vetted provider network** (ServiceChannel Fixxbook) — a network effect for
  multi-client platforms; irrelevant to one operator with a known contractor bench.
- **Deep reliability-engineering analytics program** (Maximo Health, Fiix Foresight at full depth) —
  disqualifying to skip for heavy industry, a narrower nicety for a mall. Build criticality +
  downtime primitives; don't build the enterprise analytics suite.
- **Office-lease CAM constructs** (base year / expense stop) — marginal for EG retail malls.
- **Interest-bearing / trust-segregated deposit accounts** — not the Egyptian norm here.
- **Skills / geolocation / load-balanced dispatch routing** — the count==1 auto-assign rule is a
  defensible, low-surprise policy for a single mall's zone/supervisor map.
- **Multi-currency / multi-entity consolidation** — already declined in the Odoo analysis; still N/A.

---

## 4. Strengthen vs decline

**Strengthen — build these, they close a real gap the operator or owner will feel:**

- **Property:** owner statements + disbursements (#1 — it's the operator-for-owner deliverable);
  the CAM recovery-clause engine (caps / admin fee / gross-up — real bookable revenue); automated
  rent escalation; the PDC register (market-fit *and* a differentiator none of the benchmarks
  ship); utility tariff automation; the cheap deposit-balance/reconciliation layer; lease document
  generation + e-sign.
- **Facility:** the field-technician mobile app (the day-one usability gap); vendor
  compliance/COI tracking + block-non-compliant (safety/liability, cheap via the existing expiry
  scan); meter-based PM triggers; vendor scorecards (aggregation over data you already have); asset
  criticality (one field, high leverage); fit-out permit approval; reorder-driven auto-purchase;
  billing the violation fine to AR.

**Decline — don't chase these; they turn the moat into a commodity:** IoT/BMS + predictive
maintenance, automated POS feeds, a vendor marketplace/network, the full Maximo-grade reliability
analytics suite, office-lease base-year CAM, trust-segregated deposit accounts, skills/geo
load-balanced dispatch, multi-currency/consolidation. The pattern is the same as the Odoo verdict:
**keep layering depth onto the property + facility + Egyptian-books spine; do not grow sideways
toward every-industry breadth.** The specialists win breadth by serving thousands of generic sites;
Atriom wins by being the one system that gets an Egyptian mall's *money* right end-to-end.

**Genuine risks if a mall operator (or Jawad's team) evaluated Atriom today:**

1. **The owner deliverable is the thinnest part of the most owner-facing workflow.** An operator
   whose entire pitch is "we run your mall for you" cannot hand the owner a statement or track a
   payout. This is the first thing an owner asks for and it's absent — the highest-severity gap in
   the analysis, and it undercuts the moat's own story.
2. **The headline localization differentiator is dormant.** ETA e-invoicing is built and tested but
   **disabled by default / mock**, submission is manual, and it's invoices-only (no debit/credit-note
   documents). A prospect told "we do Egyptian e-invoicing" would find it switched off and
   uncertified. Same shape on **Paymob** — the tenant payment rail is card-only, config-gated, and
   not live.
3. **Atriom will dispatch a blacklisted / non-compliant vendor without complaint.** The maintenance
   form assigns a blacklisted `vendor_id` with no COI/insurance gate — a real safety/liability
   exposure a mall's risk team would flag immediately.
4. **No field-tech mobile app.** Anyone arriving from Facilio/MaintainX/Limble expects engineers to
   run checklists and attach photos on a phone; Atriom puts them on a desktop screen.
5. **The CAM clause engine can't book standard mall revenue** — anchor caps and a 10–15% admin fee
   are lease economics the operator negotiates and then *can't charge* without a code change.

None of these are architectural — the spine is sound and the fixes are completeness, not rewrites —
but #1 and #2 are close enough to the moat's own promise that they read as risk, not backlog.

---

## 5. How this feeds the roadmap

Two clusters, mirroring the Odoo file's structure:

- **The property 🔴 cluster** — owner statements + disbursements, the CAM recovery-clause engine,
  automated escalation, the PDC register — is the "operator-for-owner + mall lease economics" band.
  Owner statements should be treated as the highest-priority *business* gap in the entire competitive
  analysis, ahead of any facility item, because it's the relationship's core deliverable.
- **The facility 🔴 cluster** — field-tech mobile app, vendor compliance/block, meter-based PM,
  scorecards, criticality — is the "make the FM arm as day-one-usable as the books are trustworthy"
  band. The mobile app and the compliance gate lead it (usability + liability).

Certifying ETA + Paymob and closing the blacklisted-vendor gate are **de-risking** items, not
features — they make the moat that already exists actually *usable/safe*, and belong near the top
regardless of the roadmap band they land in. The ⏭️ list should be explicitly declined in
[ROADMAP.md](../../ROADMAP.md) so nobody mistakes the specialists' breadth for a backlog — the same
discipline the [Odoo synthesis](../odoo/README.md) applied to Odoo's.

---

## 6. The domain deep-dives

| # | Domain | Benchmarks | Net |
|---|---|---|---|
| [01](01-lease-billing.md) | **Lease lifecycle & billing** | Yardi Voyager · Re-Leased | At-par-to-behind on lease-admin depth; **ahead** on Egyptian VAT/ETA + billing-engine correctness. |
| [02](02-cam-turnover-rent.md) | **CAM reconciliation & turnover rent** | Yardi Voyager · Re-Leased | **Behind** on the recovery-*clause* engine + retail turnover depth; **at-par-to-ahead** on true-up settlement + tie-out discipline + Egyptian fit. |
| [03](03-deposits-utilities-portal-owner.md) | **Deposits · utilities · portal · owner** | Yardi · Re-Leased · AppFolio | At-par-to-ahead on self-service + the deposit *ledger*; **behind** on owner statements/payouts + utility recharge; PDC gap none fill. |
| [04](04-work-orders-sla.md) | **Work orders (PPM+CM) & SLA/penalties** | Facilio · ServiceChannel · Maximo · MaintainX/Limble | At-par-to-**ahead** on the CM/SLA/penalty-to-AP core; **behind** on the field-tech + vendor edge. |
| [05](05-assets-parts-procurement.md) | **Assets · spare parts · procurement** | IBM Maximo · Facilio · Fiix | **Behind** on reliability depth; **at par** on register + inventory + procure-to-stock; **ahead** on accounting-grade GL integration. |
| [06](06-vendors-areas-permits-violations.md) | **Vendors/contracts · areas/routing · permits/violations** | ServiceChannel · Facilio | At-par on routing/intake; **ahead** on penalty-to-bill + per-property scoping; **behind** on vendor lifecycle breadth + permit/violation workflow depth. |

> **A caution on the competitor side.** These docs distinguish confident product facts from
> *(verify)* claims that are edition-, region-, or version-sensitive — especially whether
> Facilio/ServiceChannel/Maximo auto-*post* a liquidated-damages penalty to AP vs merely *track*
> SLA compliance, the exact per-site SLA / meter-based-PM boundaries, and Yardi's ME/India PDC
> handling. Confirm against the specific edition before quoting a boundary to the owner.
