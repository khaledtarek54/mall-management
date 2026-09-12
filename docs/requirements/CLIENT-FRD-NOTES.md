# Atriom — Consolidated Discovery Notes → Functional Requirements

> Source: TriTech / Jawad Developments — Val Plaza discovery notes (3 meetings:
> Accountant · Eltizam Operations · General Questionnaire) — **plus the 2 September 2026 client
> meeting, worked through point by point against Yardi and the market in [§6](#6-meeting-2026-09-02--decisions-yardi--market--what-exists--recommendation)**. This file turns those raw
> notes into traceable functional requirements (FRs) tagged against the **current build**.
>
> Companion to [answers collected](CLIENT-DISCOVERY-ANSWERS.md); all open questions are in the
> single hand-out [../STATUS.md](../STATUS.md). Reconciled against `app/Models`,
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
| **MNT-1** | Shared facility **checkup checklist** co-owned with facilities (cleaning, soft/hard services, toilets) | ✅ | Module 26 PPM checklist: `service_plans.checklist` → `facility_work_order_items` (pass/fail) + the FR-PPM-07 close gate; plan `category` covers cleaning / soft-service |
| **MNT-2** | Scheduled-service notifications generated from facilities input | 🟡 | **Generator BUILT** (module 26 `facility:generate-preventive` raises a work order per due plan); the **notification** on generation is still missing (2026-07-26) |
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
| **NOT-1** | Val notified when Eltizam acts late | ✅ | `requests:scan-sla-breaches` notifies owners on SLA breach |
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

**Re-checked against the code 2026-08-23 — three of the eight are no longer questions:**

| | Question | Status |
|---|---|---|
| **Q-A** | Chart of accounts | **Still open (A4.1)** — but the accountant's own file is now IMPORTABLE (EG-28), so it is a hand-over rather than a build |
| **Q-B** | Personal accounts (محسوبات شخصية) | **Narrowed (C3.7)** — custody (عهدة) and employee advances are built and post to the GL; what is missing is one sentence on who a personal account is for |
| **Q-C** | 3rd warehouse category | ✅ **CLOSED** — `warehouses.category` is free text; name as many as you like |
| **Q-D** | Per-service chargeable toggle | **Still open (C3.8)** — → code (M) |
| **Q-E** | Accounting approval chain | **Still open (C3.6)** — bands are configurable; a multi-level CHAIN is → code (M) |
| **Q-F** | Multi-currency | ✅ **DECIDED** — EGP only and enforced at the value set (EG-07). A USD-*linked* lease is EG-31, not multi-currency |
| **Q-G** | Owner / Jawad as one party or two | **Still open (B.3)** — part of the money-flow workshop |
| **Q-H** | Post-dated cheques | ✅ **BUILT** (module 33). Only *"hold security cheques as their own class"* remains (A7.1, → code XS) |

> ➡️ The live ones are in **[../STATUS.md](../STATUS.md)**, which is the single list.

## 6. Meeting 2026-09-02 → decisions (Yardi · market · what exists · recommendation)

> **How to read this.** The notes are the client's, verbatim (Arabizi kept as typed). Under each:
> *what it means* · **Atriom today** (checked in the code on 2026-09-11, with file references — three
> of the twenty-five turned out to be already built, and one "bug" is a statutory rate read as a
> book rate) · **Yardi / market** (the [benchmark convention](../benchmarks/yardi/README.md): unmarked
> = stable product knowledge, *(cited)* = in the benchmark folder, ***(verify)*** = confirm against a
> live tenant) · **Recommendation** with effort (XS · S · M · L) and, where we would be STRICTER than
> Yardi, that is stated as the deviation it is. **Decision** is blank until Khaled rules on each; then
> every point that is code runs [`/safe-change`](../../.claude/skills/safe-change/SKILL.md) on its own.
>
> One standing caution: eight of these reopen the **fixed-asset register**, a layer the
> [gap analysis §5](../gap-analysis/README.md#5-the-generic-erp-layer--vs-odoo) froze on 2026-07-18
> ("build effort goes to property and facility"). They are the accountant's own asks, so they are in
> scope as ONE slice; nothing here grows the layer beyond what was asked.

### 6.0 The list at a glance

| # | Ask (short) | Atriom today | Verdict | Effort | Decision |
|---|---|---|---|---|---|
| 1 | Activate the lease only when accounting confirms money received; reservation valid X days | Status dropdown, no money check, no reservation expiry | ✅ **Shipped 2026-09-11** — Activate act (`leases.activate`), per-property gate (`none` ships), reservation window lapsed by `leases:expire` ([modules/04](../modules/04-leases.md)) | M | ✅ built |
| 2 | A page for the accountant to activate | — | ✅ **Shipped 2026-09-11** — the *Awaiting activation* tab + the Activate button (on the lease page too since 2026-09-12, and the status field says why *Active* is withheld) on the row (accounting holds view + activate, not edit) | S | ✅ built |
| 3 | Deposit as % or fixed | Months-of-rent (= % of a month) or fixed | ✅ **Shipped 2026-09-11** — `security_deposit_basis`: months (market) · % of annual rent (the Egyptian clause) · fixed; one derivation | S | ✅ built |
| 4 | Lease PDF from the lease fields; template from Jawad | ✅ **Built** (`LeaseAgreementPdfService`) | **No code until the template arrives**; transpose it into the wording block | S | |
| 5 | Statement: totals due to you / due from you | One-sided (AR only) | ✅ **Shipped 2026-09-11** — *Due from you* / *Held for you*, itemised | (with 6) | ✅ built |
| 6 | Statement shows the deposit, reservation money, debit / credit / balance | Sectioned PDF; deposit held appears nowhere | ✅ **Shipped 2026-09-11** — the PDF is the ledger printed, with a deposit account ([modules/02](../modules/02-tenants.md#tenantstatementpdfservice)) | M | ✅ built |
| 7 | Trial balance: opening · debit · credit · closing | Net movement of the window only — **a correctness defect** | ✅ **Shipped 2026-09-11** — three debit/credit pairs on screen, CSV and PDF ([modules/21](../modules/21-general-ledger.md#a-months-trial-balance-opens-with-the-balance-brought-forward-2026-09-11)) | S–M | ✅ built |
| 8 | Ledger column "Charge" → "Debit"; PDF = the screen, every detail | ✅ **Rename shipped 2026-09-03**; PDF still a different document | ✅ **Shipped 2026-09-11** — one row per invoice line, screen and PDF one derivation | (with 6) | ✅ built |
| 9 | Description says which invoice was paid and how | Method only | ✅ **Shipped 2026-09-11** — *"Bank transfer — for INV-…, INV-…"* | (with 6) | ✅ built |
| 10 | Statement footer "valid for X days" | Fixed sentence | ✅ **Shipped 2026-09-11** — `statement.footer` block at /admin/document-wording | XS | ✅ built |
| 11 | Fixed asset: category first; asset number auto from category; description | Tag typed by hand; category free text | ✅ **Shipped 2026-09-12** — `fixed_asset_categories` catalogue (`/admin/fixed-asset-categories`), class first on the form, tag `{PREFIX}-0001` per property from the class, typed/imported tags kept ([modules/23](../modules/23-fixed-assets.md)) | M | ✅ built |
| 12 | "Tax depreciation" → "Depreciation" | Two bases by law; only the tax one has a screen | **Do not rename**; show the BOOK rate, add the book schedule | S | |
| 13 | Salvage value default 1 | Default 0; nothing hides at 0 | ✅ **Shipped 2026-09-12** — the class's memo value, 1.00 on every shipped class, proposed on pick and by the model; a stated figure wins | XS | ✅ built |
| 14 | Useful life per category; rate % not months; daily | Months per asset; full month, no proration | ✅ **Shipped 2026-09-12** — class default life; the form reads/writes an annual rate % beside the months (months stored); `accounting.depreciation_proration` (`full_month` ships · `days` — the client's ask is SET on staging); posting stays monthly | S | ✅ built |
| 15 | "Funded from" → payment method; vendor existing or new | cash/bank literal; no bank account; no vendor | ✅ **Slice 1 shipped 2026-09-12** — *Paid by* is the outbound rail catalogue + the bank account (the ninth `RecordsBankAccount` document, credit leg in the bank's own chart account); *Supplier* is a vendor row with a "+" gated on `vendors.create`; importer takes the supplier code ([modules/23](../modules/23-fixed-assets.md)). Slice 2 (a supplier BILL that capitalises the asset — Dr asset / Cr AP) not built | S (+M) | ✅ slice 1 built |
| 16 | Cash box / bank can never be credit | No guard | **Build** for cash (SAP's rule); warn for bank | M | |
| 17 | TB daily depreciation; 60 months shows 25% not 20% | 25% = Law 91 tax pool, correct; 20% is the unshown book rate | **Not a bug** — the book rate now shows on the register (the *Annual rate %* column) and the form, beside the tax pool; daily posting declined (14) | — | ✅ closed by 14 |
| 18 | Transfer an asset between places | `asset_id` editable → rewrites history | ✅ **Shipped 2026-09-12** — *Transfer* act on the asset's page (destination · date · reason), two GL legs on the transfer date (OUT of the old property, IN to the new, NBV through `inter_property_clearing`), history stays where it was, depreciation follows from the transfer month, a *Transfers* tab; `asset_id` REFUSED once depreciating ([modules/23 §2.11](../modules/23-fixed-assets.md)) | M | ✅ built |
| 19 | Trial balance as a collapsible tree | Flat list | **Build** a ledger tree (screen + PDF) | M | |
| 20 | Gross and net area per unit | One area | **Build** net area as an informational second measure | S | |
| 21 | The management contract is between the unit owner and Jawad | Terms stored, fee charged by nothing (gap B1) | **Decision, not code** — it answers half of B1 | (M once ruled) | |
| 22 | Possession date: better description if blank → today | Helper exists; nothing computes it | **Ask** what "calculated from today" means; wording only | XS | |
| 23 | Late fees → notify Eltizam to cut electricity/water; anything else? | Tenant + owner notified; no operations step | **Build** a collections stage that raises an operations request | M | |
| 24 | Annual increase on all charges, % or fixed | Base rent (+ same % on service charge) | ✅ **Shipped 2026-09-12** — every charge row carries its own rule (follows the clause · own % · own fixed EGP · none), Yardi's grain; **each parking bay / item by its own rule on its holding, items let with the lease, the rule set from the tabs** (same day); the lease form's *Annual increase* tab and its "Which charges step" table; `billing.new_charges_follow_escalation` per property (off = Yardi) ([modules/04](../modules/04-leases.md), [35](../modules/35-rentable-items.md)) | M | ✅ built |
| 25 | Vending machines, toy cars — "like units", later | ✅ **Built** (rentable items, module 35) | **Nothing now**; a unit type or a rentable item when they return to it | XS | |

**Suggested order for the `/safe-change` runs** — correctness first, then the documents the client reads
weekly, then workflow: **7** → **6+5+8+9+10** (one change) → **1+2+3** → **24** → **11+13+14** (one
slice) → **15** → **18** → **16** → **19** → **20** → **23**. Points 4, 12, 21, 22, 25 need an answer
or a template, not a build.

---

### 6.1 Lease activation, reservation and the deposit (1 · 2 · 3 · 4 · 22)

**#1 — *"Activate leasing when the accounting notify that this unit/lease when money received.
Security deposit is valid for 'X' Days."***

*Meaning.* A signed deal must not go live until accounting has the money (the deposit, or the
cheques); and a reservation on a unit holds it for X days only — if nothing arrives, the unit goes
back on the market.

**Atriom today.**
- The wizard creates the lease **`active`** immediately (`LeaseCreationService`, `'status' => 'active'`
  — the model turns that into `future` when the commencement is ahead). The form offers
  `draft` / `pending_approval` / `active` in a **dropdown**, so anyone with `leases.edit` activates by
  picking a value; there is no act, no permission and no check (`LeaseForm`, the `status` Select).
- A `draft` or `pending_approval` lease already puts the unit in **`reserved`**
  (`Unit::recomputeStatus()`), and `pending_approval` deliberately does **not** hold the premises
  against another signer (`HasLeaseTermState::HOLDS_PREMISES`).
- Nothing expires a reservation; nothing compares what was received to what was agreed at activation.
  `Lease::depositHeld()` and the PDC register (`post_dated_cheques.lease_id`) hold the two facts the
  check needs.
- The approval ladder (`ApprovalRule::MODULES`) covers inventory draws, purchase requests and
  disbursements — not leases.

**Yardi / market.** Voyager's lease walks Prospect → Applicant → **Future** → **Current** *(cited,
[01 §2.1](../benchmarks/yardi/01-yardi-lease-administration.md))*; the move from "applicant" to
"future" is an **approval / execution step in a workflow**, not a cash test — the deposit is recorded
in the deposit register and reported against the requirement, and an operator who wants a money
gate configures it as their approval rule ***(verify)***. A unit **hold** with an expiry that releases
it automatically is a standard Voyager/RentCafe control on the residential side; on the commercial
side Deal Manager commits the space by deal stage ***(verify)***. In the Egyptian and GCC mall market
the sequence the client describes — reservation (حجز) with an expiry, then contract + deposit +
cheques, then activation — is the ordinary sales-office practice, and Ejari-linked leasing tools
gate activation on cheques received.

**Recommendation — BUILD, as a stated deviation (stricter than Yardi), switchable per property.**
*(Superseded on the day it shipped, 2026-09-11 — see the ✅ note below: keys are `billing.*`, the
default is `none`, the act is on the ROW, and the act moves out of `pending_approval` only.)*
- `pending_approval` becomes *"Reserved — awaiting activation"* in both languages (a label, not a
  vocabulary change; the set stays as it is).
- A new **Activate** act on the lease record (`leases.activate`, seeded to `accounting` and
  `manager`; the dropdown stops offering `active` — a status past the first one is the outcome of an
  act, the SW-238 rule). It refuses unless `PropertySettings('leasing.activation_requires')` is met:
  `none` · `deposit_received` (`depositHeld() ≥ security_deposit`) · `deposit_or_cheques` (…or Σ
  held/deposited PDCs on the lease ≥ the deposit). **Default `deposit_or_cheques`, because Eltizam
  asked for it; the setting is what keeps a mall that takes cash at signing on Yardi's behaviour.**
  `executedStatusFor()` then decides active vs future, as today.
- The wizard creates `pending_approval` when the property requires money, `active` otherwise.
- `leases.reserved_until` = creation + `leasing.reservation_valid_days` (property setting, **0 = no
  expiry**, so nothing moves on deploy). `leases:expire` — already the projection sweep — cancels a
  pending lease past its window with nothing received, which frees the unit through the existing
  projection, and notifies leasing three days before. A reservation fee (عربون) is a deposit
  **receipt** on the pending lease: it counts toward the deposit at activation and is refunded or
  forfeited on lapse through the two `DepositTransaction` types that already exist.
- Effort **M**. Doors: the wizard, the form, the importer (`LeaseImporter` writes a status), the
  API — `atriom:doors Lease` before the diff.

**✅ Shipped 2026-09-11 (1 · 2 · 3 together)**, with one change from the plan above on the owner's
instruction that configurability follows the market too (`/safe-change` §3b): **every default is
Yardi's** — `none`, 0 days, months of rent — and the client's rule is a setting they set per property
(`deposit_or_cheques` applied on staging; X days is theirs to state). The importer stays free to
write `active` (migrated history is not an act), and a renewal or holdover conversion does not
re-enter the gate (an executed lease already). Detail: [modules/04](../modules/04-leases.md).

**#2 — *"Create page for accountant to activate the lease when the invoice is received or cheques are
obtained."*** — **Build as a worklist, not a page**: a tab *Awaiting activation* on the leases list
(badge = count; a column showing deposit received / cheques lodged against the deposit due) and the
**Activate** act on the record page, plus a card on the accounting dashboard. The list finds, the
record acts — the same shape every other worklist here has. Effort **S**, rides on #1.

**#3 — *"Create field entered the value of the security deposit to be % or fixed amount."***

**Atriom today.** Already two ways: `security_deposit_months` (a multiple of the monthly rent — 3 is
300% of a month, 0.5 is 50%; re-derived whenever rent moves, `Lease::saving`) **or** a fixed
`security_deposit` (leave months blank). What it cannot say is *"10% of the annual rent"* — that is
1.2 months, and the field steps by 0.5.

**Yardi / market.** Voyager's deposit is a deposit-flagged **charge with an amount** (fixed), with the
requirement tracked against rent *(cited, [02 §6](../benchmarks/yardi/02-yardi-money-flow.md))*; MRI
the same. Egyptian leases write it either as *N months* or as *a % of the annual rent*.

**Recommendation — EXTEND the same field with a basis**: `months of rent` · `% of annual rent` ·
`fixed`, one derivation in `deriveDepositInto()` + `Lease::saving`. **Ask the client which base the
"%" is on** — annual rent is my reading and the common clause; if they mean % of the monthly rent,
the months field already is that. Effort **S**. *(Shipped 2026-09-11 as `% of annual rent`; if the
client meant a % of the monthly rent, the months basis already expresses it — 1.2 months = 10% of
annual — and the label can be revisited without a migration.)*

**#4 — *"Create pdf with the fields we got from the lease setups. hn5od se8a 3a2d mn Jawad."***

**Atriom today — BUILT.** `LeaseAgreementPdfService`: parties, units and area, the charge schedule
(what will actually bill, not the headline), clauses, options, signature blocks, a DRAFT watermark,
and the operator's standing wording per property (`lease.agreement_terms` at `/admin/document-wording`).
Download is on the lease page. **Yardi** merges lease data into Word templates (Document Management /
Smart Lease). **Recommendation — no code until Jawad's template arrives**; then transpose its
boilerplate into the wording block (the operator can), and adjust the template only where the layout
genuinely differs (**S**). A Word-merge engine is declined: one operator, one template, and the
wording block already carries the variable text. *The gap analysis §1 still reads "no generation" —
stale; corrected when this is filed.*

**#22 — *"Possession date is better described if left empty to be calculated from today."***

**Atriom today.** Helper text exists ("when the tenant took the keys… does not move any billing");
nothing computes it from anything. **Yardi** treats possession / move-in as a recorded date fact
*(cited, 01 §2.3)*. **Recommendation — ASK what "calculated from today" means** (default to today when
the handover is recorded? to the commencement date when blank?) and change the WORDING only: *"Leave
blank until the keys are handed over."* Effort **XS**.

---

### 6.2 The statement of account (5 · 6 · 8 · 9 · 10) — one change

**The notes.** *"Kashf 7asab: ekon agmale mst7qat lakom, w 3lekom"* · *"Lazm nbyn l security deposit,
aw ay haga gatlo 'gadia 7agz', deen w da2n w raseed"* · *"column fl ledger change charge le debit; w
3yzen l pdf yb2a b same l details w shel statement mtkonsh generalized, ybayn kol details"* ·
*"Description ybayn fatora eh l etdaf3t w etdaf3t ezay"* · *"Footer fl statement, valid for X."*

*Meaning.* The classic كشف حساب: a balance forward, then every movement in date order with
**Debit · Credit · Balance**, each line saying which invoice and how it was paid, the security deposit
(and any reservation money) shown, two totals — what the tenant owes us and what we hold for them —
and a footer stating how long the statement is valid.

**Atriom today.**
- On screen, the tenant **Ledger tab** already is that shape — Debit / Credit / running Balance,
  derived from the documents (`App\Support\TenantLedger`), and the *"Charge → Debit"* rename shipped
  on **2026-09-03** (`d52f984d`), the day after the meeting.
- The **PDF is a different document**: a summary strip (outstanding · overdue · billed · settled),
  then *open invoices*, *credits applied*, *payments*, *other settlements* and *recent invoices* as
  separate tables (`TenantStatementPdfService` → `tenants/statement.blade.php`). No balance forward,
  no running balance, and **the deposit HELD appears nowhere on it** — only a deposit *applied* at
  move-out is listed. Ledger rows are one per invoice with the period as the description; a payment
  row says the method and nothing about which invoice it settled.
- The footer is a fixed sentence (`admin.statement.footer`: "This statement is computer-generated").
- The same service renders the **portal** and **mobile-API** statement, so the tenant's copy changes
  with it (`MOBILE-API.md` note in the same commit).

**Yardi.** The Tenant Ledger is *Date · charge code · description · Charge · Payment · running
Balance* with a **Balance Forward**, one line per **charge code** (rent, service charge, VAT each on
their own line, the control number on each); the statement report prints the same detail under
*previous balance · charges · payments · balance due*; the deposit shows through its deposit charge
code and as *deposit on hand* *(cited, 02 §6)*. **Market.** Every AR system prints the running-balance
form; Egyptian statements add the validity line.

**Recommendation — BUILD, one seam: the PDF renders `TenantLedger`, so screen and paper cannot
disagree.**
- `TenantLedger` grows: a **balance forward** at the window's start (the rule `accountLedger()` uses
  for the GL); rows at **line grain** (invoice number as reference, `LineNarrative` + period as
  description, gross of that line's VAT — Yardi's charge-code grain, and it sums back to the invoice);
  payment rows naming **the invoices settled + the rail + the reference** (*"Bank transfer · ref 4471 ·
  for INV-VP-0007, INV-VP-0008"*); a **deposit account** memo section (receipts, refunds, forfeits,
  applications, and the balance held) that does NOT enter the AR running balance, because a deposit is
  a liability, not a receivable — printed, and labelled as held.
- Two totals: **due from you** = the ledger's closing balance (= `Tenant::outstandingBalance()`, the
  figure the screen and the AR report already agree on) and **due to you** = deposit held + unapplied
  credit notes + on-account credit (`Tenant::creditBalance()` + `Lease::depositHeld()`).
- Footer: a `DocumentText` block `statement.footer` with today's sentence as its floor — the operator
  types *"valid for 7 days"* per property; no new setting.
- Column headings Debit / Credit / Balance (مدين / دائن / الرصيد) on both surfaces, as the ledger tab
  already says.
- Effort **M**. The CAM statement and the owner statement are separate documents and untouched.

**✅ Shipped 2026-09-11.** As recommended, plus what the review of it found and closed: credit-note
rows now come from the APPLICATIONS table (a note raised with no `invoice_id` — every negative CAM
true-up — had no row and the ledger closed above the headline by its amount); a write-off is a
ledger row; same-day lines of one invoice stay together; the deposit account opens with a balance
forward and lists a billed-and-paid deposit; a statement bounded in the past dates its today's
figures; the money columns were widened after a seven-digit closing balance wrapped. Detail in
[modules/02](../modules/02-tenants.md#tenant-ledger-on-screen).

---

### 6.3 The trial balance (7 · 19)

**#7 — *"Debit - Credit - Eftat7e - 5etmya — 4 columns in the trial balance."***

**Atriom today — a correctness defect, not a preference.** `LedgerReportService::trialBalance()`
aggregates `journal_lines` **inside the selected window only** (`aggregate($assetIds, $from, $to)`),
and the page's window is the chosen month or the fiscal year. So the August trial balance shows the
bank account at **August's net movement**, not its balance; only the whole-history read (no `from`)
is a trial balance. The opening-balance rule already exists two methods down — `accountLedger()`
computes *"movement strictly before `from`"* for the general ledger — and the trial balance never
called it. Three renderers: screen, CSV (`ReportCsvExporter::trialBalance`), PDF.

**Yardi / market.** Voyager's Trial Balance prints **Beginning balance · Debits · Credits · Ending
balance** per account for a period; SAP (FAGLB03 / the trial-balance report) and Odoo (Initial ·
Debit · Credit · End) print the same four. The client is asking for the standard.

**Recommendation — BUILD.** Opening = everything before `from` (the `accountLedger()` rule, so the
two reports cannot disagree; a prior year not yet closed rolls into the opening exactly as it does in
SAP), period debit, period credit, closing; all four total and foot; the whole-year view opens at the
fiscal start. Effort **S–M**. **First in the order** — it is the one item that is wrong today.

**✅ Shipped 2026-09-11** as three debit/credit pairs (opening · movement · closing) on the screen,
the CSV and the landscape PDF; `balanced` requires all three to foot. The review found three faults
in the fix — nil-net history printing as six-dash rows, the unallocated notice still bounded to the
month, and the report computed eight times per render — all closed and pinned. Detail in
[modules/21](../modules/21-general-ledger.md#a-months-trial-balance-opens-with-the-balance-brought-forward-2026-09-11).

**#19 — *"The trail balance should be as tree, parent general account, w ynzl mnha ka tree hide and
show."***

**Atriom today.** A flat list ordered by code. The chart is five levels deep (`1 · 11 · 111 · 11101 ·
11101001`); the statements subtotal by the top group only (`StatementGroups`), and the trial balance
does not group at all.

**Yardi / market.** Voyager runs financial reports at an **account-tree level**; Odoo's trial balance
folds and unfolds by account group; SAP's financial-statement version is a tree. Standard.

**Recommendation — BUILD** `App\Support\LedgerTree`: leaf balances rolled up into every ancestor
through `parent_id` (already derived and self-healing, EG-28), rendered as a collapsible tree on
screen and indented with subtotals on the PDF and CSV; the balance sheet and P&L take it later on the
same helper. Effort **M**.

---

### 6.4 Fixed assets (11 · 12 · 13 · 14 · 15 · 17 · 18) — the accountant's slice — 11 · 13 · 14 · 15 (slice 1) · 18 ✅ shipped 2026-09-12

**#11 — *"Category fixed asset ton fl awl, raqm l 2asl shelhaaaa w htt3ml auto mn l category w mwgod
fl database. w nzwd description le fixed assets bel mola7zat."***

**Atriom today.** `tag` (the asset number) is **typed by hand**, required, unique per property;
`category` is **free text** with suggestions (`CategorySuggestions`, flagged in `ValueSets` as *"if it
is ever made a catalogue it becomes the seventh `IsCodeCatalogue`"*); `name` + `notes` exist. Field
order: name, tag, tax pool, category…

**Yardi / market.** SAP's **asset class** drives the number range (automatic), the account
determination and the depreciation defaults; Yardi Fixed Assets and Odoo's asset model carry the
same defaults; auto-numbering by class is universal.

**Recommendation — BUILD the catalogue**: `fixed_asset_categories` (the seventh `IsCodeCatalogue`)
carrying code · EN/AR label · **tag prefix** · default useful life (or rate) · default salvage (memo)
value · default tax pool · active. `tag` auto-allocated as `{prefix}-{0001}` under the document-number
lock (the `AllocatesPartyCode` idiom — and **kept when an import supplies one**, a migrating register
has its own numbers). Category first on the form. *Description:* `name` is the description and
`notes` the remarks — relabel; add a third field only if they confirm they need one. Effort **M**;
it is also the base for 13 and 14.

**Shipped 2026-09-12** as recommended, with three things worth stating. (a) The migration rows every
value the register already holds and **skips the shipped codes** — the seeder creates those with their
life, pool and prefix; a row the migration had created for `HVAC` on a box already holding HVAC assets
shipped the class LIFELESS on exactly the installs that have assets (the review caught it against the
staging register). (b) A kept tag in another numeric shape (`FUR-2026-0001`) is not a member of the
series — `(int) '2026-0001'` reads as 2026. (c) *Description:* left as `name` + `notes`, unchanged —
nobody has confirmed a third field is needed.

**#12 — *"Kelmt ehlak dareebi, 5le ehlak."*** and **#17 (second half) — *"60 shuhor, rate zahrt 25%
mfrod kant tb2a 20%."***

**Atriom today — the 25% is correct and the 20% is missing, which is the whole misunderstanding.**
`/admin/tax-depreciation` is **Law 91/2005 Art. 25**: statutory rates (buildings 5%, intangibles 10%,
computers 50%, everything else **25%** pooled diminishing-value) — a schedule for the corporate
return that posts nothing (`App\Support\TaxDepreciation`). A 60-month asset has a **book** rate of
20% (12 ÷ 60) — and no screen prints that number: book depreciation is the register's per-asset
entries tab, the monthly run and the register CSV, with the life shown in months only.

**Recommendation — DO NOT rename.** Egypt files single-book: the statutory accounts carry the book
figure and the tax figure is a computation attached to the return; one name for both would make the
accountant read 25% as the rate the books use. Instead: **show the book rate (%)** on the register and
the form (#14), add a plain **Depreciation schedule** (book) report if they want a screen called
*إهلاك* — the register CSV is already that schedule — and keep the tax page, whose subheading already
says *"for the return, not a second ledger"*. **Ask** whether they want the tax schedule hidden (it is
one module switch away) — it is what the corporate return is prepared from. Effort **S**.

**#13 — *"Qema t5rdia, yb2a '1' default; lw b2t 0 msh hatzhar."***

**Atriom today.** Salvage defaults to **0**. The claim that an asset at 0 disappears is **not true
here** — a fully-depreciated asset stays on the register (`FixedAssetsTable` has a *fully
depreciated* filter to find them). **SAP** depreciates to a **memo value** of 1 currency unit per
asset class precisely so the asset stays visible; the accountant's instinct is SAP's rule. Yardi and
Odoo default salvage to 0.

**Recommendation — BUILD** as the category's default memo value (#11), seeded **1.00**; the base is
cost − memo. Effort **XS** once the catalogue exists.

**Shipped 2026-09-12.** Proposed on the form when the class is picked and by the model for every
door with no form (the importer, a seeder); a figure stated — including an explicit 0 — wins, and
nothing re-reads the class once the asset exists.

**#14 — *"L 3omar entagy 3ala 7sb l category / 5leha % bdl shuhor / ttsgl daily msh shahren."*** and
**#17 (first half) — *"Trail balance, daily le ehlak."***

**Atriom today.** `useful_life_months` typed per asset; straight-line **monthly**; the acquisition
month is charged in **full** whatever the day (`DepreciationService::run()` — no proration); the run
posts on the 28th.

**Yardi / market.** SAP: a depreciation key per class expressed as a **percentage or a life**, with
**period control** (full period · mid-month · pro-rata by days); Odoo: duration + prorata (constant
periods or by days); Egyptian tax law states **rates as %**; every one of them **posts monthly** — a
trial balance run mid-month shows no current-month depreciation in any of them.

**Recommendation — BUILD three small things, decline one.** (a) the category default life (#11);
(b) the form accepts **either** an annual rate % or a life in months (reciprocal), **stores months**
(one truth), shows both; (c) **day proration of the first and last month** behind a setting
(`accounting.depreciation_proration` = `full_month` today · `days`); posting stays **monthly**.
**Declined:** a daily journal entry — thirty entries a month per asset, no benchmark system does it,
and the mid-month trial balance would still be what the accountant expects from every other system.
If they want the current month visible before month-end, the *Post this month* button already runs
it early. Effort **S**.

**Shipped 2026-09-12** — (a), (b) and (c); the daily entry declined as above. The proration is
`full_month` on a fresh install (what every install did — Yardi's and this system's behaviour) and
**`days` is what the client SETS** on `/admin/settings` → Fixed assets (skill §3b); the sizing is ONE
rule (`DepreciationService::chargeFor()`) read by the posting run and the tax page's book column, so
the book-vs-tax difference cannot disagree with the ledger. Two shipped defects the review found in
the build: a `maxValue(100)` on the derived rate refused every life under a year on create AND
locked its Edit page (Filament validates a non-dehydrated field), and the form's own `general`
tax-pool default meant the class's pool never reached the field.

**#15 — *"Mamwl mn: n5leha tre2t daf3, w meen mwrd — mwgod fl system wla ytktb esm gded."***

**Atriom today.** `funded_from` ∈ {`cash`, `bank`} decides the credit leg by **posting role** —
no bank account is named (`FixedAssetAcquisitionJournalizer` calls `MoneyAccount::for(null, …)`, so a
bank-funded asset lands in the generic `bank` role, the unattributed state SW-228 closed for
receipts), and no vendor at all.

**Yardi / market.** An asset is acquired **through AP** — a vendor bill line capitalised to the asset
(SAP F-90, Odoo "create asset from bill", Yardi via a capital GL account on the payable) — or by a
payment that names the bank.

**Recommendation — BUILD in two slices.** Slice 1 (**S**): `funded_from` reads the **outbound rail
catalogue** (`payment_methods`), the document joins `RecordsBankAccount` (the eighth bank-rail
document — the `MoneyDocumentDoors` gate then covers its form), and a `vendor_id` `EntitySelect` whose
create-option is the *"new name"* door — a real vendor row, never free text, because AP needs a
counterparty. Slice 2 (**M**, later): `funded_from = payable` raises a **draft vendor bill** (Dr asset /
Cr AP), the shape the market uses and the one `RecurringExpense` already follows for retainers.

**Slice 1 shipped 2026-09-12** as recommended — with four things the review found and the build
fixed. *"The gate then covers its form"* was FALSE as written: `MoneyDocumentDoors` grepped each
model for the literal line `use RecordsBankAccount;`, so a trait on a combined `use` line was
invisible — `PostDatedCheque` had been since 2026-09-02, and the asset would have joined it; it is
derived by reflection now (the asset is the NINTH document on the concern, not the eighth). The
shared bank field required an account on EDIT wherever the rail carries bank money, which locked
every pre-register `bank`/null asset out of a name-only save (and answering it was a re-post a
closed period refused) — the requirement stands down on such a row and is re-asked only where the
rail moves or a bank is already named. The same field filled the property's account from mount
beside a rail defaulting to `cash`, and `MoneyAccount` lets a named account win, so a purchase left
on the form's defaults credited the BANK (the expense form too) — a bank the rail does not carry is
not recorded. And the supplier "+" carried no gate: `accounting` holds `fixed_assets.create` and not
`vendors.create` and minted suppliers through it — every record-creating "+" in the panel now carries
its register's own right. Not built, deliberately: slice 2, and a `description` third field.

**#16 — *"Sndo2 3am / bank menf3sh ykon da2n, lazm ykon fe amount fl 7sab."*** *(treasury, listed with
the assets because it came up on "funded from")*

**Atriom today.** No guard on any outbound document — an expense, a supplier payment, a
disbursement, a custody grant or an asset purchase can take a cash or bank account below zero.

**Yardi / market.** Voyager does not block (a bank can be overdrawn). **SAP's cash journal refuses a
posting that would make the cash balance negative** — a hard error — while bank accounts may
overdraw; Odoo does not block. Egyptian practice: the خزينة is never credit.

**Recommendation — BUILD, split by account kind, and say the deviation.** For **cash** (the `cash`
posting-role account, per property): refuse an outbound document whose amount exceeds the balance as
at its date — SAP's rule, stricter than Yardi. One seam, `App\Support\CashBalanceGuard`, from the
wildcard `saving` listener over the outbound money documents (enumerate with `atriom:doors`: expense,
vendor-bill payment, disbursement, payroll paid from cash, advance grant, custody grant, deposit
refund, asset acquisition). For **bank**: a warning on the form and a health advisory; the hard
refusal behind a per-property setting (`treasury.refuse_overdrawn_bank`, **off**) — an overdraft
facility is legitimate, and refusing a real payment because its receipt was keyed an hour later is the
worse failure. Effort **M**.

**#18 — *"Na2l asl le fixed assets law hnwde mo3dat mn mkan le mkan."***

**Atriom today.** `asset_id` (the property) is **editable** on a live asset, and changing it
**re-homes the whole history** — every acquisition and depreciation entry is re-derived into the new
property's dimension (the `updated` hook in `FixedAsset::booted`), which restates months that may be
closed. The physical location inside a mall lives on the **facility twin** (`equipment.location`,
`equipment.fixed_asset_id`).

**Yardi / market.** SAP's intra-company transfer (ABUMN) posts cost and accumulated depreciation
**out** of the old cost centre and **in** to the new on the transfer date; history stays where it was.
Yardi Fixed Assets transfers between properties/entities the same way.

**Recommendation — BUILD a dated `Transfer` act**: reason required (the `ReversalReason` idiom),
transfer entries in both properties on the transfer date, future depreciation to the new property,
and `asset_id` **REFUSED** in `ChangeImpact` once posted so the edit door closes. A move *within* a
mall is `Equipment.location` — already there; the register should show it. Effort **M**.

**✅ Shipped 2026-09-12** — exactly that shape: `TransferFixedAssetService` (reason required; the
"All Properties" pseudo-asset, a property the actor does not hold, a disposed asset, the same
property, a future or closed-period date, the acquisition month, a month already depreciated here,
a date before an earlier transfer and a tag clash in the receiver all refused in words), two
`FixedAssetTransferLeg` GL sources (the 25th source — OUT: Cr Furniture / Dr Accumulated / Dr
`inter_property_clearing`; IN the mirror), `FixedAsset::propertyOn($date)` read by all three
fixed-asset journalizers so nothing already posted moves, `asset_id` REFUSED once depreciating or
disposed with the act's own write passing by shape, the *Transfer* act on the record page (it
follows the asset to its new property) and a read-only *Transfers* tab. **No new setting** — SAP
and Yardi configure nothing about the transfer's posting shape; the clearing account is a
posting-map row. Stricter than SAP in one place: a transfer may not be dated into a month already
depreciated in the old property (SAP re-dimensions that charge; here that is the restatement the
act exists to avoid). Within-mall location stays on `Equipment.location` — not touched. The
review added five rules the act needs (the figures the legs froze are locked once transferred;
every month before the transfer must be posted; a disposal cannot pre-date the transfer; a
property's scoped depreciation run posts the months it HELD the asset; the bank picker narrows to
the property the document answers for) — [modules/23 §2.11](../modules/23-fixed-assets.md) ·
CHANGE-IMPACT-PLAN §18.

---

### 6.5 Units and unit owners (20 · 21)

**#20 — *"Gross w net le msa7a le unit."***

**Atriom today.** One `area_sqm` per unit (with dated remeasurement history in `unit_areas`); the
property carries gross building area and leasable area. Every money rule — rate × area, the CAM
share, the occupancy figures — reads that one number.

**Yardi / market.** Voyager's space carries **rentable** and **usable** area (BOMA), with the load
factor between them; charges are on rentable *(cited, [09](../benchmarks/yardi/09-yardi-space-and-parking.md))*.

**Recommendation — BUILD `net_area_sqm` as a second, INFORMATIONAL measure**; the existing column is
relabelled *Gross (chargeable) area* and stays the only one any money rule reads; the load factor is
derived. Printed on the unit register, the lease agreement and the rent roll. **Ask** them to confirm
rent is priced on the gross figure in their contracts (my assumption). Effort **S**.

**#21 — *"3a2d edara da bekon ben unit owner and Jawad."***

**Atriom today.** The management arrangement is already a **row**: `unit_ownerships.management_mode`
(self-occupied · self-let · **operator-managed** · vacant), `management_fee_pct`, `fee_basis`
(collected / billed). The fee is **charged by nothing** — gap **B1**, blocked on *"which GL account
takes management-fee income — it is Eltizam's revenue, not the property's"* ([modules/37
§8](../modules/37-unit-owners.md), [gap analysis B1](../gap-analysis/README.md)).

**What the note changes.** If the management contract is between the unit owner and **Jawad** (the
property owner), the fee is the **property's** income — a revenue account in the property's own chart,
not an inter-company balance. That is half of B1 answered; the other half (a sinking fund, and its
liability account) stands. **Yardi** Investment Manager / Condo bill the management fee per the
management agreement on the owner's statement.

**Recommendation — a DECISION, then code.** Confirm with Jawad: (a) the fee is the property's revenue,
(b) the account it posts to. Then phase 5 of module 37 is buildable (**M**): the fee line on the unit
owner's statement, its posting, remittance net of it. If they also meant a generated *management
agreement* PDF from the ownership row (the `LeaseAgreementPdfService` idiom), that is **S** and needs
their template — **ask which they meant**.

---

### 6.6 Collections (23)

**#23 — *"If the tenant has late fees, notify Eltizam to stop electricity, water, etc. Think if there
is anything else."***

**Atriom today.** A late fee notifies the **tenant** (`LateFeeAppliedNotification`); overdue
reminders and a final notice go out on the dunning stages with operator-editable wording; the owner is
alerted on overdue. There is **no operations step** and no *services suspended* state on the tenant.

**Yardi / market.** Voyager's delinquency workflow is letters (first, second, final), a delinquency /
**legal** status on the tenant and *send to attorney* — **no utility cut-off**, which is an
operational remedy outside the AR system. Egyptian malls do suspend services after the final notice,
under the lease clause that allows it.

**Recommendation — BUILD a collections stage that asks a person, never one that cuts anything.** A
**derived** `collections_stage` on the tenant (current → reminded → final notice → suspension
requested → legal — derived from the dunning stamps and the late-fee count, never typed), a
per-property threshold (days overdue and/or number of live late fees), and when it is crossed a
**tenant request** of a new subcategory *service suspension* is raised to the **operations
department** (the routing that module 30 already gives a zone) for a human to confirm; when the
account settles (`recomputeTotals()` reaching paid) a *restore services* request is raised the same
way. **Anything else** — worth putting to them as a list, each a flag on the same stage: block
non-emergency work orders and new fit-out permits, hold parking / access cards, a banner in the tenant
portal, exclusion from renewal options, an alert to the owner above a threshold, a mark on the rent
roll. Cutting power has legal exposure in Egypt; the system records the **request** and the decision,
and a person turns the switch. Effort **M**.

---

### 6.7 Escalation (24) — ✅ shipped 2026-09-12

**#24 — *"The annual increase should be on all expenses not only on rent — better to be an option to be
a percentage or a fixed number."***

**Atriom before.** The clause was **lease-level** and applied to **base rent** (`fixed_percent` ·
`fixed_amount` · `cpi`, floor/ceiling, generates schedule rows — `RentEscalationService`); a
toggle stepped the service charge by the **same %**; the marketing levy follows rent because it is
a % of it; **parking, signage, storage and every other charge row never escalated**.

**Yardi.** The escalation schedule is held **per charge code** — method (% / amount / CPI / market
review), floor and ceiling, frequency — and it generates future rows *(cited, [01
§4](../benchmarks/yardi/01-yardi-lease-administration.md))*. The client asked for Yardi's grain.

**Shipped.** The rule is a term of each **charge row** — `charges.escalation_mode` (`follows_lease`
· `percent` · `fixed_amount` · `none`) with its own rate or amount, carried onto every successor
rung with the row's other terms; the service-charge toggle retired into it (the migration writes
`follows_lease` onto flagged leases' service rows, so nothing moved on deploy). The nightly sweep
steps every ruled charge on the lease anniversary by its own rule, the ladder is projected per
charge at signing, and each stepped charge writes its own timeline event. The lease form gained an
**Annual increase** tab (the clause, and a "Which charges step" table — one row per charge), the
schedule tab asks the rule on *Add charge*, the importer takes the three columns.
**Configurable the market's way**: `billing.new_charges_follow_escalation` per property proposes
every new charge as following the clause — **off by default (Yardi: a charge carries no escalation
until stated)**; the client's *"on all expenses"* is what they SET on their malls. **Deviation,
stated**: one anniversary and one interval per lease (Voyager allows a frequency per charge; no
Egyptian clause these malls sign needs it), and a follows-lease row under an AMOUNT clause steps
nothing (a pound step is a statement about the rent). The financial-terms tab was also split into
four sections in the same change — thirty-five fields in one grid was the operator's own
complaint.

**And the parking bays (2026-09-12, the operator's follow-up).** A bay, cage or signage face
steps on the same anniversary by ITS OWN rule, stored on its holding (Yardi: the escalation sits on
the item's charge); a lease can be created WITH its items — the standard form's *Parking & rentable
items* table and the quick wizard's third step; and the rule is set from the lease's TABS (the
schedule tab's and the items tab's *Annual increase* row actions) with the form's table refilling
from them — **and, the same day, from the form's own *Annual increase* tab**, where each bay is a
row beside the rent's and the service charge's (Voyager's one escalation screen per lease; the
create form's items table then carries the letting only). Module 35 has the rules and the two pre-existing defects the review found on the way
(a future-dated release billed nothing from the day it was recorded; a re-let bay was closed and
summed twice).

---

### 6.8 Additional activities (25)

**#25 — *"Coffee vending machine, children toy cars — can be treated like units — will be made later."***

**Atriom today — BUILT.** Module 35 `RentableItem` (parking · storage · signage · **kiosk**) attaches
to a lease with its own price, prorates mid-month, and is outside GLA — Yardi's own shape *(cited,
09 §2)*. A retailer adding a vending machine is a rentable item on their lease; an operator whose
whole business is the toy cars needs a **unit** (a kiosk / activity unit type), because a lease holds
a unit — again as Voyager does. **Recommendation — nothing now**, as they asked; when they return to
it the work is a unit type, **XS**.

---

### 6.9 What this section does not decide

- Which of the twenty-five to build is **Khaled's** call, point by point, then `/safe-change` each.
- Points **3** (the % base), **12** (hide the tax schedule?), **20** (rent on gross?), **21** (fee is
  the property's? a PDF too?) and **22** (what "from today" means) go back to the client as
  questions before any code — they are *meaning* questions, not behaviour questions.
- Every "stricter than Yardi" above (**1**, **16**) ships behind a per-property setting whose default
  is the client's rule, so the deviation is a configuration and not a fork.

---

*Keep current: when a backlog item ships, move its FR to ✅ and add a note. When an open
question is answered, fold the answer into the FR and the [client questionnaire](CLIENT-DISCOVERY-ANSWERS.md).*
