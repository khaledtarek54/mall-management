# Atriom — 🏢 Eltizam (Operations) Meeting · Discovery Questions

> **Meeting 2 of 2** (the other is the [Accountant meeting](accountant-meeting-questions.md)).
> Focus: **operating model, leasing, maintenance, workflows, go-live.** Collected answers so far
> live in [client-discovery-questionnaire.md → Part 1](client-discovery-questionnaire.md).
> Fill the **Answer** column live. "Maps to" ties each question to an Atriom module / design decision.
>
> _Last updated: 2026-06-29._

## What we already know (relevant to this meeting)
- ~60–300 leasable units; **3 floors**; mix of **Commercial / Offices / Clinics**.
- One tenant **can lease multiple units**; units **can be split/merged**.
- Users: **Val = 4 (viewer/auditor)**, **Eltizam = 30–40 (editor, no delete)**; new users created **by admin**.
- Tenants get a **mobile app + portal** (bilingual): view/download invoices, pay online, submit & track maintenance, full statement, push notifications.
- **Delete is super_admin-only**; **bulk-delete is off** project-wide (Atriom policy).
- Owner model today = **oversight + requests only** (no separate owner portal; owners are scoped admin users).

---

## A · Operating Model & Owner Relationship

> The operational side of the operator↔owner question (the money mechanics are in the accountant meeting).

| # | Question | Why it matters / maps to | Answer |
|---|----------|--------------------------|--------|
| 1 | _(confirm in both)_ Is Eltizam the **manager operating Val Plaza for the owner**, or the owner itself? | Foundational to the whole model | |
| 2 | _(confirm in both)_ Single owner or **multiple co-owners** per property? Does ownership change over time? | App has owner↔asset links with % + dates | |
| 3 | Who physically **collects rent and chases tenants** — Eltizam staff, or the owner? | Defines who operates collections | |
| 4 | Should the **owner see financial statements/disbursements**, or stay **oversight-only** (current behavior)? | Scopes the owner surface | |
| 5 | Does the owner **approve** anything before Eltizam acts? (Budgets, big expenses, new leases) | Owner-approval workflow | |
| 6 | How is work **divided between Val and Eltizam staff** day-to-day? (Val = audit, Eltizam = operate?) | Confirms the role split already configured | |

## B · Properties, Units & Tenant Operations

> Fills the open items in Part 1 §03 / §04.

| # | Question | Why it matters / maps to | Answer |
|---|----------|--------------------------|--------|
| 1 | What **unit types** exist? (Shop, Kiosk, F&B, Office, Clinic, Service, Storage, ATM…) | Unit classification | |
| 2 | Need a **visual floor plan / occupancy map** (vacant vs occupied)? | Possible new feature | |
| 3 | What do you store **per unit**? (Area sqm, floor, number, photos, CAD drawings) | Unit data model | |
| 4 | What **unit statuses** do you use? (Vacant, Occupied, Under Maintenance, Reserved) | Status lifecycle | |
| 5 | Is rent **per sqm or a fixed monthly amount** per unit? | Pricing model | |
| 6 | Is there a **tenant onboarding checklist / approval** before activating a lease? | Onboarding workflow | |
| 7 | **Multiple contacts per tenant**? (Owner + accountant + ops manager — different access?) | Tenant contacts / portal users | |
| 8 | How do you **communicate with tenants** today? (Email, phone, WhatsApp, portal) | Drives notification channels | |

## C · Lease Operations

> The operational lease decisions (financial math is in the accountant meeting §E).

| # | Question | Why it matters / maps to | Answer |
|---|----------|--------------------------|--------|
| 1 | **Standard lease duration**? (1 / 3 / 5 yr) Typical mix? | Lease defaults | |
| 2 | For **percentage rent**, how do tenants **report sales** — POS integration, manual monthly declaration, audited statements? | Portal plans a sales-declaration flow | |
| 3 | Do you **audit/verify** declared sales? What if a tenant **under-reports**? | Compliance + dispute path | |
| 4 | Do leases **auto-renew**, or admin action each time? On renewal, do **charges carry over** + **escalation apply**? | Renew action behavior | |
| 5 | Do leases have **annual rent escalation** (e.g., +10%/yr)? Fixed % or index-linked? | App has an `escalate` action — confirm rule | |
| 6 | **Early termination** — penalty (e.g., X months' rent), **notice period**, is the **deposit forfeited**? | Terminate action behavior | |
| 7 | Can a lease cover **multiple units** with **different rent/charges per unit**? (Master-unit model.) | App supports multi-unit leases — confirm | |
| 8 | Need a **lease/contract PDF** + (digital or physical) **signature** tracked in-system? | Document generation | |
| 9 | _(confirm in both)_ Do tenants pay via **post-dated cheques (PDCs)** for the term up front? | Likely a new cheque-register module | |
| 10 | When a **cheque bounces** — penalty fee, status flip, tenant notification, legal flag? | Cheque ops + late fees | |
| 11 | Do you hold **security cheques** separately from payment cheques? | Off-ledger guarantee tracking | |
| 12 | _(confirm in both)_ **Security deposit** — how many months? Refundable minus deductions? What **deductions at exit**? (Unpaid rent, damages, restoration, cleaning) | Deposit operations | |
| 13 | Do you offer **rent-free / grace / fit-out periods** at lease start? | Lease/billing proration behavior | |
| 14 | Do you bill **one-off charges**? (Fit-out, key money, signage, parking, storage, fines) | Ad-hoc charge types | |

## D · CAM / Service Charge & Utilities Operations

| # | Question | Why it matters / maps to | Answer |
|---|----------|--------------------------|--------|
| 1 | Do you charge tenants for **CAM / service charge**? What's in the **expense pool**? (Security, cleaning, common power/water, M&E, insurance) | Expense-pool definition | |
| 2 | How is CAM **allocated** — pro-rata by leased area, custom weighting, or fixed? Are **vacant units carried by the owner**? | Allocation method (app does pro-rata by area) | |
| 3 | What's the **current reconciliation process** (spreadsheets)? How often? | Migration + true-up flow | |
| 4 | Are **utilities sub-metered per unit**? (Electricity, water, gas) | Meter module | |
| 5 | **Who reads the meters**, how often, and is a **reading photo** required? | Meter-reading ops (note: `meter_readings.cost` quirk) | |

## E · Maintenance, Vendors, Workflows & Approvals

| # | Question | Why it matters / maps to | Answer |
|---|----------|--------------------------|--------|
| 1 | Who submits maintenance requests — **tenants, staff, or both**? | Maintenance intake (tenants via portal/app) | |
| 2 | What **categories** + **priority levels** do you use? (HVAC/electrical/plumbing/structural · low→urgent) | Work-order taxonomy | |
| 3 | Do you have **SLAs** (e.g., urgent within 4 hrs) and **alert management on breach**? | SLA scan command exists — confirm targets | |
| 4 | Do you assign requests to **specific staff/teams** (departments)? | Department routing | |
| 5 | Do you track **preventive/scheduled maintenance** (elevator, HVAC, fire systems), not just reactive? | Maintenance extension | |
| 6 | Do you use **external contractors/vendors**? Track **vendor cost per work order** and **recharge** to tenant / CAM / owner? | Links maintenance ↔ billing ↔ CAM | |
| 7 | Do vendors **sign contracts** with start/end dates, and need **expiry alerts**? Critical now or later? | Vendor-contract module (expiry scan exists) | |
| 8 | Which actions need **approval before they take effect**? (New lease, discount, write-off, refund, large expense, invoice cancellation/credit note) | Approval-workflow scope (not built) | |
| 9 | Given Eltizam = "editor, no delete," who holds **delete/void/cancel authority** — only Val super-admin? | Confirms RBAC delete policy | |
| 10 | Do you track **tenant insurance certificates** and **expiry**? Manage **parking** (allocations / paid visitor)? Run a **fit-out/handover** process (snag list, NOC)? | Possible new modules | |

## F · Reporting, Notifications & Go-Live

| # | Question | Why it matters / maps to | Answer |
|---|----------|--------------------------|--------|
| 1 | Top **dashboard KPIs** for management? (Occupancy %, collection rate, AR aging, expiring leases, revenue vs target) | Dashboard build | |
| 2 | Need a **lease-expiry calendar** + **tenant-performance report** (on-time payment, sales trends)? | Reporting features | |
| 3 | Reports needed **per-property and consolidated**? Exportable to **Excel/PDF** + **scheduled by email**? | Reporting scope | |
| 4 | What **events trigger tenant notifications**? (Invoice issued, payment received, maintenance update) — and **staff alerts**? (Lease expiring, SLA breach, overdue) | Notification matrix | |
| 5 | **Reminders** — before due date (how many days?) and overdue (how many / intervals?). | Reminder scheduling | |
| 6 | Need **WhatsApp** and/or **SMS**, plus **branded email templates** with the logo? | Channel integrations | |
| 7 | **Current system of record** (Excel / other software / paper)? Can you share **sample files**? | Migration mapping | |
| 8 | What **operational data** must migrate, and **how many years**? (Tenants, active leases, units, maintenance history) | Migration scope | |
| 9 | **Target go-live date**, and do you want a **parallel-run** period? Who is the **client-side data-validation owner**? | Cutover plan | |
| 10 | **Training** before go-live — on-site, remote, or video guides? Which roles? | Enablement plan | |
| 11 | **Hosting** — cloud SaaS (we manage) or on-prem? Do they have an **IT team**? **Backup/DR** expectations? | Infra decision | |
| 12 | Need **2FA**, and what happens to a user account when someone **leaves** (deactivate vs delete)? | Security / account lifecycle | |

---

### ⭐ Don't leave without
- The **operating model & owner relationship** (§A) — who collects, who approves, what owners see.
- **Lease operations** (§C) — escalation rule, percentage-rent sales reporting, termination penalties, **post-dated cheques** & deposit terms.
