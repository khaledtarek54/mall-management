# Atriom — 🧮 Accountant Meeting · Discovery Questions

> **Meeting 1 of 2** (the other is the [Eltizam / operations meeting](eltizam-meeting-questions.md)).
> Focus: **money, tax, accounting.** Collected answers so far live in
> [client-discovery-questionnaire.md → Part 1](client-discovery-questionnaire.md).
> Fill the **Answer** column live. "Maps to" ties each question to an Atriom module / design decision.
>
> _Last updated: 2026-06-29._

## What we already know (relevant to this meeting)
- Bilingual (AR/EN); currency assumed **EGP** (confirm).
- ~60–300 leasable units; mix of **Commercial / Offices / Clinics**.
- **Encoded financial rules to validate:** VAT **14% on service charges**, **base rent VAT-exempt**, **5% marketing levy** on base rent, late fees, percentage rent. (See `docs/BUSINESS-RULES.md`.)
- **ETA e-invoicing** is built but running in **MOCK mode** — not yet certified/live. **Paymob** card payments available but currently off.
- **AR is the source of truth** (invoices → payments + credit notes → balance). There is **no full double-entry GL** today.
- **Owner model today = oversight + requests only**; there is **no operator→owner money flow** (management fees, rent remittance, owner statements) built yet.

---

## A · Operator ↔ Owner Money Flow ⭐ HIGHEST PRIORITY

> The biggest gap: how does Eltizam (operator) earn and how do owners get paid? If Eltizam takes a
> fee and remits net rent to owners, this is a **new module** (fees, disbursements, owner statements).

| # | Question | Why it matters / maps to | Answer |
|---|----------|--------------------------|--------|
| 1 | _(confirm in both)_ Legal relationship — is Eltizam the **manager operating Val Plaza for the owner**, or the owner? | Decides whether operator↔owner accounting is needed at all | |
| 2 | _(confirm in both)_ Single owner or **multiple co-owners** per property (with ownership %)? Can it change mid-year? | App has `asset_owner` pivot w/ `ownership_percentage` + dates | |
| 3 | How is **Eltizam compensated** — % of collected rent, % of gross revenue, fixed monthly fee, or a mix? | Drives a management-fee engine (not built) | |
| 4 | Is the fee on **rent only or all charges** (service/CAM/utilities)? Computed **before or after VAT**? | Fee-base definition | |
| 5 | Is the **management fee subject to 14% VAT**? Does Eltizam **invoice the owner** for it? | New owner-billing flow + ETA implications | |
| 6 | Where do **tenant payments land** — Eltizam's account, the owner's, or a **trust/escrow account per property**? | Trust accounting / fund segregation | |
| 7 | Does Eltizam **remit (disburse) net funds to the owner**, and how often (monthly)? | New owner-disbursement module | |
| 8 | What is **deducted before remitting**? (Mgmt fee, paid expenses, CAM, taxes withheld, reserve top-up) | Defines the owner-statement waterfall | |
| 9 | Need an **Owner Statement** per period: opening → collections → expenses → fee → net payout → closing? | The key new owner deliverable | |
| 10 | Does Eltizam hold a **reserve/float per property**? Starting amount + replenishment rule? | Reserve-fund tracking | |
| 11 | Are operating expenses **paid by Eltizam and recharged to the owner**, or **paid directly by the owner**? | Expense recharge vs pass-through accounting | |
| 12 | Is each mall a **separate legal entity / set of books**, or all **consolidated** under Eltizam? | Multi-company vs single-company GL | |
| 13 | Any **inter-company transactions** between Eltizam and owner entities to record? | Multi-entity bookkeeping | |

## B · Accounting, General Ledger & Period Close

> Atriom keeps **AR**, not a full GL. Confirm whether accounting lives here or in external software,
> and how revenue is recognized for Egyptian-standard books.

| # | Question | Why it matters / maps to | Answer |
|---|----------|--------------------------|--------|
| 1 | Full **general ledger / chart of accounts** in this system, or accounting done elsewhere? | GL build vs export-only | |
| 2 | If elsewhere — **which software**, and do you want an **export** (journal entries) or **live integration**? | Integration scope | |
| 3 | Account on an **accrual or cash basis**? | Revenue-recognition timing | |
| 4 | Need **straight-line rent recognition** (spread rent-free / escalations over the term)? | Invoices vs recognized revenue | |
| 5 | How is **rent received in advance** treated — deferred/unearned income until earned? | Prepaid-rent handling | |
| 6 | How are **security deposits** accounted — liability until refunded? Do they accrue interest? | Deposit ledger (see §D) | |
| 7 | Need **cost centers / segment reporting per property** (and per unit)? | Maps to existing property scoping | |
| 8 | What is your **fiscal year** (Jan–Dec)? Do you **lock/close periods** to block back-dated edits? | Period-close controls — not built | |
| 9 | Need **bank reconciliation** inside the system, or done externally? | Cash-side reconciliation | |
| 10 | How do you handle **bad debt / write-offs**? Who approves? | Write-off workflow (affects AR recompute) | |
| 11 | Record **petty cash + operating expenses** here, or only revenue/AR? | Expense-ledger scope | |
| 12 | Need **opening balances** (AR, deposits, prepaid, credits) loaded at go-live? As of what date? | Migration cutover | |

## C · Egyptian Tax & Compliance — VAT, ETA, WHT, Stamp, Property Tax ⭐

> Validate the encoded tax rules and surface the taxes Atriom doesn't model yet.

| # | Question | Why it matters / maps to | Answer |
|---|----------|--------------------------|--------|
| 1 | Confirm **VAT per charge type**: base rent (exempt?), service/CAM (14%?), utilities (?%), parking (?%), marketing levy (?), mgmt fee (14%?). | Validates `BUSINESS-RULES.md` | |
| 2 | Is the **5% marketing levy** a tax, a contractual charge, or a fund? Is it itself **subject to VAT**? | Marketing module captures it per-charge | |
| 3 | Are all properties / the operator **VAT-registered**? **One VAT number or several** (per entity)? | ETA issuer identity + per-property invoicing | |
| 4 | **ETA:** already **live** on the portal? Have the **digital signature (HSM/USB token)** + registered **activity & item (EGS/GS1) codes**? | Switches ETA mock→live; current EGS codes are placeholders | |
| 5 | Submit each invoice to ETA **real-time or batched**? Store the returned **UUID** on the invoice? | Already supported — confirm operating model | |
| 6 | Also need **e-Receipts** (B2C / cash), separate from B2B e-invoices? | Not built — only e-invoice exists | |
| 7 | **Withholding tax (WHT):** do tenants **withhold from rent**? Rate? Track/reconcile **WHT certificates**? | A withheld payment ≠ a shortfall — affects reconciliation | |
| 8 | Do **you withhold tax when paying vendors/contractors**? Rates by service type (1%/3%/5%)? | Vendor-payment tax | |
| 9 | **Stamp tax (rasm damgha):** on lease contracts and/or invoices? Who calculates & remits? | Not modeled | |
| 10 | **Real-estate / property tax (الضريبة العقارية):** charged on units? **Recharged to tenants** or owner-borne? | New cost line + possible recharge | |
| 11 | **VAT filing period** (monthly?), and need a **VAT output report** (by invoice) for the return? | New report off invoice data | |
| 12 | Any **tax-exempt tenants** (free zone, government, NGO, embassy)? How handled on invoices/ETA? | Per-tenant tax overrides | |
| 13 | Are invoices issued under **Eltizam's TRN or each owner's TRN**? | Determines the ETA issuer per invoice | |

## D · Deposits, Cheques & Advanced Billing (accounting view)

> Post-dated cheques (PDCs) dominate Egyptian commercial leasing; Atriom has no cheque register yet.

| # | Question | Why it matters / maps to | Answer |
|---|----------|--------------------------|--------|
| 1 | _(confirm in both)_ Do tenants pay via **post-dated cheques (PDCs)** for the term up front? | Likely a **new cheque-register module** | |
| 2 | If yes — track each cheque's **status** (pending/deposited/cleared/bounced) for treasury/cash? | Cheque lifecycle / cash forecasting | |
| 3 | _(confirm in both)_ **Security deposit**: how many months? Refundable at lease end **minus deductions**? | Deposit accounting (§B.6) | |
| 4 | Is the **security deposit subject to VAT**, or a pure liability? | Deposit tax treatment | |
| 5 | Do you grant **discounts / concessions**? Booked as **credit notes**? Approval + audit trail? | Credit-note module + approvals | |
| 6 | **Multi-currency:** any leases billed in **USD/EUR**? How is **FX** handled on invoice + payment? | App is EGP-centric today | |
| 7 | Do you track **prepaid rent / advances** separately from the deposit? | Unearned-income handling | |

## E · Lease & CAM Financial Rules

> The decisions that change billing math (the operational side is in the Eltizam meeting).

| # | Question | Why it matters / maps to | Answer |
|---|----------|--------------------------|--------|
| 1 | **Percentage rent** structure — **"higher of base or %"**, or **base + % above a breakpoint**? Breakpoint formula? | Defines the percentage-rent engine | |
| 2 | Percentage-rent **true-up cadence** — monthly, quarterly, annual? | Billing cadence | |
| 3 | Is the **service charge a fixed rate per sqm**, or **budgeted-then-reconciled** vs actuals? | CAM estimate vs true-up model | |
| 4 | CAM **reconciliation frequency** (annual?), and do you issue a **balancing charge or credit** per tenant? | Year-end true-up flow | |
| 5 | Utilities recharged with a **markup**, or **pass-through at cost**? | Meter-reading → invoice line | |
| 6 | Any **min/cap on the service charge** or a **cap on annual increase**? | Billing guardrails | |
| 7 | **Late fees** — confirm rate, grace period, and whether they're VAT-able. | Validates encoded late-fee rule | |

## F · Financial Reporting & Migration of Balances

| # | Question | Why it matters / maps to | Answer |
|---|----------|--------------------------|--------|
| 1 | Which **financial reports are must-have at go-live**? (Rent roll, AR aging, collection rate, owner statement, VAT output) | Report prioritization | |
| 2 | Need both **per-property and consolidated** financial views? | Multi-property reporting | |
| 3 | Who may see **financial reports** — all admins, finance only, owners (own property)? | Report-level RBAC | |
| 4 | Reports exportable to **Excel + PDF**, and **scheduled by email**? | Export/automation scope | |
| 5 | What **financial history** must migrate — and **how many years**? (Open AR, deposits, payment history, cheques, credits) | Migration scope (balance cutover) | |
| 6 | Current **system of record** for finance (Excel / accounting software / paper)? Can you share **sample files**? | Migration mapping | |

---

### ⭐ Don't leave without
- The **operator↔owner money flow** (§A) — fee structure, where money sits, disbursement + owner statement.
- The **real tax rules + ETA go-live status** (§C) — VAT per charge, WHT, stamp, property tax, ETA certification.
