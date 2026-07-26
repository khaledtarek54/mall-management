# Atriom — Consolidated Discovery Notes → Functional Requirements

> Source: TriTech / Jawad Developments — Val Plaza discovery notes (3 meetings:
> Accountant · Eltizam Operations · General Questionnaire). This file turns those raw
> notes into traceable functional requirements (FRs) tagged against the **current build**.
>
> Companion to [answers collected](client-discovery-questionnaire.md); all open questions are in the
> single hand-out [../OPEN-QUESTIONS.md](../OPEN-QUESTIONS.md). Reconciled against `app/Models`,
> `database/migrations`, `app/Services`, `app/Filament`, and `docs/modules/*` on 2026-07-03.
>
> _Created: 2026-07-03._

## Legend

| Tag | Meaning |
|---|---|
| ✅ | **Built** — exists today, reuse as-is |
| 🟡 | **Partial** — some of it exists; needs extension |
| ❌ | **Not built** — net-new |
| ⏸️ | **Deferred / undecided** — out of current scope by decision or pending input |

**Big picture:** the money *engine* these notes ask for is largely already built — Atriom
has a full double-entry GL, all three financial statements, VAT, CAM, marketing levy, payroll,
deposits, vendor bills/expenses, and period close (modules 05–21, ~1,450 tests). The real gaps
cluster in four areas: **owner↔operator money flow**, **inventory/warehouse**, **HR depth
(advances/custody)**, and **hardware integrations (gates/parking)**.

---

## 0. Scope decisions captured (2026-07-03)

These four decisions steer the FRs below:

| # | Decision | Effect on FRs |
|---|---|---|
| D-1 | **Owner↔operator money flow — deferred** (document as future) | REV-3, REV-4, LEASE-2 marked ⏸️. Keep single-operator model for now. |
| D-2 | **"Vuala" — not relevant; Atriom is the book of record** | INT-1 resolved → out of scope. No export / no integration. |
| D-3 | **Inventory — build full inventory + consumption costing** | INV-1…4, COST-1 approved for a net-new module (highest new-build priority). |
| D-4 | **Gate-traffic & parking hardware — deferred** | RPT-4, RPT-5 marked ⏸️. |

---

## 1. Accountant meeting → FRs

| ID | Functional requirement | Status | Evidence / note |
|---|---|---|---|
| **FIN-1** | Produce trial balance, cash-flow statement, balance sheet (+ income statement) — full accrual statements | ✅ | Module 21; all 4 statements + bilingual RTL PDF, per-property & consolidated |
| **FIN-2** | Load Mr. Ibrahim's chart of accounts as the reference COA | 🟡 | Editable tree COA with guardrails exists (`ChartOfAccountsSeeder`, `AccountCodeMatchesType`); his specific chart must be entered/reconciled — **needs his file** (Q-A) |
| **FIN-3** | Book paid-in capital from Jawad; match commercial-registry amount (equity) | 🟡 | `capital` / `retained earnings` equity accounts seeded; posting is a manual journal entry — no guided "capital declaration" record |
| **REV-1** | Revenue starts only when a unit is leased (vacant units = "stock") | 🟡 | Behaviour matches (no charges/invoice until an active lease); vacant units are **not** carried as inventory/deferred on the books |
| **REV-2** | Revenue filterable by category (rent, service, utility, %-rent, marketing) | ✅ | Income-statement lines + analytical dimensions (`asset/tenant/lease`) on journal lines |
| **REV-3** | Split revenue between rent and **owner entitlement** on an accrual basis | ⏸️ | **Deferred (D-1).** No operator→owner split exists |
| **REV-4** | Track rent's **source and destination** (tenant → operator → owner) | ⏸️ | **Deferred (D-1).** Double-entry captures source/destination accounts for cash/AR/revenue; the **owner leg** is the deferred part |
| **COST-1** | Maintenance/supply costs recognized from **stock consumption** (as used) | ❌→build | **Approved (D-3).** Today vendor bills/expenses are lump-sum by category, not material-based |
| **VEND-1** | Separate maintenance vendor companies, tagged distinctly | ✅ | Vendor `type` = contractor / supplier / service_provider / consultant / other (form + table column + filter + CSV) |
| **LEASE-1** | Capture contract start/end (from–to) per contract | ✅ | Module 04 leases |
| **LEASE-2** | Support 4 contract revenue models: (a) 100%-owner, (b) operator service-fee, (c) operator charges tenant, (d) owner/operator %-split | ⏸️ | **Deferred (D-1).** Lease has no `revenue_model`/`contract_type` |
| **INT-1** | "Vuala" accounting app — integrate / replace / ignore | ✅ resolved | **Out of scope (D-2):** Atriom is the system of record; no Vuala integration |
| **HR-1** | Payroll flows into accounting | ✅ | `Payroll` batch run posts salaries/tax/insurance to GL (Phase 3) |
| **HR-2** | Employee **advances / staff loans / dues** flow into accounting | ❌ | No `Employee` model, no advances/loans/dues |
| **TREAS-1** | Main treasury + petty/sub-treasuries, custodies & advances (عهدة), banks, tenant balances — each with currency | 🟡 | Only as flat COA cash/bank accounts; no treasury/petty-cash/custody module; tenant balances = AR |
| **TREAS-2** | Per-account **currency classification** (multi-currency) | ❌ | `currency` fields exist but hardcoded EGP; no FX/exchange-rate (Q-F) |
| **FA-1** | Fixed-asset register + depreciation (الإهلاك) | ❌ | Furniture / accum-depreciation / depreciation-expense accounts seeded but **dormant** — no register, no depreciation run |
| **INV-1** | Warehouses/inventory (leased assets, fixed assets, general assets, supplier items) | ❌→build | **Approved (D-3)** — see §4 |
| **PA-1** | "Personal accounts" (محسوبات شخصية) for individuals | 🟡 | Can be added as COA accounts; no dedicated individual/sub-ledger type — **needs definition** (Q-B) |

---

## 2. Eltizam operations meeting → FRs

| ID | Functional requirement | Status | Evidence / note |
|---|---|---|---|
| **DEPT-1** | Model Eltizam's departments & how each operates | 🟡 | 5 fixed departments (HR, Accounting, Marketing, Leasing, Operations) + membership→role |
| **DEPT-2** | Department **org hierarchy** (Leasing Mgr→Mgr→Head; Ops Financial/Admin/Operations; Head over facility checklist) | ❌ | Only a free-form role label on membership; no reporting hierarchy/titles |
| **MNT-1** | Shared facility **checkup checklist** co-owned with facilities (cleaning, soft/hard services, toilets) | ✅ | Module 26 PPM checklist: `maintenance_plans.checklist` → `maintenance_work_order_items` (pass/fail) + the FR-PPM-07 close gate; plan `category` covers cleaning / soft-service |
| **MNT-2** | Scheduled-service notifications generated from facilities input | 🟡 | **Generator BUILT** (module 26 `maintenance:generate-preventive` raises a work order per due plan); the **notification** on generation is still missing (2026-07-26) |
| **MNT-3** | Ad-hoc urgent tickets on top of scheduled | ✅ | Reactive requests (module 11) |
| **MNT-4** | PM supervises facility; ticket handed off Operations → Eltizam | 🟡 | Department routing + `redirectToDepartment()`; no explicit PM approval checkpoint |
| **INV-2** | Daily-consumables tickets: whoever enters item raises ticket; engineer logs what was used + work done | ❌→build | **Approved (D-3)** |
| **INV-3** | Main inventory (spare parts, deep-clean machines) + usage on both inventory types | ❌→build | **Approved (D-3)** |
| **INV-4** | 3 warehouse/inventory categories | ❌→build | **Approved (D-3);** 3rd category name **unknown** (Q-C) |
| **SVC-1** | Per-service: chargeable (billed out) vs absorbed as unit expense, **plus an annual report either way** | 🟡 | Service-charge billing + CAM exist; no per-service chargeable/expense toggle or unified annual service report (Q-D) |
| **RPT-1** | Facility reports: daily work log, cleaning activity | ✅ | `FacilityWorkLogPdfService` — bilingual PDF of work orders per property over a date range (summary by status + category + detail), from the work-order list; cleaning shows via the category grouping |
| **RPT-2** | Financial reports: rent, turnover-rent, service charge, marketing | ✅ | Income statement + reports (module 17); %-rent (module 09) |
| **RPT-3** | Sales reports for revenue share | ✅ | Tenant sales declarations → percentage rent (module 09) |
| **RPT-4** | Traffic-flow reports integrated with **gate systems** | ⏸️ | **Deferred (D-4).** No hardware integration |
| **RPT-5** | Parking reports with **card + gate** integration | ⏸️ | **Deferred (D-4).** No parking module/hardware |
| **VEND-2** | Treat vendors as **contractors** with contract attached (start/end + expiry) | ✅ | Vendor contracts + `vendors:expire-contracts` scan (module 12) |
| **VEND-3** | Log of who (vendor/person) did what work | 🟡 | Activity-log records vendor **assignment**; no structured vendor work-log (materials/hours/notes) — links to COST-1/INV |

---

## 3. General requirements questionnaire → FRs

| ID | Functional requirement | Status | Evidence / note |
|---|---|---|---|
| **ACCESS-1** | Val internal support/workflow **hidden from Eltizam** | 🟡 | Owner scoping + owner-to-owner requests hidden from operators; but **no owner-private notes/documents** (`Note` has no visibility flag) |
| **NOT-1** | Val notified when Eltizam acts late | ✅ | `maintenance:scan-sla-breaches` notifies owners on SLA breach |
| **REQ-1** | Only admins can raise a maintenance/request | ✅ | Portal: tenant-admin only; admin panel: `maintenance.create` RBAC |
| **DEPT-3** | Core departments HR/Accounting/Marketing/Leasing | ✅ | Present (+ Operations) |
| **DEPT-4** | Departments contact each other via in-app notifications | ✅ | `DepartmentMessageService` fan-out notification |
| **ACCT-2** | Inter-department requests/payments routed through Accounting for approval | ⏸️ | Explicitly deferred pending accounting-team workflow definition (Q-E) |
| **REQ-2** | Closed requests immutable | ✅ | `isTerminal()` guards at model/service/UI |
| **OWN-1** | Jawad→Eltizam **and** Jawad→Jawad requests | ✅ | `OwnerRequest.recipient` = operator / owner (module 15) |
| **NOT-2** | Late maintenance / late fees → notify Jawad | 🟡 | SLA-breach → owner ✅; a **late-fee-specific** owner alert is not distinct |
| **REQ-3** | Maintenance assigned to a department; admin can **redirect** or **reject**; departments visible to admin | ✅ | `redirectToDepartment()` + cancel/reject; admin visibility |
| **REQ-4** | Requests carry from-date/to-date **with time** | ✅ | `scheduled_from` / `scheduled_to` datetimes |
| **LEASE-3** | One master unit → multiple units under one lease; different units → separate leases | ✅ | Master-unit model (module 04) |
| **TEN-1** | Tenant fields: National ID, ID card (بطاقة), commercial registry, company name, responsible person name/phone, email | 🟡 | All present **except a typed ID-card document** (only a generic untyped documents upload) |
| **MKT-1** | Marketing runs promotions, events, printed materials for tenants | 🟡 | These exist only as **spend categories**; no campaign/event planning, targeting, or outcomes |
| **MKT-2** | Marketing levy 5%, adjustable | ✅ | `MarketingSettings.levy_rate_percent` (system-wide; no per-property override) |
| **MKT-3** | Marketing budget visible in dept; receipts flow to accounting; budget auto-updates | ✅ | Budget visible + auto-derived; levy **collection** hits GL via tenant invoice; **spend now posts to GL** via `MarketingSpendJournalizer` (Dr Marketing Expense / Cr Cash\|Bank), swept by `accounting:sync-ledger` — shipped 2026-07-03 |

---

## 4. New-work backlog (ranked, reflecting the 2026-07-03 decisions)

**In scope / approved to build:**

1. **Inventory / warehouse module** (INV-1…4, COST-1, ties VEND-3) — 3 warehouses (spare
   parts, deep-clean machines, daily consumables — 3rd name TBC), stock items, per-ticket
   **consumption logging** by the engineer, and **cost recognized as materials are used**
   (consumption → GL expense). Highest-priority net-new build (D-3).

**Shipped since (2026-07-04):**

2. ✅ **HR depth** (HR-2) — employee master + advances/loans (سلف, → GL) + per-employee
   payroll lines & bilingual payslip PDFs. Module 24, complete. (Dues + payroll-deduction
   of advances deferred to a future Phase 3b.)
3. ✅ **Fixed-asset register + depreciation** (FA-1) — register + straight-line depreciation
   + full GL posting (acquisition, depreciation, disposal write-off). Module 23, complete.
5. ✅ **Preventive-maintenance / facility checklist** (MNT-1/2) + **facility work-log
   report (RPT-1)** — recurring plans auto-raise work orders with checklists (daily scan),
   completion tracking, and a bilingual work-log PDF. Module 26, **complete**.

**Partially shipped:**

4. 🟡 **Treasury** (TREAS-1/2) — ✅ **custodies (عهدة)** posting to the GL (grant + expense/
   return settlements, module 25 Phase 1). ⏳ Still open: multi-treasury / petty-cash boxes,
   and **multi-currency (TREAS-2)** — blocked on **Q-F** (is anything billed in USD/EUR?).

**Still open / lower priority (not yet decided to build):**
6. **Marketing campaign/event management** (MKT-1) — spend→GL posting (MKT-3) shipped 2026-07-03.
7. **Owner-private notes/documents** (ACCESS-1); department→accounting approval routing (ACCT-2).
8. **Typed ID-card document** on tenant (TEN-1); **service chargeable/expense toggle + annual
   report** (SVC-1); **personal-accounts** account type (PA-1); department org hierarchy (DEPT-2).

**Deferred by decision:**

- **Owner↔operator money flow** (REV-3/4, LEASE-2) — deferred (D-1).
- **Gate-traffic & parking hardware** (RPT-4/5) — deferred (D-4).

**Resolved / out of scope:**

- **Vuala integration** (INT-1) — Atriom is the book of record (D-2).

---

## 5. Open questions still needing client input

These (Q-A chart of accounts, Q-B personal accounts, Q-C 3rd warehouse category, Q-D service
chargeable toggle, Q-E accounting approval chain, Q-F multi-currency, Q-G owner/Jawad parties, Q-H
post-dated cheques) are consolidated into the single hand-out, mapped to the answering department:

> ➡️ **[../OPEN-QUESTIONS.md](../OPEN-QUESTIONS.md)** (see sections A4, C3.7, C3.3, C3.8, C3.6, A7.4,
> B.3, A7.1 respectively).

---

*Keep current: when a backlog item ships, move its FR to ✅ and add a note. When an open
question is answered, fold the answer into the FR and the [client questionnaire](client-discovery-questionnaire.md).*
