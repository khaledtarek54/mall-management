# Atriom — MVP-Readiness Program

> Take the whole system to a **high-standard, competitive, first-client-MVP** state — module by module — benchmarked against property **and** facility-management specialists. This doc is the charter for that program: the mission, the per-module pass, the process, and live status. The authoritative per-module findings live in [`docs/gap-analysis/`](gap-analysis/) and the closure ledger [`PROPERTY-FACILITY-CLOSURE.md`](gap-analysis/PROPERTY-FACILITY-CLOSURE.md).

## 1. Mission

- **Client:** Eltizam (operator) running Egyptian malls for owners (Jawad). This is the **MVP for the first real client** — it must be trustworthy on money and pleasant to operate, not a demo.
- **Bar:** a genuinely high-level app with high standards — correct books, verifiable numbers, clean UX, bilingual (EN + RTL Arabic), scalable and maintainable **by default**.
- **Benchmark:** the leasing/AR specialists (Yardi, MRI) **and** the facility-management specialists (Angus, Building Engines, Planon, MRI). For every module we ask: *what do the specialists do that we don't, and is it MVP-critical for this client or a fair deferral?*
- **Moat we are protecting:** Egyptian books (VAT 14% / exemptions, ETA e-invoicing, marketing levy), a real double-entry GL with a property/asset dimension, SLA→AP automation, airtight per-property isolation — the things generic global platforms localize poorly.

## 2. The six-dimension pass (every module)

Each module is taken to production-grade across **all six** dimensions — not just one:

| # | Dimension | What "done" means |
|---|-----------|-------------------|
| 1 | **Business-model gap vs competitors** | Compare against P&FM specialists; list what's missing; classify each gap **MVP-critical** (our client needs it / competitors all have it) vs **deferrable** (with an explicit trigger). "Competitors have it" alone ≠ build it — the test is whether *our* client's reality needs it. |
| 2 | **UX enhancement** | The UX-completeness checklist: **verifiable numbers** (a native "View working" breakdown wherever a computed figure appears), native Filament (no hand-rolled Blade), clear action **feedback** with resulting state, **honest** modal copy, reactive forms, and **EN+AR i18n added in the same change** (no raw keys). |
| 3 | **Module completion** | The missing slices that make it functionally complete for the client's real workflow. |
| 4 | **GL completion & correctness** | Every money source on the `LedgerPoster::JOURNALIZERS` single registry; a real `accounting:sync-ledger` sweep tie-out test per source; closed-period guards (`PostingDate`); `billing:reconcile` ties out. |
| 5 | **Bug hunt** | An adversarial correctness review (multi-agent) before every push — find the input→wrong-output, don't trust "looks right". |
| 6 | **Recommendations** | What would make the module fully functional + competitive beyond the MVP — captured as deferrals with triggers, so nothing is lost. |

## 3. Process

- **Audit** — per-module (or grouped) **read-only** agents produce ranked findings (HIGH / MEDIUM / LOW) against the six dimensions.
- **Triage** — fix HIGH + MEDIUM now; defer LOW **with an explicit trigger**; retire false "X is missing" findings with the reason (the mechanism usually lives in another layer).
- **Adversarial review** before every push (a fresh pass on the diff, not just green tests).
- **Definition-of-done gates:** `vendor/bin/pest --parallel` green · PHPStan no new errors above the baseline · `billing:reconcile` ties out · module doc + memory updated **in the same commit** · pushed to `main`.
- **Standing decisions** (don't re-ask): pick the **MVP subset competitors have**, defer the rest; scalability + maintainability are non-negotiable defaults; no DB-level enums; native Filament over Blade; property isolation + authz double-gating on every write action.

## 4. Module status matrix

Legend: ✅ done · 🔄 in progress · ⬜ not started · — n/a. "Biz/Compl/GL/Bugs" for the AR/leasing spine were taken to CLOSED in the earlier close-out; **UX** is the dimension being added across the program now.

| # | Module | Biz-gap | UX | Completion | GL | Bugs | Overall |
|---|--------|:---:|:---:|:---:|:---:|:---:|---|
| 01 | Properties & Units | ✅ | ✅ | ✅ | — | ✅ | **UX pass done** |
| 02 | Tenants | ✅ | ✅ | ✅ | — | ✅ | **UX pass done** (HIGH authz fixed) |
| 04 | Leases | ✅ | ✅ | ✅ | ✅ | ✅ | **UX pass done** |
| 05 | Billing & Invoices | ✅ | ✅ | ✅ | ✅ | ✅ | **UX pass done** (HIGH authz fixed) |
| 06 | Payments (+ tenant credit) | ✅ | ✅ | ✅ | ✅ | ✅ | **UX pass done** (`b0740e1`) |
| 07 | Credit Notes | ✅ | ✅ | ✅ | ✅ | ✅ | **UX pass done** (`88814a7`) |
| 08 | CAM reconciliation | ✅ | ✅ | ✅ | ✅ | ✅ | **UX pass done** (`6ba6fb1`) |
| 09 | Tenant Sales & % Rent | ✅ | ✅ | ✅ | ✅ | ✅ | **Annual + UX done** (`8b2ca48`) |
| 10 | Utility Meters | ✅ | ✅ | ✅ | ✅ | ✅ | **Recharge built** — readings can now be billed |
| 11/26 | Facility work (requests · work orders · service schedules) | ✅ | 🟡 | ✅ | — | 🟡 | **Generalised** to any facility service |
| 12 | Vendors & Contracts | ✅ | ✅ | ✅ | ✅ | ✅ | **COI chase + commitment tracking** — certs no longer lapse silently |
| 29 | Procurement | ⬜ | ⬜ | 🟡 | ⬜ | ⬜ | Later |
| 32 | Owner Statements | 🟡 | ⬜ | 🟡 | ✅ | 🟡 | Later |
| 33 | Post-dated Cheques | 🟡 | ⬜ | 🟡 | ✅ | 🟡 | Later |
| 10–33 | (remaining) | ⬜ | ⬜ | — | — | ⬜ | Backlog — see the ledger |

*The full ordered list (including 03 Tenant Portal, 15 Owner Requests, 16 ETA, 17 Reports, 30 Areas, 31 Violations, and the generic-ERP layer that is intentionally frozen) is in the [closure ledger](gap-analysis/PROPERTY-FACILITY-CLOSURE.md).*

## 5. Current focus — the "first 8" (the AR / leasing spine)

Modules **01, 02, 04, 05, 06, 07, 08, 09** — the money-critical core the client touches daily. UX pass:

- **Done:** 09 annual (cumulative) % rent — module completion **and** the operator/tenant UX; then the UX pass over **06 tenant-credit**, **07 Credit Notes**, **08 CAM** (each: verifiable "View working" breakdowns, caught `DomainException`→toast instead of 500, native components, richer/branched feedback, honest modal copy, EN+AR keys — with per-module adversarial review + tests).
- **Also done — 01, 02, 04, 05.** Two **HIGH authz holes** closed (the systemic `visible()`-only class): Tenants **`portalAccess`** (a `leasing` user could set/reset any tenant's portal password via a crafted dispatch) and Billing **`runMonthlyBilling` + ETA submit/bulk-submit** (viewer/owner could trigger a property-wide billing run or file tax invoices). Plus: Leases `renew`/`changeRent`/`terminate` now catch the service guard instead of a Livewire 500; the Assets list column titled *Occupancy* actually showed raw leasable area (relabelled); English label fallbacks in the Arabic panel fixed (country/currency/areas/is_active/description); a manually-added `base_rent` line no longer defaults to 14% VAT (base + % rent are exempt); the late-fee line now states its basis (`2% of EGP X overdue, min EGP 50`) instead of a bare "Late Fee"; `reverse_credit` catches its guard.
- **The "first 8" (the AR/leasing spine) is now complete across all six dimensions.**
- **Next:** modules 12+ (Vendors → Procurement → Owner Statements → PDC → …), each through the full six-dimension pass.

## 5c. Facility work (modules 11/26) — the domain model, and what changed

**There is no standalone "maintenance module", and that is correct.** The system already has the three-layer structure every CMMS/IWMS is built on — and they must stay distinct (not every request becomes work; one request can spawn several work orders; and **planned work has no request at all**):

| Layer | Here | Holds |
|---|---|---|
| **Demand (intake)** | **Tenant Requests** | `request_type` + `category`, priority, SLA target, area, department, vendor, CSAT — maintenance is one *category* |
| **Execution** | **Work Orders** | ppm/cm, internal/external, targets asset/unit/**area**/equipment, SLA, vendor/assignee, parent WO, `tenant_request_id`, **fault party + cost bearer** (tenant chargeback), parts |
| **Planned generator** | **Service Schedules** (was "Maintenance Plans") | target + discipline + cadence + **checklist** → raises work orders |

**What changed (2026-07-22).** FM splits work into **hard services** (HVAC, electrical, lifts — *equipment*-centric PPM) and **soft services** (cleaning, landscaping, pest, waste, security — *location*-centric rounds). Both belong in the same work-order engine; they differ only in target and cadence. Plans and work orders could target asset/unit/equipment but **not an area**, so soft services — which this operator schedules in-house — could not be planned at all. Now:

- **`area_id` on schedules and work orders** — a round knows *where* it happens ("clean the food court"), and the generator carries the location onto the raised order so it still says where after the plan changes.
- **`days_of_week` on schedules** — "every Mon/Wed/Fri" rounds. Empty = any day, so every existing plan behaves exactly as before.
- **Discipline vocabulary broadened** — added landscaping, pest control, waste management, security alongside the maintenance trades.
- **Relabelled** to *Service Schedule(s)* so an operator scheduling cleaning isn't staring at a screen called "Maintenance Plans" (labels + i18n only; tables unchanged).

**Deliberately NOT built — sub-daily work orders.** "Clean twice daily" as two work orders per day is 700+ orders a year per area of pure noise. The FM convention is **one daily work order whose checklist carries the rounds** ("morning round", "evening round") — which `checklist` already supports. *Trigger to revisit: a client needing per-round sign-off with distinct times/assignees.*

**Deferred (triggers):** meter/usage-based triggers (service every 500 runtime hours — *trigger: equipment with runtime metering*); condition-based triggers; route/patrol schedules covering many areas in one order; per-discipline SLA targets.

## 5d. Module 12 — Vendors & Contracts (done)

**The compliance gate already existed** (`5df09c0`): a vendor whose Certificate of Insurance has lapsed is dropped from every assignment picker (`Vendor::assignable()`) and refused at the real gate (`MaintenanceWorkOrder::saving()`). The initial scan claimed "COI is never enforced" — **that was a false absence finding**, caught by verifying before fixing. What was actually missing were the two things that make the gate usable:

- **The cert lapsed *silently*.** No warning beforehand, no explanation afterwards — the operator's contractor simply vanished from a dropdown. Now `vendors:scan-coi-expiry` (daily 02:40) chases at **30 days out** and again **on lapse**, stamped with *both* the stage and the exact cert date, so a re-run never re-nags, an escalation alerts once more, and **renewing the cert re-arms the cycle by itself**. Recipients come from *engagement* — staff of the properties where the vendor actually holds an active contract (vendors are a shared portfolio catalog), falling back to portfolio roles. Backed by an **Action Required card** + a **"Insurance lapsed / lapsing" table filter**, all three reading the same `Vendor::coiNeedsAttention()` scope so they can never disagree. Delivery failure warns but still stamps — the live card is the backstop, so a dropped notification can't hide a lapsing cert.
- **`vendor_contracts.value` was decorative.** A bill was never tied to the contract it was incurred under, so nothing compared committed vs actually invoiced — a EGP 500k contract could quietly absorb EGP 5m of bills. Now `vendor_bills.vendor_contract_id` (nullable — ad-hoc call-outs have no contract) drives **committed / billed-to-date / remaining** on the contracts list, red once over-run, and a live helper on the bill form spelling out the arithmetic (`committed − billed = remaining`) rather than a bare figure. Cancelled bills don't consume the commitment. It's a **flag, not a block** — change orders and overruns are legitimate; hiding them isn't.

**Verified sound, not changed:** every vendor/AP write action already uses `->authorize()` (a real Filament gate, unlike `visible()`), `VendorBillService::approve()` re-checks state, `recordPayment()` locks, `cancel()` guards; `VendorBill`/`VendorBillPayment`/`MaintenancePenalty`/`Disbursement` are all on the GL registry.

**Deferred (triggers):** hard-blocking a non-compliant vendor at *award* time on purchase requests (*trigger: a client whose procurement policy requires it* — a block without an emergency override stops 2am burst-pipe work); COI document-expiry OCR; per-contract SLA scorecards.

## 5b. Module 10 — Utility Meters (done)

**The gap was a missing revenue path.** Readings were recorded (consumption even auto-derived from the prior reading, with a rollover guard) — but there was **no tariff on the meter**, so `cost` was hand-typed into a NOT-NULL column, and **nothing turned a reading into an invoice**. `InvoiceItemType::Utility → utility_revenue (41104001)` was already wired in the journalizer and chart: the system *intended* recharge and it was never finished, so every submetered EGP had to be re-keyed by hand (revenue-leak risk + an unverifiable number). Every submetering competitor (Yardi Utility Billing, RealPage, Conservice) bills recharge — MVP-critical.

**Built:** `utility_meters.rate_per_unit` (tariff; blank = a monitored-but-not-recharged landlord/common-area meter) · cost now **derives** as consumption × tariff in the reading form (still overridable, with a helper naming the rate) · **`BillMeterReadingService`** issues a dedicated recharge invoice (one `utility` line + 14% VAT — a taxable supply, unlike base rent), lock-safe and **idempotent** via `meter_readings.billed_invoice_id`/`billed_at`, refusing when the meter has no unit, no active lease, or no cost · a **"Bill to tenant"** action (double-gated visible + `abort_unless`) and a "Recharged / Not billed" column so un-billed consumption is visible.

**The trap caught while building it:** the recharge invoice is dated to the **consumption month**, which overlaps `MonthlyBillingService`'s already-billed probe — so a recharged month would have read as "already billed" and the monthly run would have **silently skipped that lease's base rent**. `utility` is now excluded from that probe, exactly as `percentage_rent`/`cam_*` are, with a regression test.

**Deferred (triggers):** tiered/slab tariffs (Egypt's electricity brackets) + time-of-use — *trigger: a meter whose provider bills in brackets*; standing/ minimum charges; RUBS-style apportionment of unmetered common usage; estimated readings + a cost-recovery reconciliation (recharged vs the actual provider bill); meter multiplier/CT ratio; bulk "bill all unbilled readings for the month".

### Deferred from the first-8 UX pass (recommendations, with triggers)
Non-blocking; captured so nothing is lost. **Leases:** an on-screen "working" for derived figures (next escalation date + escalated rent, levy EGP/month, deposit multiple), reactive escalation-rate field (hide for `none`/CPI, which the sweep skips), CAM cap-term resolved-ceiling column, renew-modal term preview, relation-manager empty states. **Billing:** distinguish the *lock-skipped* monthly run from "nothing to bill" (currently a green success with zeros), a read-only itemised invoice View, per-line VAT column, status filter for cancelled/credited/disputed. **Properties:** surface real occupancy % (`Asset::occupancyRate()` exists but is unsurfaced), a guard so force-deleting a unit/asset with lease dependents is a toast not an FK 500, confirm whether the Units resource should stay labelled "Tenant Directory". **Tenants:** a derivation tooltip on the on-account credit badge. **Project-wide:** exporter completion toasts are hardcoded English (systemic — own sweep).

## 6. Recurring gaps found (so the pass stays sharp)

From the first-8 UX pass, the same defects recur — audit every module for them:

1. **Bare, unverifiable numbers** — a computed money figure with no visible working (fixed with a service `explain()` + a native "View working" modal).
2. **Uncaught `DomainException` → Livewire 500** — service guards (closed period, terminal state, freeze) must be **caught** into a clean localized toast + the offending control disabled/hidden.
3. **All-or-nothing** operations where the client needs **partial/granular** control.
4. **Thin feedback** — toasts that omit the resulting state (new balance, which months re-trued, recover-vs-credit).
5. **Wrong/stale copy at a money moment** — modal descriptions that misstate what a button does.
6. **Missing i18n keys** — a key referenced in code but absent in en/ar renders raw to the user; always add EN+AR in the same change.

## 7. Competitive positioning (why this wins the deal)

- **Egyptian-native books** the global platforms fake: 14% VAT vs base-rent exemption per line, ETA e-invoicing, the 5% marketing levy, Arabic-first RTL.
- **A real GL** (double-entry, property/asset dimension, single-registry money sources, tie-out gates) — not a reporting bolt-on.
- **SLA → AP** automation and **CAM/percentage-rent** engines built for retail malls specifically.
- **Airtight per-property isolation** with self-enforcing conformance gates — a multi-mall operator can trust the walls.

---

*Kept in sync as the program advances. Per-module detail: [`docs/gap-analysis/`](gap-analysis/) · business model: [`docs/business-model/`](business-model/) · the single prioritized list: [`ROADMAP.md`](ROADMAP.md).*
