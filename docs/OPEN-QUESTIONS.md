# Atriom — Open Questions (the single list)

> **Everything still waiting on an answer, and nothing else.**
>
> Re-verified against the **code** on 2026-08-23. Every question the system now answers by itself was
> **removed** rather than re-described — git has the previous version, and [§7](#7--closed--the-code-answers-these--do-not-re-ask)
> keeps one line per closed ID so a link from another document still lands on something true.
> **The IDs are unchanged**, because eleven other documents cite them.
>
> **Where answers go:** the **Answer** column here, then folded into
> [BUSINESS-RULES.md](BUSINESS-RULES.md) (the rule-by-rule detail) and
> [requirements/CLIENT-DISCOVERY-ANSWERS.md](requirements/CLIENT-DISCOVERY-ANSWERS.md) (the running
> record of what the client has told us).
>
> **This is the only live question list.** [operations/GO-LIVE.md](operations/GO-LIVE.md) is the
> launch gate — credentials, infrastructure, configuration items — and points here for the questions;
> the discovery questionnaire is a historical record, not a backlog.

## How to read this

| Marker | Meaning |
|---|---|
| 🔴 | **Answer before the first real invoice.** A wrong or missing answer means wrong tax, wrong money, or a document an auditor will query — and some of it cannot be corrected afterwards. |
| 🟠 | **Answer before the first real month.** It changes what the system does day to day. |
| 🟡 | **Confirm a default.** Built and working; silence ships what is described. |
| **→ code** | Saying *yes* means a build, sized here. Everything without this marker is a value you type on a screen. |

**Silence is a decision.** Every row states what ships if nobody answers.

**Two things this list is deliberately NOT:**

- **Not a feature backlog.** What we intend to build is [ROADMAP.md](ROADMAP.md) and
  [EGYPT-MARKET-FIT.md](EGYPT-MARKET-FIT.md); this file holds only what is *waiting on you*.
- **Not a list of things nobody has looked at.** Two dozen rows were removed on 2026-08-23 because
  the code already answered them — §7 names every one.

**Sections:** [1 · Before the first invoice](#1---before-the-first-real-invoice) ·
[2 · Before the first month](#2---before-the-first-real-month) ·
[3 · Do you need this?](#3--do-you-need-this--yes-means-code) ·
[4 · Confirm a default](#4---confirm-a-default-silence-ships-it) ·
[5 · Requirement wording](#5--requirement-wording-we-cannot-build-from) ·
[6 · IT and hosting](#6--it-hosting-and-secrets) ·
[7 · Closed](#7--closed--the-code-answers-these--do-not-re-ask)

---

## 1 · 🔴 Before the first real invoice

These have a deadline in the literal sense: after the first issued document, some of them cannot be
changed without leaving a visible break in the books.

| # | Question | What ships if you say nothing | Who | Answer |
|---|---|---|---|---|
| **A1.1** | **The operator's tax registration number, registered legal name, and billing-enquiries email.** Settings → Tax. | **Blank.** Every invoice is titled *Tax Invoice* and is not a valid one — the tenant cannot reclaim the VAT — and the credit note carries the same dependency in reverse. The PDFs omit the line rather than print a placeholder, because a plausible-looking TRN gets filed by the tenant and fails on audit. The name falls back to *"Atriom"*, which is the software's name and one no tenant has seen on a lease. | Accountant | |
| **A1.x** | **Sign off the tax treatment: which supplies are taxable, at what rate, and from when** — including the one Law 157/2025 forces: **is base rent now taxed, and from what date?** | Rent exempt, services at 14%. **All of it is configuration**: `charge_codes.tax_code` is the ruling as a row, and the rate is a dated rung at `/admin/tax-codes`, so a rise can be entered in advance and a back-dated invoice keeps the rate that was in force. Nothing here needs a release. | Accountant | |
| **C-TAX** | **Which supplies carry stamp tax (ضريبة الدمغة) or schedule tax (ضريبة الجدول)?** | Both families are in the catalogue with their own accounts and posting treatment (output = liability, input = **expense**), and **no charge code points at one**, so nothing is taxed under them. If a supply IS subject and no charge code says so, it is under-taxed on the return. | Accountant | |
| **A4.1** | **The real Egyptian chart of accounts.** *(The file supplied earlier was a Saudi contracting template — zakat, no VAT — and was rejected.)* | A starter Egyptian chart. **It is importable now** (EG-28): keyed on `code`, order-independent, with the cash-flow classification as a column. Also parked on you: **account code width, 8 vs 10 digits** — the system is width-agnostic, so this is your convention, not our constraint. | Accountant | |
| **A9.1 / A9.2** | **Sign off the posting map** — does every one of the 52 roles point at the right account in your chart? And **the 5% marketing levy: revenue, or a restricted marketing fund (a liability) to be spent on marketing?** Is it shown on the tenant invoice? | Seeded mapping; levy as revenue, billed as an invoice line. The map is re-pointable per role **and per property** from the screen, and a charge code may already point at any role, liabilities included — **→ code (XS)** only if you want a dedicated *marketing fund* role rather than reusing one. | Accountant | |
| **A2.7** | **Are invoices issued under Eltizam's TRN, or each owner's?** | One seller identity for the whole install. Two owners with two VAT registrations cannot both be billed correctly. **→ code (M)** — the per-asset issuer override `IssuingEntity` already documents and does not have. | Accountant + owner | |
| **A3.7** | **Opening balances** — AR, AP, bank, deposits held, fixed assets — **and the cut-over date.** | Nothing loaded, so the first trial balance is wrong by exactly the history that preceded it. **The machinery is built** (`/admin/opening-balances` takes your own trial balance; open AR and fixed assets have importers). What is missing is your numbers and your date. | Accountant | |
| **A8.3** | **What history migrates, how many years, and can you share sample files?** (Open AR, deposits, payment history, cheques, credits.) | Scope undefined. Importers exist for tenants, units, leases, charges, opening invoices, fixed assets and the chart; what they cannot do is guess which of your history matters. | Accountant + IT | |
| **B.1 / B.3 / B.4 / B.5** | **How is Eltizam paid, and whose bank account does tenant money land in?** Fixed fee, % of collected, % of gross? On rent only or all charges? Before or after VAT? Is the fee VATable and invoiced to the owner? Are *"owner"* and *"Jawad"* two parties or one? | No management-fee engine and no operator↔owner money flow. **Owner statements and disbursements are built** and show net **before** fee. **→ code (M)** for the fee line once the basis is known; the wider Jawad/Eltizam revenue split is a finance workshop, not an email — it needs legal entities, issuer-vs-payer separation and per-entity VAT. | Owner (Jawad) | |
| **C-NUM** | **The document number prefixes** — invoice, credit note, journal, bill, expense, deposit, receipt, payroll, purchase request, lease, cheque. | `INV` · `CN` · `JE` · `BILL` · `EXP` · `DEP` · `RCT` · `PAY` · `PR` · `LSE` · `PDC`, **continuous** (Yardi's scheme; `annual` and `monthly` are offered). **Hard deadline:** after the first issued invoice the prefix is printed on documents that cannot be renumbered, and changing it starts a *second* series rather than renumbering the first. | Accountant | |
| **C-FY** | **The month the fiscal year starts.** | January. **Refused once anything is posted** — moving it re-dates the periods, so a document in an open period lands inside a closed one. Free to decide on the empty install, expensive after. | Accountant | |
| **C-PAY** | **The statutory payroll rates** — salary tax and both social-insurance shares. | **0 · 0 · 0**, on a dated 1 Jan 2026 rung that already carries the insurable-wage band (2,700 / 16,700). Rates ship at zero deliberately: a guessed rate looks authoritative and is wrong. **But all three at nil on an approved run means net = gross on every payslip and no liability to the authority in the books** — `/admin/configuration-health` says so, advisory before the first run and blocking once an approved month withheld nothing. | Accountant / HR | |
| **C4.2** | **Target go-live date, parallel-run period, and who validates the migrated data on your side.** | Undefined. | Eltizam | |

---

## 2 · 🟠 Before the first real month

| # | Question | What ships if you say nothing | Who | Answer |
|---|---|---|---|---|
| **C1.9** | **The final month of an expiring lease — is the tenant entitled to a credit for the days they did not occupy?** | The full month is billed and the unearned part is **credited at move-out**, on the same rule the invoice billed on — and since EG-29 that rule is **the lease's own**. On a 30k lease ending on the 10th: ~20,300 credited under `actual`, 20,000 under `thirty_day`, **nothing at all** under `whole_month`. The clause matters more than the arithmetic. | Operations | |
| **C1.10** | **A tenant who stays past the lease end — should conversion to holdover be automatic, or always an operator's act?** | **An act.** Converting stamps `holdover_from` (what keeps the lease billing past its own expiry) and `holdover_rate_pct`, defaulting to **150%**. An unconverted overstay is alerted and unbilled — deliberately, because billing past a lease nobody has agreed to extend is a commercial claim rather than a calculation. | Owner + operations | |
| **C-SLA** | **Which SLA priorities are measured in WORKING time, and which on the calendar?** | **Empty — every priority runs on bare hours.** The calendar exists (Fri–Sat weekend, holidays register, Ramadan short days, per property) and ships off. Left unset, an urgent job raised Thursday 17:00 is due Friday 17:00 with nobody on site — **and a vendor SLA penalty is charged off that**, which an Egyptian contractor will contest. Each job freezes the clock it was promised on, so changing this never re-prices work in flight. | Operations | |
| **C-NSF** | **Price the returned-cheque fee** (per property). | **0**, and the action that charges it stays hidden until a figure is set. Normally two things in one flat figure: the bank's own returned-cheque charge, which you actually pay, plus an administrative component. | Operations + accountant | |
| **C2.4** | **A vendor SLA penalty is booked as a cost reduction, so the saving flows into the CAM pool tenants reimburse — is that intended, or should the mall keep it?** | The benefit reaches tenants. | Operations + accountant | |
| **A5.1 / A5.4** | **Is this workforce entitled to an end-of-service gratuity, and which employees are covered by social insurance?** | The exposure is **computed and reported** under Labour Law 12/2003 Art. 122 (both rates settings) and **posts nothing** — Art. 122 covers workers *not* under the social-insurance law, and most Egyptian employees are, so accruing a provision nobody owes overstates the liability as surely as omitting a real one understates it. **→ code (S)** to post the accrual once you rule. | Accountant / HR | |
| **A5.3** | **Should the system compute statutory payroll at all, or will each run always be keyed by hand?** | Keyed per run. The numbers are a **dated ladder** resolved for the run's own month, with social insurance on the insurable wage and the ceiling binding the employer share. **→ code (L)** for the seven-band progressive engine with the personal exemption — brackets are rungs with more columns, so they hang off the ladder that exists. | Accountant / HR | |
| **A2.9** | **Confirm the withholding rates by supply type** — published summaries disagree (1% / 2% / 3% / 5%). | Per-supplier rate → portfolio default → 0, on the VAT-exclusive share, with a quarterly Form 41 and per-supplier certificates. Only the rates are missing. | Accountant | |
| **A2.8** | **Do you charge a trade-name or brand component?** If so it attracts 10% schedule tax on 10% of the value. | Not charged, so not taxed. If you do charge one it is a charge code pointed at the schedule-tax code — configuration, not code. | Owner + accountant | |
| **C4.11** | **Which roles must have two-factor authentication, and from what date?** | **Nobody is forced.** `manager`, `accounting`, `leasing`, `operations` and `hr` handle payments and tenant data with no second factor. The recommended list is `SecurityDefaults::FORCE_2FA_ROLES` (9 roles). Switching it on marches every listed role through TOTP enrolment at their next login — a rollout to schedule with staff, and one that would block the people doing pre-go-live validation. `atriom:health` reports production as unhealthy until it is set, by design. | Eltizam IT | |
| **C4.12** | **A user with no property assigned — should they see nothing, or everything?** | **The two layers disagree.** Query scoping treats no-assignment as unrestricted (single-mall back-compat); the panel refuses entry to every property. The result was an account that could open no page, papered over by assigning everyone. **→ code (XS)** either way, once the policy is stated. | Eltizam IT | |
| **—** | **Auto-apply open credit is ON** (Voyager's behaviour). Confirm that suits. | On. A credit raised while a charge is in dispute will otherwise be consumed by the next invoice. | Operations | |

---

## 3 · "Do you need this?" — yes means code

Each row is a capability the system does **not** have. The size is ours; the answer is yours. None of
it is blocking — if the answer is no, the row disappears.

| # | Question | Today | Size | Answer |
|---|---|---|---|---|
| **A2.6** | **Tax-exempt tenants** (free zone, government, NGO, embassy) — do you have any? | Taxability resolves *charge code → tax code → dated rate*, one answer for the portfolio; there is no tenant or lease input. This is **EG-02**, and the fix is that third input, expressed as a tax CODE rather than a rate. | L | |
| **A2.1** | **Do tenants withhold tax from rent**, and do they issue certificates you must track? | The **vendor** side is built. A tenant who withholds still reconciles as an underpayment for ever. | M | |
| **A3.3 / A7.3** | **Should rent billed in advance be deferred and recognised over the period it covers?** | Recognised at issue. Money received with no invoice already sits as an on-account credit, separate from the deposit. *(Straight-line rent — EAS 49's other half — IS built and switchable.)* | M | |
| **A7.1** | **Should security cheques be held as their own class**, distinct from payment cheques? | The PDC register has no purpose column, so a security cheque is distinguishable only by a note. | XS | |
| **A9.5** | **Accrue a leave provision monthly** (the account exists in the chart)? | Not computed. Gratuity is A5.1. | S | |
| **A9.8** | **A salary-tax return**, beside the VAT return and Form 41? | Not built. | S | |
| **C1.8** | **Generate the lease contract as a PDF**, with signature tracked in-system? | Uploading a signed lease works; nothing generates one. | M | |
| **C2.5** | **Recharge a tenant-caused repair to that tenant?** If yes: VATable or cost recovery? Parts only, or parts + labour + the vendor's invoice? What if the cost changes afterwards, and can the tenant dispute it? | Responsibility is recorded; there is no path from a work order to a tenant invoice. | M | |
| **C2.7** | **Must a vendor bill back an externally-bought part before the job can close?** | Recorded on the work order; nothing requires a bill. | XS | |
| **C3.1** | **Bins or shelves inside a warehouse?** | A warehouse is the finest grain. | M | |
| **C3.2** | **Inter-mall stock transfers?** | The movement types exist and **nothing creates them** — it looks shipped and is not. Note it moves value between two properties' books. | M | |
| **C3.6** | **The approval chain for inter-department requests and payments routed through Accounting.** Does a large spend need more than one approver? | `approval_rules` is a single-level band lookup per module. | M | |
| **C3.8** | **Per service: billed out (chargeable) or absorbed as a unit expense — plus an annual report either way.** | Not distinguished. | M | |
| **C4.1** | **WhatsApp or SMS** to tenants? | Email, in-app bell and push (built, not live). Branded email templates exist. | M | |
| **C4.10** | **Should a role's authority differ per property?** (Manager at Mall A, viewer at Mall B.) | A role is portfolio-wide; property assignment is a separate list. Deliberately not built — with zero staff assigned to both malls it has no expressible case. **The trigger to revisit is the first person assigned to both.** | L | |
| **C4.13** | **Should a technician be emailed when work is assigned to them?** | Bell only. The five alerts with a deadline also email; assignment deliberately does not, because mailing everything trains people to ignore the alerts that matter. | XS | |
| **C1.1** | **Confirm your unit types and statuses.** | `retail · food_beverage · wellness · service · kiosk · office · storage`, and `vacant · reserved · occupied · maintenance`. **These are a code-side value set, not an operator catalogue** — a different list is a one-line change, not a row you can add. | XS | |
| **A1.8b** | **Is 7% the house escalation default?** | The deposit default became a per-property setting (EG-35) and payment terms follow the property's convention (2026-08-23); **the 7% escalation is still a literal** in lease creation, so a different house policy is keyed on every lease. | XS | |
| **E.4** | **Must completing a work order require a photo?** All work orders, only technician-completed corrective ones, or none? | Tenant-request evidence shipped; work-order photos can be attached and are not required. | XS | |
| **E.5** | **Is who/when/from→to enough for status history**, or do you need per-step comments and attachments? | Who/when/from→to is recorded. | M | |
| **B2.1 / B2.2** | **Which GL account does the unit-owner letting FEE post to**, and **is there a sinking fund (صندوق صيانة)** collected from unit owners — if so, which liability account? | Both block module 37's phase 5. The fee % and its basis are already configurable per ownership; only the account is missing, and a charge code may already point at any of the 52 roles, liabilities included — so this is close to configuration once you name the account. | XS | |
| **B.7 / B.5 / B.8** | **Does Eltizam hold a float per property? Should tenant money sit in a trust/escrow per property? Is each mall a separate legal entity with inter-company entries?** | None modelled; single-company GL with a property dimension. | M / L / XL | |
| **A7.4** | **Is anything really billed in USD or EUR?** | **EGP only, and enforced** — the value set refuses a non-EGP currency. If a lease is USD-*linked*, the recommended answer is to index the escalation and denominate in EGP (EG-31), not full multi-currency. | M | |
| **C3.7** | **"Personal accounts" (محسوبات شخصية)** — who exactly, and what for? | **Custody (عهدة) and employee advances are built** and post to the GL. What does not exist is a per-person sub-ledger beyond those — and we cannot size that until we know who it is for. | ? | |

---

## 4 · 🟡 Confirm a default (silence ships it)

Built, working, and reasonable. Each is one word from you — *"yes"*, or a different number.

| # | Confirm | Ships as |
|---|---|---|
| A1.2–A1.6 | Percentage rent, CAM true-up charges, late fees and the marketing levy are **VAT-exempt**; the levy is **5% of base rent only**, accrued and never shown to the tenant; CAM is allocated **pro-rata by leased m²** | As described — and every one is a row on `/admin/charge-codes`, so a different ruling is a row, not a release |
| A1.7 | Late fee **2%** of outstanding, **minimum 50 EGP**, **7-day grace**, charged **once**, **no cap** | Five settings on three tiers (lease → property → portfolio); 0 = no cap and 0 = charge once, so nothing moves until you change it |
| A1.8 | **Security deposit 3 months**, **escalation 7% fixed** | Deposit is a per-property setting; escalation is per lease, with a CPI-indexed option — see A1.8b for the default |
| A1.9 | The **artificial breakpoint** for percentage rent — `(sales − threshold) × rate` | Per lease, with a natural-breakpoint option and monthly-vs-annual cumulation |
| A1.10 | **Payment terms 7 days** from issue | A setting, per property, applied at lease origination — the lease then carries its own number, so changing it never re-dates receivables already raised |
| A3.2 | **Accrual, revenue at issue.** Straight-line rent (EAS 49) is built and **off** | Flip it in Billing settings when your accountant decides |
| A3.4 / A3.8 | Period close blocks back-dated posting; reporting is per property and consolidated | As described |
| A5.2 | Payroll withholdings split into their own payable accounts | As described |
| A6.1 | **Egyptian tax depreciation rates per class** (5 / 10 / 25 / 50%, Law 91/2005 art. 25) | The schedule is built and computed; confirm the rates you file at |
| A6.2 / A9.6 | Monthly depreciation run, bilingual payslips, per-asset useful life and salvage value | As described |
| A7.2 / A7.5 | Deposit is a refundable liability with no VAT; discounts go through credit notes with approval and an audit trail | As described |
| A9.3 / A9.4 | CAM presented **gross**; inventory at per-movement unit cost (FIFO on procurement receipts) | As described |
| A8.1 / A8.2 | The report set at go-live, and the export format your stakeholders need | 23 report pages, CSV + XLSX, saved views, scheduled email delivery |
| B.2 / B.9 | Co-owners with % and dates; the owner is oversight + requests and approves nothing before Eltizam acts | As described |
| B2.3 / B2.4 / B2.5 | Unit-owner صيانة is property revenue; no operator approval on a resale; a purchase-value owner's denominator is the **sold cohort** | As described — B2.5's other reading is one method in one service |
| C1.2–C1.6 | Renewal, escalation, early termination, fit-out grace (a **full** grace: rent, service, CAM and levy all suppressed), manual sales declarations | As described |
| C2.1 / C2.2 | CAM pool contents and the annual true-up; utilities passed through at cost, no markup, no cap | As described |
| C2.3 | The SLA hour targets the breach scan alerts on | Settings — the working-calendar half is C-SLA |
| C2.6 / C3.5 / C3.9 | Approval bands **1,000 / 10,000 EGP**, the same for procurement and spare parts; delete is super-admin only, and money records are never deletable at all | As described |
| C3.3 / C3.4 | Warehouse categories (free text — name as many as you like) and one reorder level + reorder quantity per item | As described |
| C4.3 | Training format and which roles | Undefined |
| D.2 | **Paymob card payments** — activate now or later? | Built, off |
| D.4 | Hosting, backup/DR expectations, and what happens to an account when someone leaves | Cloud-ready; daily backups with a weekly restore drill; deactivate rather than delete |
| E.3 | *"Admin (per mall) — full access"* does **not** include deleting records | Deletion is super-admin only; money records refuse deletion outright |

---

## 5 · Requirement wording we cannot build from

Each needs **one clarifying sentence**.

| # | Question | Why we stopped |
|---|---|---|
| **E.1** | **FR-REQ-01 "delegation (from/to)"** — what does it mean? | No such concept exists anywhere in the system or the rest of the FRD |
| **E.2** | **FR-PPM-01 "Fixed maintenance"** — one-time, or periodic per asset? | The FRD says **both**, in different sentences. We support periodic |

---

## 6 · IT, hosting and secrets

| # | Question | Today |
|---|---|---|
| **D.5** | **Where do live Paymob credentials live, and who rotates them?** | Four live keys in plaintext `.env`, no vault, no rotation procedure. **A leaked HMAC secret lets someone forge a "paid" callback** — invoices marked settled with no money arriving. The verification itself is sound (SHA-512, `hash_equals`, fails closed) |
| **D.6** | **Is the app reachable ONLY through the reverse proxy?** | `TRUSTED_PROXIES=*`, which is what makes login throttling and the audit trail see the real client IP. Safe **only** while nothing can reach the app directly — otherwise a caller forges `X-Forwarded-For` and becomes un-throttleable. If there is a direct address, give us the proxy IPs to pin |

---

## 7 · Closed — the code answers these · do not re-ask

Removed from the list above on **2026-08-23**, each checked against the code. One line apiece, so a
link from another document still resolves.

| # | Why it is closed |
|---|---|
| A2.2 | **Stamp tax is built** — the family is in the dated catalogue with its own posting roles, and bills the moment a charge code points at it. *(Which supplies is C-TAX.)* |
| A2.3 | **Real-estate tax: the cost side is built** (EG-33) — a recurring schedule including **semiannual**, Egypt's two instalments — and recovery through a CAM pool already worked. The **assessment** is deliberately not modelled: a computed guess would go on a statutory filing |
| A2.4 · D.1 · D.3 | **e-invoicing is FROZEN in code** (2026-08-22). The module answers *disabled* before any settings row is read. Not a question, and not work to schedule |
| A2.5 | **The VAT return is built** — `/admin/vat-return`, by document, with the ledger tie-out |
| A3.1 | Full double-entry GL, here, with a property dimension |
| A3.5 | **Bank reconciliation is built** — statements, lines and matching; and since EG-12 each document carries its own bank account, so two banks no longer share one chart account |
| A3.6 | **Write-off is built** — `WriteOffInvoiceService` and a `written_off` status the tenant still sees. Who may approve is RBAC |
| A3.7 *(mechanism)* | **The opening-balance screen and its importers are built.** Only your figures and your date are missing — the row stays open in §1 for those |
| A4.1 *(mechanism)* | **The chart is importable** (EG-28). Only the file is missing — the row stays open in §1 for that |
| A5.1 *(half)* | The **employer's** social-insurance contribution is recorded and posts. Gratuity is §2 |
| A5.3 *(mechanism)* | **Payroll numbers are a dated ladder** (EG-03), resolved for the run's own month, with the insurable-wage band. Only the rates and the bracket decision are open |
| A6.1 | **Egyptian tax depreciation is built** — statutory pools and the temporary difference from the book figure. A schedule, not a second ledger, because Egypt files single-book |
| A7.1 *(half)* | **The PDC register is built** — lifecycle, bulk series lodging, maturity dashboard, GL posting |
| A9.7 | **A bank account per mall** (EG-12) and **configurable numbering** including the reset rule (EG-10) |
| A9.8 *(half)* | **Form 41 is built** (EG-21) — quarterly, per registration, with per-supplier certificates and a tie-out |
| B.6 | **Owner statements and disbursements are built** (module 32). Only the fee line waits, on B.4 |
| C1.1 *(half)* | **The occupancy map is built** — a per-floor grid with each unit's status and tenant |
| C1.5 | **Fit-out grace is built** — a full grace for whole months from commencement |
| C1.7 | **Both halves built** — multi-user portal accounts, and tenant documents with an insurance-COI type, expiry dates and a scheduled expiry scan |
| C1.9 *(arithmetic)* | **Proration is the lease's to state** (EG-29): `actual` · `thirty_day` · `year_365` · `whole_month`, plus a per-charge *never prorate* flag. Only the entitlement is open |
| C1.10 *(billing)* | **A holdover IS billable** once converted — `holdover_from` keeps it billing, at `holdover_rate_pct`. Only the automatic-conversion policy is open |
| C2.3 *(calendar)* | **The working calendar is built** (EG-08/EG-38) — Fri–Sat weekend, holidays register, Ramadan short days, per property. Ships off; see C-SLA |
| C3.3 *(mechanism)* | `warehouses.category` is free text — name as many categories as you like |
| C3.7 *(two thirds)* | **Custody (عهدة) and employee advances are built**, both posting to the GL. Only *who a personal account is for* is open |

---

## What happens if you answer nothing

- Everything in **§4** ships as described — those are working defaults.
- Everything in **§3** stays unbuilt. The rows with real money behind them are **A2.6** (no
  per-tenant tax treatment), **A2.1** (a withholding tenant reconciles as an underpayment for ever)
  and **C2.5** (no way to recharge a tenant-caused repair).
- Everything in **§1** is the risk — **A1.1** above all: without the operator's TRN every invoice is
  titled *Tax Invoice* and is not one, and no tenant can reclaim the VAT on it.

## Sign-off

| Section | Owner | Date |
|---|---|---|
| Tax, GL, payroll, assets, opening balances (A·) | *accountant* | |
| Owner money-flow (B·) | *owner (Jawad) + Eltizam finance* | |
| Operations (C·) | *Eltizam operations lead* | |
| IT, hosting, secrets (D·) | *Eltizam IT* | |
| Requirement wording (E·) | *Eltizam operations lead* | |

**Related:** [BUSINESS-RULES.md](BUSINESS-RULES.md) (every rule + risk level) ·
[operations/GO-LIVE.md](operations/GO-LIVE.md) (the launch gate) ·
[EGYPT-MARKET-FIT.md](EGYPT-MARKET-FIT.md) (what an operator can change without a developer) ·
[requirements/CLIENT-DISCOVERY-ANSWERS.md](requirements/CLIENT-DISCOVERY-ANSWERS.md) (answers already collected).
