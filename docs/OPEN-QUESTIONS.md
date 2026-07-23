# Atriom — Open Questions (single hand-out)

> **What this is.** Every open question we need answered, in **one document**, grouped by **who can
> answer it** — so you can hand each section to the right person. It supersedes and merges the older
> scattered question docs (client-questions, the accountant & operations meeting agendas, the
> BUSINESS-RULES top-questions, the accounting walkthrough's questions, and the FRD open items).
>
> **Where answers go:** record them here in the **Answer** column, then fold the confirmed ones into
> [BUSINESS-RULES.md](BUSINESS-RULES.md) (the rule-by-rule detail) and
> [discovery/client-discovery-questionnaire.md](discovery/client-discovery-questionnaire.md) (the
> running record of what the client has already told us).
>
> _Consolidated: 2026-07-18._

## How to read this

| Marker | Meaning |
|---|---|
| 🔴 **Blocks go-live** | Wrong answer = wrong tax owed, wrong money billed, wrong books, or a compliance breach |
| 🟠 **Blocks a feature** | We stopped building and are waiting on the answer |
| 🟡 **Shapes a default** | Built and working; the answer changes a number or a presentation, not the code |

Each question states **what the system does today**, so silence is a decision too — if you don't
answer, that is what ships.

**Sections:** [A · Accountant / Finance](#a--accountant--finance) · [B · Owner (Jawad) &
ownership](#b--owner-jawad--ownership-structure) · [C · Eltizam operations](#c--eltizam-operations) ·
[D · ETA / tax registration & IT](#d--eta--tax-registration--it) · [E · Requirements we cannot build
as written](#e--requirements-we-cannot-build-as-written).

---

## A · Accountant / Finance

> 🇪🇬 **نسخة المحاسب ثنائية اللغة + خريطة الترحيل الكاملة** (كل حركة مالية على أنهي حساب مدين/دائن) في
> **[accounting/ACCOUNTANT-BRIEFING.md](accounting/ACCOUNTANT-BRIEFING.md)** — دي النسخة اللي تتسلّم في الاجتماع.
> The **bilingual accountant hand-out + the full GL posting map** (every movement → its exact debit/credit account)
> live in **[accounting/ACCOUNTANT-BRIEFING.md](accounting/ACCOUNTANT-BRIEFING.md)**. This file stays the internal
> master log where answers are recorded.

### A1 · Tax rules the billing engine computes from — 🔴 all block go-live

These are unverified assumptions. Plausible, consistent, never confirmed by anyone with authority.

| # | Question | What we do today | If different | | Answer |
|---|---|---|---|---|---|
| A1.1 | Is **14% the correct VAT rate** on service charges, utilities, parking — and is **base rent genuinely VAT-exempt**? | 14% on services; rent exempt | Every invoice has the wrong VAT | 🔴 | |
| A1.2 | Is **percentage rent VAT-exempt**? | 0% VAT | Every %-rent invoice under-charges VAT | 🔴 | |
| A1.3 | Are **CAM true-up charges VAT-exempt**? | 0% VAT (our assumption) | Every reconciliation invoice under-charges VAT | 🔴 | |
| A1.4 | Are **late fees VAT-exempt**? | 0% VAT (penalty outside VAT) | Every late fee under-charges VAT | 🔴 | |
| A1.5 | Is the **marketing levy 5% of base rent only**, accrued internally and **never shown on the tenant invoice**? | Yes, both | Levy mis-calculated, or tenants should see it | 🔴 | |
| A1.6 | Is **CAM allocated pro-rata by leased area (m²)**? Does the lease wording say "by area"? | Pro-rata by m² | Leases by turnover/fixed share are allocated wrong | 🔴 | |
| A1.7 | **Late-fee policy:** 2% of outstanding, min 50 EGP, charged **once** (not compounding), after a **7-day** grace? | Exactly that | None of these four numbers has a legal source — they are defaults | 🔴 | |
| A1.8 | **Security deposit = 3 months' rent** and **annual escalation = 7%** — the real contract defaults? | Both, baked into new leases | Every new lease starts wrong | 🔴 | |
| A1.9 | Is the **artificial-breakpoint** formula right for %-rent — `(sales − threshold) × rate`? | Yes; leases with no type set use it **silently** | If leases use the natural breakpoint, %-rent is wrong | 🔴 | |
| A1.10 | Is the **default payment term 7 days** from issue? | 7 days | Due dates + the whole overdue/late-fee chain shift | 🔴 | |

> **Verify once confirmed:** `php artisan billing:reconcile` independently re-derives receivables and
> prints control totals (invoiced / collected / credits / outstanding AR / VAT) to reconcile against
> your books.

### A2 · Taxes Atriom does not yet model — 🔴/🟠 surface before go-live

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| A2.1 | **Withholding tax (WHT):** do tenants withhold from rent (rate? WHT certificates)? Do **you** withhold when paying vendors/contractors (1%/3%/5% by service)? | Not modelled — a withheld payment looks like a shortfall in reconciliation | 🔴 | |
| A2.2 | **Stamp tax (رسم دمغة)** — on lease contracts and/or invoices? Who calculates & remits? | Not modelled | 🟠 | |
| A2.3 | **Real-estate / property tax (الضريبة العقارية)** — charged on units? Recharged to tenants or owner-borne? | Not modelled | 🟠 | |
| A2.4 | **e-Receipts** (B2C / cash) needed, separate from B2B e-invoices? | Not built — only e-invoice exists | 🟠 | |
| A2.5 | **VAT filing period** (monthly?) — need a **VAT output report** (by invoice) for the return? | Not built | 🟠 | |
| A2.6 | Any **tax-exempt tenants** (free zone, government, NGO, embassy)? How on invoices/ETA? | No per-tenant tax override | 🟠 | |
| A2.7 | Are invoices issued under **Eltizam's TRN or each owner's TRN**? | Single issuer identity | 🔴 | |

### A3 · General ledger, close & controls

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| A3.1 | Full **GL / chart of accounts here**, or accounting done elsewhere (export vs live integration, which software)? | Full double-entry GL exists (modules 21–29) | 🟡 | |
| A3.2 | **Accrual or cash basis**? Need **straight-line rent recognition** (spread rent-free/escalations)? | Accrual, revenue-at-issue; no straight-line spread | 🟠 | |
| A3.3 | How is **rent-in-advance** treated — deferred/unearned until earned? | Not modelled as deferred | 🟠 | |
| A3.4 | **Fiscal year** (Jan–Dec)? Do you **lock/close periods** to block back-dated edits? | Period close exists; closed periods refuse back-dated posts | 🟡 | |
| A3.5 | **Bank reconciliation inside the system**, or external? | ❌ **Not built** — the cash/bank GL balance is asserted by construction, never matched to a statement. The clearest treasury gap. | 🟠 | |
| A3.6 | **Bad debt / write-offs** — process + who approves? | Not modelled as a distinct workflow | 🟠 | |
| A3.7 | **Opening balances** (AR, cash, deposits, prepaid, payables) to load at go-live — as of what date? | Migration cutover — not defined | 🔴 | |
| A3.8 | Need **cost centres / segment reporting per property** (and per unit)? | Property scoping exists | 🟡 | |

### A4 · Chart of accounts (still waiting) — 🔴

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| A4.1 | Can **Mr. Ibrahim / Jawad provide the real coded chart of accounts**? Replace the seeded chart, or reconcile against it? (The file received earlier was a Saudi *contracting* template — zakat, no VAT — and was rejected.) | Running on a starter Egyptian chart | 🔴 | |
| A4.2 | Are the account names/codes right for Egyptian practice? | Starter names/codes | 🟡 | |

### A5 · Payroll — the one item that may mean the books are wrong today

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| A5.1 | **Are the employer's own social-insurance contribution AND accruing end-of-service gratuity captured anywhere** (even a manual monthly expense entry)? | ⚠️ Payroll records **only the amount withheld from the employee.** The employer share + gratuity are **not** recorded. If they're captured nowhere, the P&L understates labour cost and the balance sheet understates liabilities. **This answer — not an Odoo comparison — sets its priority.** | 🔴 | |
| A5.2 | Are the payroll **withholdings** (salary tax + social insurance) split the way you need? | Split into their own payable accounts | 🟡 | |
| A5.3 | Are **statutory amounts** (tax brackets / insurance rates) something the system should compute, or will they always be keyed per run? | Keyed per line, not rate-driven | 🟡 | |

### A6 · Fixed assets & depreciation

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| A6.1 | Do you need **Egyptian tax depreciation** (declining-balance pools per Law 91/2005 — ~25% general / 50% IT / 5% SL buildings, **confirm exact rates**) as a **second book** alongside straight-line? | ❌ Straight-line only — cannot produce tax-basis depreciation. May be computed separately by the accountant today. | 🟠 | |
| A6.2 | Do you need **fixed-asset depreciation run automatically**, and per-employee **payslips**? | Both exist (monthly depreciation cron + bilingual payslip PDFs) | 🟡 | |

### A7 · Deposits, cheques, advanced billing

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| A7.1 | Do tenants pay via **post-dated cheques (PDCs)** for the term up front? Track each cheque's status (pending/deposited/cleared/bounced)? Hold **security cheques** separately? | No cheque register — likely a **new module** | 🟠 | |
| A7.2 | Is the **security deposit** a pure liability (no VAT)? Refundable at exit minus deductions (unpaid rent, damages, restoration, cleaning)? | Liability, refundable; deposit ledger exists | 🟡 | |
| A7.3 | Do you track **prepaid rent / advances** separately from the deposit? | Not separated | 🟠 | |
| A7.4 | **Multi-currency:** any leases billed in **USD/EUR**, and how is FX handled? *(Q-F — also gates multi-currency treasury.)* | EGP only | 🟠 | |
| A7.5 | Discounts / concessions booked as **credit notes** with approval + audit trail? | Credit-note module + approvals exist | 🟡 | |

### A8 · Reporting & migration

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| A8.1 | Which **financial reports are must-have at go-live** (rent roll, AR aging, collection rate, owner statement, VAT output)? Per-property **and** consolidated? Who may see them? | Excel/PDF exports; property + consolidated views | 🟡 | |
| A8.2 | Your stakeholders use **Oracle / SAP / Odoo** — what **export format** do they need (Excel-compatible? Odoo-importable)? | Excel/PDF today | 🟡 | |
| A8.3 | What **financial history** must migrate and **how many years** (open AR, deposits, payment history, cheques, credits)? Current system of record — can you share **sample files**? | Migration scope undefined | 🔴 | |

### A9 · Account mapping & policies — added 2026-07-23 (tracked bilingually in [ACCOUNTANT-BRIEFING.md](accounting/ACCOUNTANT-BRIEFING.md) §4)

New questions surfaced while writing the posting map, so the accountant can re-point wrong accounts and confirm the policy-level treatments that decide *which* account a movement hits.

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| A9.1 | **Review the posting-role → account mapping** (briefing Part 2): does every role post to the right account in your chart? Every role is re-pointable per-role **and per-property** from the UI, no code change. | Starter mapping (`AccountMappingSeeder`) | 🔴 | |
| A9.2 | **Marketing levy (5%)** — book as **revenue** (today: `marketing_revenue` 41106001, billed as an invoice line) or as a **restricted marketing fund / liability** to be spent on marketing, not kept as profit? And is it shown on the tenant invoice? *(Refines A1.5 with the GL treatment.)* | Revenue, billed as a line | 🔴 | |
| A9.3 | **CAM** presented **gross** (recovery revenue + pooled expenses booked separately) or **net**? Confirm the GL treatment, not just the pool contents (C2.1). | Gross | 🟡 | |
| A9.4 | **Inventory valuation method** — FIFO / weighted-average / standard cost? | Per-movement unit cost | 🟡 | |
| A9.5 | Accrue **end-of-service & leave provisions monthly** (accounts 22201001 / 22201002 already exist in the chart)? | Not automated | 🟠 | |
| A9.6 | **Fixed-asset useful lives / depreciation rates per class** and **salvage value**? | Straight-line, per-asset params | 🟡 | |
| A9.7 | Separate **cash/bank account per mall**, or shared? Any specific **numbering series** for journals/invoices to match your books? | Shared; internal numbering | 🟡 | |
| A9.8 | Need a **WHT report (Form 41)** and a **salary-tax report** alongside the VAT-output report (A2.5)? | Not built | 🟠 | |

---

## B · Owner (Jawad) & ownership structure

> The biggest unbuilt area: how Eltizam earns and how owners get paid. Most of this is **deferred by
> decision** (no operator↔owner money flow is built), so these questions decide whether to build it.

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| B.1 | Is Eltizam the **manager operating the mall for the owner**, or the owner itself? | Operator model assumed | 🟠 | |
| B.2 | Single owner or **multiple co-owners** per property (ownership %)? Can it change mid-year? | `asset_owner` pivot with % + dates exists | 🟡 | |
| B.3 | On the %-split contract, are **"owner" and "Jawad" two different parties**, or the same owner? *(Q-G — affects the future owner-money data model.)* | Treated as one owner | 🟠 | |
| B.4 | How is **Eltizam compensated** — % of collected rent, % of gross, fixed fee, or a mix? On rent only or all charges? Before/after VAT? Is the fee **VATable** and invoiced to the owner? | No management-fee engine | 🟠 | |
| B.5 | Where do **tenant payments land** — Eltizam's account, the owner's, or a **trust/escrow per property**? | Not modelled | 🟠 | |
| B.6 | Does Eltizam **remit net funds to the owner** (how often), and what is **deducted first** (fee, paid expenses, CAM, taxes, reserve)? Need an **Owner Statement** (opening → collections → expenses → fee → net payout → closing)? | No disbursement/owner-statement module | 🟠 | |
| B.7 | Does Eltizam hold a **reserve/float per property** (starting amount + replenishment)? | Not tracked | 🟠 | |
| B.8 | Is each mall a **separate legal entity / set of books**, or all **consolidated** under Eltizam? Any **inter-company** transactions to record? | Single-company GL, property-dimensioned | 🟠 | |
| B.9 | Should the owner **see financial statements/disbursements** or stay **oversight-only** (current)? Does the owner **approve** anything before Eltizam acts (budgets, big expenses, new leases)? | Oversight + requests only | 🟠 | |

---

## C · Eltizam operations

### C1 · Leasing & tenant operations

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| C1.1 | **Unit types** (Shop, Kiosk, F&B, Office, Clinic, Service, Storage, ATM…) and **statuses** (Vacant, Occupied, Under Maintenance, Reserved)? Need a **visual floor plan / occupancy map**? | Free-form; no floor-plan view | 🟡 | |
| C1.2 | **Standard lease duration** + typical mix? Do leases **auto-renew**, and do charges + escalation carry over on renewal? | Renew/escalate actions exist | 🟡 | |
| C1.3 | **Annual escalation** rule — fixed % (7% default) or index-linked? | Fixed % via `escalate` | 🟡 | |
| C1.4 | **Early termination** — penalty (X months), notice period, deposit forfeited? | `terminate` action exists | 🟡 | |
| C1.5 | **Rent-free / grace / fit-out** periods at lease start? **One-off charges** (fit-out, key money, signage, parking, storage, fines)? | ✅ **Grace RESOLVED 2026-07-19:** `leases.fit_out_months` — a **FULL** grace (rent + service + CAM + marketing levy all suppressed) for that many whole months from the commencement month; billing starts after. Whole-month grace (no mid-month proration of the tail). *One-off charges still via ad-hoc Charge rows — separate item.* | 🟢 | |
| C1.6 | For **percentage rent**, how do tenants **report sales** (POS, manual monthly declaration, audited)? Do you **audit** them and what if under-reported? | Manual sales-declaration flow exists | 🟡 | |
| C1.7 | **Multiple contacts per tenant** (owner + accountant + ops — different access)? Track **tenant insurance certificates** + expiry? | Multi portal-user support; no insurance-cert tracking | 🟡 | |
| C1.8 | Need a **lease/contract PDF** + signature tracked in-system? | Not generated | 🟠 | |

### C2 · CAM, utilities & maintenance

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| C2.1 | What's in the **CAM expense pool** (security, cleaning, common power/water, M&E, insurance)? Are **vacant units carried by the owner**? Reconciliation frequency (annual)? | Pool + annual true-up exist | 🟡 | |
| C2.2 | Utilities recharged with a **markup** or **pass-through at cost**? Any **min/cap** on service charge or annual increase? | Pass-through; no cap | 🟡 | |
| C2.3 | **SLA targets** (e.g. urgent within 4 hrs) — confirm the numbers the breach scan alerts on. | SLA scan exists; targets configurable | 🟡 | |
| C2.4 | **SLA penalties (FR-CM-08):** treated as a **cost reduction** (Dr AP / Cr the expense, no VAT) — right, or **other income**? Those costs flow into CAM tenants reimburse, so a penalty's saving reaches tenants automatically — **is that intended**, or should the mall keep it? | Cost reduction, benefit reaches tenants | 🟠 | |
| C2.5 | **Recharge tenant-caused repairs at all?** If yes: VATable or cost recovery? Parts only or parts+labour+vendor invoice? What if the cost changes after? Can the tenant dispute (→ credit note or void)? | ❌ We record responsibility but **cannot bill a tenant for a repair** — the FRD never asks us to | 🟠 | |
| C2.6 | **Approval bands (FR-CM-11):** are **1,000 / 10,000 EGP** the right thresholds (supervisor / manager / senior)? Does a large spend need **more than one** approver (a chain, not a level lookup)? | These bands; single-level lookup | 🟡 | |
| C2.7 | **Externally-bought part (FR-CM-09):** must a **vendor bill** back it before the job can close, or is the work-order record a memo with accounting entering the bill independently? | Recorded on the WO; posts nothing; nothing requires a bill | 🟠 | |

### C3 · Inventory / procurement / workflows

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| C3.1 | **Multi-location warehousing** — multiple warehouses per mall? **Bins/shelves** within a warehouse? | A warehouse is the finest grain | 🟠 | |
| C3.2 | **Inter-mall stock transfers** in scope? (Note: transfer types exist as scaffolding but **nothing creates them** — looks shipped, isn't. They also move value between two properties' books.) | Not built | 🟠 | |
| C3.3 | What is the **3rd warehouse/inventory category**? (Only "daily consumables" and "main / spare parts + machines" are named. *Q-C*) | Two categories | 🟠 | |
| C3.4 | **Low-stock alerts** wanted at all, and is **one reorder level per item** enough (vs per-property)? | Daily bell alert, per mall, one number per item | 🟡 | |
| C3.5 | Does **procurement approval follow the same price bands** as spare parts? Confirm the FRD's own open item. | Defaulted to identical bands | 🟡 | |
| C3.6 | Exact **approval chain for department requests/payments routed through Accounting**? *(Q-E)* | Not defined | 🟠 | |
| C3.7 | **"Personal accounts" (محسوبات شخصية)** — who exactly (staff? related parties?) and what for? *(Q-B)* | Not built | 🟠 | |
| C3.8 | For each **service**: billed out (chargeable) or absorbed as a unit expense? Confirm the annual-report format. *(Q-D)* | Not distinguished | 🟠 | |
| C3.9 | Which actions need **approval before they take effect** (new lease, discount, write-off, refund, large expense, invoice cancellation/credit note)? Who holds **delete/void/cancel** authority — only super-admin? | Approval ladder for procurement/parts; delete = super-admin only | 🟡 | |

### C4 · Notifications, go-live & training

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| C4.1 | What events trigger **tenant** notifications and **staff** alerts? **Reminder** cadence (days before due / overdue intervals)? Need **WhatsApp / SMS** + branded email templates? | Email + push (FCM built, not live); no WhatsApp/SMS | 🟡 | |
| C4.2 | **Target go-live date**, parallel-run period, and the **client-side data-validation owner**? | Undefined | 🔴 | |
| C4.3 | **Training** (on-site / remote / video) and for which roles? | Undefined | 🟡 | |

---

## D · ETA / tax registration & IT

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| D.1 | **ETA e-invoicing go-live:** provide live `ETA_CLIENT_ID/SECRET`, the **CAdES signing certificate** (HSM/USB token), registered **activity code (6820?)** and real **EGS/GS1 item codes** — all placeholders today. Submit **real-time or batched**? | ⚠️ Runs in **MOCK mode** (`ETA_MOCK=true`). Nothing has ever reached the real tax authority; signing is a no-op passthrough. **Not certified.** | 🔴 | |
| D.2 | **Paymob** card payments — activate now or later? (Built, currently off.) | Off | 🟡 | |
| D.3 | **ETA receiver address per tenant** — tenants have only a free-form `address`; real invoices need governorate/city fields (hardcoded to Giza / 6 October today). | Hardcoded buyer address | 🟠 | |
| D.4 | **Hosting** — cloud SaaS (we manage) or on-prem? Do they have an **IT team**? **Backup/DR** expectations? Need **2FA**? Account lifecycle when someone leaves (deactivate vs delete)? | Cloud-ready; 2FA not enabled | 🟡 | |

---

## E · Requirements we cannot build as written

Each needs **one clarifying sentence** before we can build it.

| # | Question | Why we stopped | | Answer |
|---|---|---|---|---|
| E.1 | **FR-REQ-01 "delegation (from/to)"** — what does it mean? | No such concept exists anywhere in the system or the rest of the FRD | 🟠 | |
| E.2 | **FR-PPM-01 "Fixed maintenance"** — one-time, or periodic-per-asset? | The FRD says **both**, in different sentences; we support periodic | 🟠 | |
| E.3 | **FR-USR-01 "Admin (per mall) — full access"** — does "full access" include **deleting records**? | Deletion is super-admin-only by design; confirm you don't mean to change that | 🟡 | |
| E.4 | **FR-USR-06** — must completing a **work order** require a **photo** (all WOs, only technician-completed corrective, or none)? | Tenant-request evidence shipped; the WO-photo side is deferred | 🟠 | |
| E.5 | **FR-PROC-05 status history** — is who/when/from→to enough, or do you need **per-step comments and attachments**? | We record who/when/from→to; comments/attachments are a bigger build | 🟡 | |

---

## What happens if you answer nothing

- Everything **🟡** ships as described — those are working defaults.
- Everything **🟠** stays unbuilt (notably: no tenant-repair recharge, no owner money-flow, no cheque register, no bank reconciliation).
- Everything **🔴** is the risk: Section A1 are the numbers the billing engine computes from, and most are unconfirmed assumptions; **A5.1 (employer social insurance/gratuity)** may mean the books are wrong today; **D.1 (ETA)** blocks legal e-invoicing. Sign these off before the first real invoice.

## Sign-off

| Section | Owner | Date |
|---|---|---|
| A (finance / tax / GL / payroll / assets) | *accountant* | |
| B (owner money-flow) | *owner (Jawad) + Eltizam finance* | |
| C (operations) | *Eltizam operations lead* | |
| D (ETA / IT) | *Eltizam IT + tax registrant* | |
| E (requirement clarifications) | *Eltizam operations lead* | |

**Related:** [BUSINESS-RULES.md](BUSINESS-RULES.md) (every rule + risk level) ·
[discovery/client-discovery-questionnaire.md](discovery/client-discovery-questionnaire.md) (answers
already collected).
