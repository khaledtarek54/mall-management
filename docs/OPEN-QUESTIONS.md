# Atriom — Open Questions (single hand-out)

> **Start at [GO-LIVE.md](operations/GO-LIVE.md)** if what you want is "what is left before we launch". That is
> the single gate — configuration, credentials and decisions in one list, each re-verified against
> the code 2026-08-11. This file stays the DETAIL behind the questions it names.

> **What this is.** Every open question we need answered, in **one document**, grouped by **who can
> answer it** — so you can hand each section to the right person. It supersedes and merges the older
> scattered question docs (client-questions, the accountant & operations meeting agendas, the
> BUSINESS-RULES top-questions, the accounting walkthrough's questions, and the FRD open items).
>
> **Where answers go:** record them here in the **Answer** column, then fold the confirmed ones into
> [BUSINESS-RULES.md](BUSINESS-RULES.md) (the rule-by-rule detail) and
> [discovery/client-discovery-questionnaire.md](requirements/CLIENT-DISCOVERY-ANSWERS.md) (the
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

## Status at a glance — reviewed 2026-07-29, spot-checked 2026-08-12, **re-verified against the code 2026-08-23**

> **Read [the 2026-08-23 verification pass](#2026-08-23--verified-against-the-code-what-the-system-already-answers) first.**
> It classifies every question as ⚙️ configuration · ✅ already built · 🧑‍💻 buildable · 🔑 only you can
> answer — checked against the code rather than against this file, which had fourteen rows describing
> as missing something that has since shipped. The summary immediately below is the older reading.

> **2026-08-12:** the two questions that now block WORK rather than polish are **A9.x stamp/schedule tax GL accounts** (eleven catalogue codes ship inactive without them) and the **budget shape** (§4.8 of the accountant briefing, added the same day). Everything else on the prioritised roadmap is shipped or closed. A9.7's numbering half became answerable and carries a go-live deadline — see the row.

The list was consolidated 2026-07-18 and a lot shipped after it. This review re-checked every
"what we do today" claim against the code, so a meeting is spent on questions that are still real.

**Five rows were STALE and have been corrected** — do not ask the client about these as if nothing
exists:

| # | Was | Actually |
|---|---|---|
| A5.1 | "employer share + gratuity **not** recorded" 🔴 | Employer social insurance **is** recorded (`payrolls.employer_social_insurance` → Dr 51110001). Only **gratuity accrual** remains → narrowed to 🟠 |
| A7.1 | "no cheque register — likely a **new module**" | **Module 33 shipped**: full PDC register, status lifecycle, bulk series lodging, maturity dashboard, GL posting. Only "hold security cheques separately?" remains |
| A2.1 | "WHT **not modelled**" 🔴 | **Vendor side shipped** (per-supplier rate + withheld amount on payments, posting to GL). **Tenant-side withholding is still open** — that half stays 🔴 |
| B.6 | "no disbursement/owner-statement module" | **Module 32 shipped**: statement runs, accrual GL spine, finalise/send, PDF, disbursements. What is missing is the **management fee**, which is blocked on B.4 |
| C1.1 | "no floor-plan view" | **Occupancy map is built** — per-floor visual grid with status + tenant |

**Genuinely still open, by who can answer it:**

- **A · Accountant** — the whole of A1 (the tax numbers the billing engine computes from), A4 (the
  real chart of accounts), A9.1/A9.2 (posting map + marketing-levy treatment), A3.7 (opening
  balances). **This is the big block and the client already knows it.**
- **B · Owner (Jawad)** — B.1/B.3/B.4/B.5 (how Eltizam is paid, whose account money lands in). B.4
  is the one with a build waiting behind it: the owner-statement fee line.
- **C · Eltizam operations** — C1.8 **(new, 🔴: does the final month of an expiring lease bill in
  full or pro-rata — ~20,300 EGP per departing tenant on a 30k lease)**, plus the C4.10–C4.13 access
  and alerting decisions added 2026-07-29.
- **D · IT** — D.1 (ETA credentials), D.5/D.6 **(new: Paymob secret storage, and whether the app is
  reachable outside the proxy)**.
- **E** — five requirement clarifications, unchanged.

**Not asked here because they are engineering decisions already taken:** CI runs on demand only;
per-property roles deliberately not built (C4.10 records the trigger); the app timezone is
`Africa/Cairo`; logs rotate daily.

---

## 2026-08-23 — verified against the CODE: what the system already answers

> **Why this pass exists.** EG-02's question — *"which units carry the schedule tax, and from
> when"* — turned out to be a **configuration** answer, not a code one, and the document said
> otherwise. So every row below was re-checked **against the code today**, not against this file.
> Fourteen rows were stale in the same direction: they describe as missing something that has
> since shipped.

| Marker | Meaning |
|---|---|
| ⚙️ | **The system answers it.** A screen, a row or a setting — no deploy, no build. All that is left is you stating the value. |
| ✅ | **Built since this document was written.** The row was stale; do not ask it. |
| 🧑‍💻 | **Not expressible today, and code CAN answer it** — a customization, sized here. |
| ⏸️ | Frozen or already decided. Not a live question. |
| 🔑 | **Only you can answer**: a contract term, a legal ruling, a number, a credential. Most ⚙️ rows carry one of these too — the knob exists, the value is yours. |

### ✅ Stale — these are BUILT, and the row below still said they were not

| # | What actually exists |
|---|---|
| A2.2 | **Stamp tax** — the whole family is in the dated tax catalogue with its own posting roles (output = liability, input = expense). Point a charge code at it and it bills. |
| A2.5 | **VAT return** — `/admin/vat-return`, output and input by document, with the ledger tie-out. |
| A3.5 | **Bank reconciliation** — statements, lines, matching, and since EG-12 a per-document `bank_account_id` so two banks stop sharing one chart account. |
| A3.6 | **Bad-debt write-off** — `WriteOffInvoiceService` + a `written_off` status. |
| A3.7 | **Opening balances** — a screen that takes the accountant's own trial balance, plus open-AR and fixed-asset importers. Only the DATA is missing. |
| A4.1 | **The chart is IMPORTABLE** (EG-28) — keyed on `code`, order-independent, `cash_flow_section` included. Still waiting on the accountant's file. |
| A5.3 | **Statutory payroll numbers are a dated ladder** (EG-03) resolved for the run's own month, with the insurable-wage band. |
| A6.1 | **Egyptian tax depreciation** — `/admin/tax-depreciation`, statutory pools, and the temporary difference from the book figure. A schedule, not a second ledger, because Egypt files single-book. |
| A9.7 | **A bank account per mall** (EG-12) and **configurable numbering** incl. the reset rule (EG-10). |
| A9.8 | **Form 41** — quarterly, per registration, with per-supplier certificates (EG-21). |
| C1.7 | **Tenant insurance certificates** — typed documents with expiry and a scheduled expiry scan. |
| C1.9 | **Proration is the LEASE's to state** (EG-29) — `actual` · `thirty_day` · `year_365` · `whole_month`, plus a per-charge *"never prorate"* flag. |
| C1.10 | **A holdover IS billable** once converted — `holdover_from` keeps the run going past expiry, at `holdover_rate_pct` (150% default). |
| C2.3 | **A working calendar exists** (EG-08/EG-38) — Fri–Sat weekend, holidays register, Ramadan short days, per property. Ships off. |

### ⚙️ Configuration — the system answers it; you only state the value

**A1.1–A1.10** (every tax rate, which supplies are taxed, the marketing levy, the CAM basis, the
late-fee policy incl. its new CAP and RECURRENCE, the deposit months, the breakpoint type, payment
terms) · **A3.2** straight-line rent (a switch) · **A3.4** period close · **A5.2** payroll splits ·
**A6.2** depreciation run + payslips · **A7.2** deposit treatment · **A7.5** credit notes +
approvals · **A8.1/A8.2** reports and CSV/XLSX export · **A9.1** the posting map (re-pointable
per role AND per property) · **A9.3** CAM gross · **A9.4** valuation · **A9.6** useful lives ·
**B.2** co-owners with % and dates · **C1.2–C1.6** renewal, escalation (incl. CPI-indexed),
termination, fit-out grace, sales declarations · **C2.1** the CAM pool · **C2.6/C3.5/C3.9**
approval bands and who may delete · **C3.3** warehouse categories (free-form) · **C3.4** reorder
level and quantity · **C4.11** 2FA roles · and the GO-LIVE decisions **C-NUM · C-FY · C-NSF ·
C-TAX · C-PAY · C-SLA**, every one a settings field.

> **The one thing to take from this list:** none of it is a build. If the answer is *"we do it
> differently"*, the change is a value on a screen, not a release.

### 🧑‍💻 Not expressible today — and code CAN answer it, like EG-02

| # | What a customization would be | Size |
|---|---|---|
| A2.6 | **Tax-exempt tenants** — EG-02's twin, and the same fix: a lease/unit tax input on the resolver, as a tax CODE not a rate | L |
| A2.7 | **A TRN per owner** — `IssuingEntity` documents the per-asset override and does not have it, so two VAT registrations cannot both be billed correctly (T-10) | M |
| A2.1 | **Tenant-side withholding** — a tenant who withholds from rent reconciles as an underpayment for ever | M |
| A3.3 / A7.3 | **Recognising rent-in-advance over the period it covers.** The role exists; only unapplied payments and tenant credits use it | M |
| A5.1 / A9.5 | **Posting** the gratuity accrual (the figure is already computed and reported) and a leave provision — after your entitlement ruling | S |
| A7.1 | **A security cheque as its own class** — one column, a filter, and a rule that it never auto-clears | XS |
| A9.8 | A **salary-tax return** beside the VAT and WHT ones | S |
| C1.8 | **A generated lease contract PDF** + signature tracking (uploading a signed one already works) | M |
| C2.5 | **Recharging a tenant-caused repair** — responsibility is recorded; there is no path from a work order to a tenant invoice | M |
| C2.7 | **Requiring a vendor bill before a work order may close** | XS |
| C3.1 | **Bins/shelves inside a warehouse** (a warehouse is the finest grain today) | M |
| C3.2 | **Inter-mall stock transfers** — the movement types exist and nothing creates them; note it moves value between two properties' books | M |
| C3.6 | **A multi-level approval CHAIN** — `approval_rules` is a single-level band lookup | M |
| C3.8 | **A per-service chargeable-vs-absorbed toggle** + the annual report either way | M |
| C4.1 | **WhatsApp / SMS** channels (email + in-app + push exist) | M |
| C4.10 | **Role authority per property** — deliberately not built; the trigger is the first person assigned to both malls | L |
| C4.12 / C4.13 | The unassigned-user policy, and emailing a technician on assignment | XS |
| C1.1 | **Unit types/statuses** — these are a code-side value set, not a catalogue. If your list differs, it is a one-line change (not a row) | XS |
| E.4 / E.5 | Requiring a completion photo; per-step comments and attachments on status history | XS / M |
| B.4 / B.6 | **The owner-statement management FEE** — built-but-omitted, waiting on the % and its basis | M |
| B2.1 / B2.2 | **The unit-owner fee account and the sinking fund.** Both are nearly configuration: a charge code may already point at ANY of the 52 posting roles, liabilities included — what is missing is a dedicated role + mapping | XS |
| B.5 / B.7 / B.8 | Trust/escrow per property · an operator float per property · separate legal entities and inter-company | L–XL |

### ⏸️ Not live questions

**A2.4 · D.1 · D.3** — e-invoicing is **frozen in code** (`Modules::FROZEN`, 2026-08-22): the module
answers *disabled* before any settings row is read. Not a question, and not work to schedule.
**A7.4** — multi-currency was decided (EGP only, enforced at the value set); if a lease is really
USD-linked the answer is EG-31, index the escalation and denominate in EGP.
**C-NUM's reset rule** — decided by market standard (EG-10): continuous, like Yardi and MRI.

### 🔑 Genuinely yours — no code and no configuration can answer these

**A1.x values** (the rates and policies themselves) · **A4.1** the accountant's chart file ·
**A3.7** the opening figures and the cut-over date · **A8.3** what history migrates and sample
files · **B.1/B.3/B.4/B.5** how Eltizam is paid and whose account money lands in · **C1.9** whether
a departing tenant is *entitled* to the unearned credit · **C1.10** whether holdover conversion
should be automatic · **C2.4** whether an SLA penalty's benefit should reach tenants · **C4.2/C4.3**
go-live date and training · **D.5/D.6** secret storage and whether the app is reachable outside the
proxy · **E.1/E.2/E.3** one clarifying sentence each.

---

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
| A1.1 | Is **14% the correct VAT rate** on service charges, utilities, parking — and is **base rent genuinely VAT-exempt**? | 14% on services; rent exempt. **The rate is now MASTER DATA** — a dated rung on a tax code at **/admin/tax-codes** (`TaxSettings::vat_standard_rate` was removed 2026-08-12) — so a different answer, or a future rise entered in advance, is a row your accountant adds, not a code release. **But taxability itself has no lease dimension and is frozen onto each lease's charge rows at creation** — see [EGYPT-MARKET-FIT.md](EGYPT-MARKET-FIT.md) T-1…T-4, which matters now that Law 157/2025 has pulled property rental into the tax net. Applies to what is billed from then on; already-issued invoices keep their rate. | Every invoice has the wrong VAT | 🔴 | |
| A1.2 | Is **percentage rent VAT-exempt**? | 0% VAT | Every %-rent invoice under-charges VAT | 🔴 | |
| A1.3 | Are **CAM true-up charges VAT-exempt**? | 0% VAT (our assumption) | Every reconciliation invoice under-charges VAT | 🔴 | |
| A1.4 | Are **late fees VAT-exempt**? | 0% VAT (penalty outside VAT) | Every late fee under-charges VAT | 🔴 | |
| A1.5 | Is the **marketing levy 5% of base rent only**, accrued internally and **never shown on the tenant invoice**? | Yes, both | Levy mis-calculated, or tenants should see it | 🔴 | |
| A1.6 | Is **CAM allocated pro-rata by leased area (m²)**? Does the lease wording say "by area"? | Pro-rata by m² | Leases by turnover/fixed share are allocated wrong | 🔴 | |
| A1.7 | **Late-fee policy:** 2% of outstanding, min 50 EGP, charged **once** (not compounding), after a **7-day** grace? | ⚙️ **All four are settings, on three tiers** (lease → property → portfolio) — and since EG-35 (2026-08-22) there is also a **CAP** (`late_fee_maximum`, 0 = none, applied AFTER the minimum) and **RECURRENCE** (`late_fee_recurrence_days`, 0 = charge once). A fee never earns a fee. | Only the NUMBERS are yours; none of this is code | 🔑 | |
| A1.8 | **Security deposit = 3 months' rent** and **annual escalation = 7%** — the real contract defaults? | ⚙️ Both are settings: `BillingSettings::default_security_deposit_months` (EG-35 — it was a literal `3` until 2026-08-22) and the escalation type/percent per lease, incl. a CPI-indexed option (`rent_indices`). | Every new lease starts from the default you set | 🔑 | |
| A1.9 | Is the **artificial-breakpoint** formula right for %-rent — `(sales − threshold) × rate`? | Yes; leases with no type set use it **silently** | If leases use the natural breakpoint, %-rent is wrong | 🔴 | |
| A1.10 | Is the **default payment term 7 days** from issue? | ⚙️ A setting (`BillingSettings`), overridable per property and per lease. | Due dates + the whole overdue/late-fee chain shift | 🔑 | |

> **Verify once confirmed:** `php artisan billing:reconcile` independently re-derives receivables and
> prints control totals (invoiced / collected / credits / outstanding AR / VAT) to reconcile against
> your books.

### A2 · Taxes Atriom does not yet model — 🔴/🟠 surface before go-live

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| A2.1 | **Withholding tax (WHT) — the TENANT side only now:** do tenants withhold from rent, at what rate, and do they issue WHT certificates you must track? | 🟡 **Vendor side built (module 12):** `vendors.withholding_tax_rate` per supplier + `vendor_bill_payments.withholding_amount`, settings-driven, posting to the GL — so paying a contractor net of WHT no longer looks like a shortfall. **The tenant side is still unmodelled:** a tenant who withholds from rent still reconciles as an underpayment. | 🔴 | |
| A2.2 | **Stamp tax (رسم دمغة)** — which supplies carry it, and at what rate? | ⚙️ **BUILT 2026-08-19 — configuration, not work.** The stamp family is in the tax catalogue (both directions) with its own posting roles, and the journalizers group tax by the tax code's own role — output stamp is a LIABILITY, input stamp an EXPENSE. A code taxes a supply the moment a charge code points at it: a row, no deploy. **What is yours is WHICH supplies and at what rate.** | 🔑 | |
| A2.3 | **Real-estate / property tax (الضريبة العقارية)** — charged on units? Recharged to tenants or owner-borne? | ⚙️ **Cost side BUILT (EG-33, 2026-08-23):** a recurring expense schedule — monthly · quarterly · **semiannual** (Egypt's two instalments) · annual — that mints the expense and posts it; recovery through a CAM pool already worked. **The ASSESSMENT is deliberately not modelled** (rate, rental-value basis, the 32% non-residential deduction): a computed guess would go on a statutory filing. | 🔑 | |
| A2.4 | **e-Receipts** (B2C / cash) needed, separate from B2B e-invoices? | ⏸️ **Moot while module 16 is FROZEN** (`Modules::FROZEN`, 2026-08-22). e-invoicing itself is frozen in code, so its B2C sibling is not a question anyone need answer today. | ⏸️ | |
| A2.5 | **VAT filing period** (monthly?) — need a **VAT output report** (by invoice) for the return? | ✅ **BUILT.** `/admin/vat-return` (`VatReturnService`) — output and input VAT by document for a period, with the ledger tie-out. Its withholding sibling (Form 41) shipped with EG-21. | ✅ | |
| A2.6 | Any **tax-exempt tenants** (free zone, government, NGO, embassy)? | 🧑‍💻 **Not expressible — this is EG-02's twin.** Taxability resolves *charge code → tax code → dated rate*, one answer for the portfolio; there is no tenant or lease input. The nearest channel today is a per-charge `vat_rate` override reachable through the Charges importer, which moves the RATE and not the tax CODE. **Code can answer it** — the same third input EG-02 describes. | 🧑‍💻 | |
| A2.7 | Are invoices issued under **Eltizam's TRN or each owner's TRN**? | 🧑‍💻 **One seller identity for the whole install** (`TaxSettings::seller_tax_registration_number`); `IssuingEntity` documents the intended per-asset override and does not have it. Two owners with two VAT registrations cannot both be billed correctly. **Code can answer it** (EGYPT-MARKET-FIT T-10). | 🔴 | |

### A3 · General ledger, close & controls

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| A3.1 | Full **GL / chart of accounts here**, or accounting done elsewhere (export vs live integration, which software)? | Full double-entry GL exists (modules 21–29) | 🟡 | |
| A3.2 | **Accrual or cash basis**? Need **straight-line rent recognition** (spread rent-free/escalations)? | Accrual, revenue-at-issue. **Straight-line rent IS built** (`StraightLineRentAdjustment`, `PostStraightLineRentService`, a lease relation manager) and ships **off** behind `BillingSettings::straight_line_rent_enabled` — EAS 49 requires it for a lessor, so this is now a switch your accountant flips, not work to do | 🟠 | |
| A3.3 | How is **rent-in-advance** treated — deferred/unearned until earned? | 🧑‍💻 Revenue is recognised at ISSUE. `unearned_revenue` exists as a posting role but only an unapplied payment and an applied tenant credit use it — rent billed for a future period is not deferred over that period. *(EAS 49's other half, straight-line rent, IS built and switchable — A3.2.)* **Code can answer it.** | 🟠 | |
| A3.4 | **Fiscal year** (Jan–Dec)? Do you **lock/close periods** to block back-dated edits? | Period close exists; closed periods refuse back-dated posts | 🟡 | |
| A3.5 | **Bank reconciliation inside the system**, or external? | ✅ **BUILT — the row was stale.** `bank_statements` / `bank_statement_lines` / `bank_matches` with a matching service; since EG-12 (2026-08-22) each document carries its own `bank_account_id`, so a mall banking in two places no longer offers one bank's postings against the other's statement. | ✅ | |
| A3.6 | **Bad debt / write-offs** — process + who approves? | ✅ **BUILT — the row was stale.** `WriteOffInvoiceService` + a `written_off` status a tenant still sees (a document that explains a number they remember). **Who may approve is RBAC configuration.** | ⚙️ | |
| A3.7 | **Opening balances** (AR, cash, deposits, prepaid, payables) to load at go-live — as of what date? | ⚙️ **The MACHINERY is built:** `/admin/opening-balances` (a trial balance pasted from the accountant's own sheet, `ImportOpeningBalancesService`), `OpeningInvoiceImporter` for open AR, and an opening fixed-asset import. **What is missing is your DATA and your cut-over date** — nothing here is code. | 🔑 | |
| A3.8 | Need **cost centres / segment reporting per property** (and per unit)? | Property scoping exists | 🟡 | |

### A4 · Chart of accounts (still waiting) — 🔴

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| A4.1 | Can **Mr. Ibrahim / Jawad provide the real coded chart of accounts**? (The file received earlier was a Saudi *contracting* template — zakat, no VAT — and was rejected.) | ⚙️ **Since EG-28 (2026-08-22) the accountant's chart is IMPORTABLE** — `LedgerAccountImporter`, keyed on `code`, order-independent (a child before its parent re-parents itself), with `cash_flow_section` as a column so a foreign chart classifies correctly. `parent_id` and `normal_balance` are derived, never imported. **Still blocked on the file itself.** | 🔑 | |
| A4.2 | Are the account names/codes right for Egyptian practice? | Starter names/codes | 🟡 | |

### A5 · Payroll — the one item that may mean the books are wrong today

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| A5.1 | **Is the accruing end-of-service gratuity captured anywhere?** | 🟡 **Employer social insurance IS recorded.** Gratuity: `GratuityService` computes the exposure under Labour Law 12/2003 Art. 122 with both rates as settings, and **ships OFF and posts nothing** — Art. 122 covers workers *not* under the social-insurance law, and most Egyptian employees are. **Two things are needed: your entitlement ruling (🔑), then wiring the accrual to the GL (🧑‍💻, small).** | 🟠 | |
| A5.2 | Are the payroll **withholdings** (salary tax + social insurance) split the way you need? | Split into their own payable accounts | 🟡 | |
| A5.3 | Are **statutory amounts** (tax brackets / insurance rates) something the system should compute, or always keyed per run? | ⚙️ **EG-03 (2026-08-22) made them a DATED LADDER** (`payroll_rates`, `PayrollRates::for($periodMonth)`) — a January run generated in March uses January's figures and a rise is enterable in advance. SI is charged on the **insurable wage** and the ceiling binds the employer share. Rates ship at **0** on purpose. **The seven-band progressive engine is the one unbuilt half (🧑‍💻), and §6.4 asks you first whether it should compute at all.** | 🔑 | |

### A6 · Fixed assets & depreciation

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| A6.1 | Do you need **Egyptian tax depreciation** (Law 91/2005 pools) as a **second book** alongside straight-line? | ✅ **BUILT — the row was stale.** `/admin/tax-depreciation` + `TaxDepreciationService`: statutory rates, pooled diminishing value, and the temporary DIFFERENCE from the book figure. **It is a schedule, not a second ledger** — Egypt files single-book, so nothing posts. **Confirm the rates per class (🔑).** | ✅ | |
| A6.2 | Do you need **fixed-asset depreciation run automatically**, and per-employee **payslips**? | Both exist (monthly depreciation cron + bilingual payslip PDFs) | 🟡 | |

### A7 · Deposits, cheques, advanced billing

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| A7.1 | Do tenants pay via **post-dated cheques**? Hold **security cheques** separately from payment cheques? | 🧑‍💻 **PDC register built (module 33)** — status lifecycle, bulk series lodging, maturity dashboard, GL posting. **The security-cheque half is NOT expressible:** `post_dated_cheques` has no purpose/class column, so a security cheque is only distinguishable by a note. **Code can answer it — a column, a filter and a rule that it never auto-clears (XS).** | 🟠 | |
| A7.2 | Is the **security deposit** a pure liability (no VAT)? Refundable at exit minus deductions (unpaid rent, damages, restoration, cleaning)? | Liability, refundable; deposit ledger exists | 🟡 | |
| A7.3 | Do you track **prepaid rent / advances** separately from the deposit? | 🟡 **Partly.** Money received with no invoice sits as an on-account **tenant credit** (its own GL source, `unearned_revenue`), which is separate from the deposit ledger. What is not modelled is recognising prepaid RENT over the period it covers — same gap as A3.3. | 🟠 | |
| A7.4 | **Multi-currency:** any leases billed in **USD/EUR**, and how is FX handled? *(Q-F)* | ⚙️ **DECIDED 2026-08-20 (EG-07): EGP only, and enforced** — `ValueSets` refuses a non-EGP value on `vendor_contracts.currency` and `assets.currency`; the picker is gone. **If a lease really is USD-indexed, the recommended answer is EG-31 — index the escalation, denominate in EGP (🧑‍💻 M)** — not full multi-currency. | 🔑 | |
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
| A9.2 | **Marketing levy (5%)** — book as **revenue** (today: `marketing_revenue`, billed as an invoice line) or as a **restricted marketing fund / liability**? And is it shown on the tenant invoice? | ⚙️ **Configuration, with one caveat.** A charge code picks its posting role from ALL 52 roles — liabilities included — and the invoice journalizer credits whatever role it names, so booking the levy to a liability is a screen change. **The caveat:** there is no dedicated *marketing fund* liability role, so you either reuse one or add a role + account mapping (🧑‍💻 XS). | 🔑 | |
| A9.3 | **CAM** presented **gross** (recovery revenue + pooled expenses booked separately) or **net**? Confirm the GL treatment, not just the pool contents (C2.1). | Gross | 🟡 | |
| A9.4 | **Inventory valuation method** — FIFO / weighted-average / standard cost? | Per-movement unit cost | 🟡 | |
| A9.5 | Accrue **end-of-service & leave provisions monthly** (accounts 22201001 / 22201002 exist)? | 🧑‍💻 See A5.1 — the gratuity figure is computed and reported, and posts nothing. Leave provision is not computed at all. **Both are code, and both wait on your entitlement ruling first.** | 🟠 | |
| A9.6 | **Fixed-asset useful lives / depreciation rates per class** and **salvage value**? | Straight-line, per-asset params | 🟡 | |
| A9.7 | Separate **cash/bank account per mall**, or shared? Any specific **numbering series** for journals/invoices? | ⚙️ **Both answered.** `bank_accounts` per property, and since EG-12 (2026-08-22) every one of the thirteen journalizers resolves the account through `MoneyAccount::for()` — document's own bank account → the rail's account → the posting role — so two banks no longer share one chart account. Numbering: prefixes AND the reset rule (`never` · `annual` · `monthly`, default continuous) are settings since EG-10. | 🔑 | |
| A9.8 | Need a **WHT report (Form 41)** and a **salary-tax report** alongside the VAT-output report? | ✅ **Form 41 BUILT (EG-21, 2026-08-22)** — quarterly off the FISCAL year, per registration, with a per-supplier certificate PDF and a tie-out between what was deducted and what the ledger owes. **A salary-tax return is the one still missing (🧑‍💻 S).** | 🟠 | |

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
| B.6 | Does Eltizam **remit net funds to the owner** (how often), and what is **deducted first** (fee, paid expenses, CAM, taxes, reserve)? *(Now also applies at UNIT level — see section B2.)* | ✅ **Owner Statements + Disbursements are BUILT (module 32).** Per-property, per-period statement runs (opening → collections → expenses → net) with a three-tier accrual GL spine, finalise/send lifecycle and PDF. **What is still missing is the management FEE** — deferred in v1 pending B.4 — so a statement shows net-before-fee. Answer B.4 and the fee line can be switched on. | 🟠 | |
| B.7 | Does Eltizam hold a **reserve/float per property** (starting amount + replenishment)? | Not tracked | 🟠 | |
| B.8 | Is each mall a **separate legal entity / set of books**, or all **consolidated** under Eltizam? Any **inter-company** transactions to record? | Single-company GL, property-dimensioned | 🟠 | |
| B.9 | Should the owner **see financial statements/disbursements** or stay **oversight-only** (current)? Does the owner **approve** anything before Eltizam acts (budgets, big expenses, new leases)? | Oversight + requests only | 🟠 | |

---

## B2 · Unit owners (مُلّاك الوحدات) — module 37

> **These three are the ONLY thing left in the unit-owners feature.** Everything else is built and
> tested: an owner is recorded, billed his صيانة monthly, aged, dunned, posted to the GL, carries his
> CAM share, can let his own unit, sees his account in the portal, and can be transferred out with a
> resale certificate. Phase 5 — the operator's management fee and the cash-basis owner statement —
> **cannot be finished until B2.1 and B2.2 are answered**, because the posting would otherwise go to
> an account somebody guessed.
>
> Related: B.4 asks the same fee question at PROPERTY level. If the answers differ (a % of the
> property's net vs a % of one unit's collected rent) say so — they are two different agreements and
> the system can hold both.

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| B2.1 | When Eltizam lets a **sold unit on its owner's behalf** and keeps a fee — **which GL account does that fee income post to?** It is the operator's revenue, not the property's, so it may not belong with rent. | ⛔ **Blocks phase 5.** The fee % and its basis (collected vs billed rent) are already configurable per ownership; only the posting account is missing. | 🔴 | |
| B2.2 | Is there a **sinking / reserve fund (صندوق صيانة)** collected from unit owners for future capital works? If yes, **which liability account** — it is money held for a future obligation, **not revenue**. | ⛔ Not collected. The system can bill it as a charge code the moment it has an account; shipping it as revenue would overstate income and understate a liability. | 🔴 | |
| B2.3 | The **صيانة an owner pays** — is it Eltizam's revenue for running the common areas, or **cost recovery** that should NOT appear as income on the property owner's statement? | Treated as property revenue, like a tenant's service charge (it posts through the same charge code). | 🟠 | |
| B2.4 | On a **resale**, does Eltizam approve the buyer or hold a right of first refusal? | No approval step; the transfer is recorded when the operator records it. Arrears are refused unless transferring over them deliberately, and the resale certificate states the figure. | 🟡 | |
| B2.5 | For an owner assessed on **purchase value**, what is the denominator — the summed purchase prices of the **sold units**, or of the **whole building**? The second is only defined when every unit is sold, and silently becomes wrong the day one is let. **Today: the sold cohort.** The purchase-value owners keep the share of the pool their AREA gives them collectively and re-cut that share among themselves by price, so Σ over the cohort is unchanged and no leased neighbour moves — the same principle F-08 settled for a stated lease share. If the operator's contracts mean the other reading, it is a change in `CamReconciliationService::purchaseValueShares()` and nowhere else. | Cohort of sold units, area-neutral in aggregate | 🟡 | |

---

## C · Eltizam operations

### C1 · Leasing & tenant operations

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| C1.1 | **Unit types** (Shop, Kiosk, F&B, Office, Clinic, Service, Storage, ATM…) and **statuses** (Vacant, Occupied, Under Maintenance, Reserved) — confirm the lists you actually use. | 🟡 Types/statuses are free-form (confirm the canonical set). **The occupancy map IS built** — a per-floor visual grid of every unit with its status and tenant. | 🟡 | |
| C1.2 | **Standard lease duration** + typical mix? Do leases **auto-renew**, and do charges + escalation carry over on renewal? | Renew/escalate actions exist | 🟡 | |
| C1.3 | **Annual escalation** rule — fixed % (7% default) or index-linked? | Fixed % via `escalate` | 🟡 | |
| C1.4 | **Early termination** — penalty (X months), notice period, deposit forfeited? | `terminate` action exists | 🟡 | |
| C1.5 | **Rent-free / grace / fit-out** periods at lease start? **One-off charges** (fit-out, key money, signage, parking, storage, fines)? | ✅ **Grace RESOLVED 2026-07-19:** `leases.fit_out_months` — a **FULL** grace (rent + service + CAM + marketing levy all suppressed) for that many whole months from the commencement month; billing starts after. Whole-month grace (no mid-month proration of the tail). *One-off charges still via ad-hoc Charge rows — separate item.* | 🟢 | |
| C1.6 | For **percentage rent**, how do tenants **report sales** (POS, manual monthly declaration, audited)? Do you **audit** them and what if under-reported? | Manual sales-declaration flow exists | 🟡 | |
| C1.7 | **Multiple contacts per tenant**? Track **tenant insurance certificates** + expiry? | ✅ **Both built — the row was stale.** Multi-user portal accounts (only `is_admin` may write), and `tenant_documents` with an insurance-COI type, expiry dates and a scheduled expiry scan (`tenants:scan-document-expiry`), private-disk media. | ✅ | |
| C1.8 | Need a **lease/contract PDF** + signature tracked in-system? | Not generated | 🟠 | |
| C1.9 | **Final month of an expiring lease — full month or pro-rata?** A lease ending on the **10th** is billed the **whole month**. Proration keys on *commencement* only, so a mid-month move-IN is prorated but a mid-month move-OUT is not. On EGP 30,000/month that is **30,000 billed vs 9,677 pro-rata — a ~20,300 difference on every departing tenant.** If the lease says the tenant pays for occupied days, this is over-billing; if it says rent is due for any month in which the term runs, today's behaviour is correct. **CORRECTED 2026-08-11 — this is no longer unremedied over-billing.** `CreditUnearnedBillingService` now credits the unearned portion at move-out, using `MonthlyBillingService::monthsCovered()` — the same rule the invoice billed on, so the bill and the credit cannot disagree. The question narrows to whether the tenant is ENTITLED to that credit, or whether the lease makes rent due for any month the term runs into. *(Found 2026-07-29 in the module-05 close-out; pinned by `ManualBillingEligibilityTest`.)* **NARROWED AGAIN 2026-08-23 (EG-29):** the figures quoted here assume `actual` days. The method is now the lease's to state — a lease on `thirty_day` credits 30,000 − (10 ÷ 30 × 30,000) = 20,000 rather than 20,323, and one on `whole_month` credits nothing at all because the month is earned in full. So the remaining question is purely the entitlement, and the arithmetic follows whichever clause the lease carries. | Bills the full month, **credits the unearned part on move-out** | 🟠 | |
| C1.10 | **A tenant who stays past the lease end date — do they keep paying, and at what rate?** | ⚙️ **Stale as written — it IS billable.** Converting the lease to holdover stamps `holdover_from` (what keeps the billing run going past expiry) and `holdover_rate_pct`, defaulting to `BillingSettings::holdover_default_rate_pct` (150%); EG-40 made every derivation from the rate honour the uplift. An UNCONVERTED overstay is alerted and unbilled. **The only decision left: should conversion be automatic on expiry, or always an operator's act (it is an act today, deliberately).** | 🔑 | |

### C2 · CAM, utilities & maintenance

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| C2.1 | What's in the **CAM expense pool** (security, cleaning, common power/water, M&E, insurance)? Are **vacant units carried by the owner**? Reconciliation frequency (annual)? | Pool + annual true-up exist | 🟡 | |
| C2.2 | Utilities recharged with a **markup** or **pass-through at cost**? Any **min/cap** on service charge or annual increase? | Pass-through; no cap | 🟡 | |
| C2.3 | **SLA targets** (e.g. urgent within 4 hrs) — confirm the numbers the breach scan alerts on. | ⚙️ Settings (`SlaSettings`), and since EG-08/EG-38 a **working calendar** exists too — Egypt's Fri–Sat weekend, a holidays register, Ramadan short days, per property. It **ships off**: `sla_working_clock_priorities` is empty, so every clock still runs on bare hours until you say which priorities are office work. | 🔑 | |
| C2.4 | **SLA penalties (FR-CM-08):** treated as a **cost reduction** (Dr AP / Cr the expense, no VAT) — right, or **other income**? Those costs flow into CAM tenants reimburse, so a penalty's saving reaches tenants automatically — **is that intended**, or should the mall keep it? | Cost reduction, benefit reaches tenants | 🟠 | |
| C2.5 | **Recharge tenant-caused repairs at all?** If yes: VATable or cost recovery? Parts only or parts+labour+vendor invoice? What if the cost changes after? Can the tenant dispute (→ credit note or void)? | ❌ We record responsibility but **cannot bill a tenant for a repair** — the FRD never asks us to | 🟠 | |
| C2.6 | **Approval bands (FR-CM-11):** are **1,000 / 10,000 EGP** the right thresholds (supervisor / manager / senior)? Does a large spend need **more than one** approver (a chain, not a level lookup)? | These bands; single-level lookup | 🟡 | |
| C2.7 | **Externally-bought part (FR-CM-09):** must a **vendor bill** back it before the job can close, or is the work-order record a memo with accounting entering the bill independently? | Recorded on the WO; posts nothing; nothing requires a bill | 🟠 | |

### C3 · Inventory / procurement / workflows

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| C3.1 | **Multi-location warehousing** — multiple warehouses per mall? **Bins/shelves** within a warehouse? | A warehouse is the finest grain | 🟠 | |
| C3.2 | **Inter-mall stock transfers** in scope? (Note: transfer types exist as scaffolding but **nothing creates them** — looks shipped, isn't. They also move value between two properties' books.) | Not built | 🟠 | |
| C3.3 | What is the **3rd warehouse/inventory category**? *(Q-C)* | ⚙️ **Not a question for us:** `warehouses.category` is a free-form label — name as many as you like on the screen. | 🔑 | |
| C3.4 | **Low-stock alerts** wanted at all, and is **one reorder level per item** enough (vs per-property)? | Daily bell alert, per mall, one number per item | 🟡 | |
| C3.5 | Does **procurement approval follow the same price bands** as spare parts? Confirm the FRD's own open item. | Defaulted to identical bands | 🟡 | |
| C3.6 | Exact **approval chain for department requests/payments routed through Accounting**? *(Q-E)* | Not defined | 🟠 | |
| C3.7 | **"Personal accounts" (محسوبات شخصية)** — who exactly (staff? related parties?) and what for? *(Q-B)* | 🟡 **Two thirds exist:** custody (عهدة) with its own transactions and settlement, and employee advances with repayments — both posting to the GL. What does not exist is a per-PERSON sub-ledger beyond those. **Still needs your one sentence on who and what for.** | 🔑 | |
| C3.8 | For each **service**: billed out (chargeable) or absorbed as a unit expense? Confirm the annual-report format. *(Q-D)* | Not distinguished | 🟠 | |
| C3.9 | Which actions need **approval before they take effect** (new lease, discount, write-off, refund, large expense, invoice cancellation/credit note)? Who holds **delete/void/cancel** authority — only super-admin? | Approval ladder for procurement/parts; delete = super-admin only | 🟡 | |

### C4 · Notifications, go-live & training

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| C4.1 | What events trigger **tenant** notifications and **staff** alerts? **Reminder** cadence (days before due / overdue intervals)? Need **WhatsApp / SMS** + branded email templates? | Email + push (FCM built, not live); no WhatsApp/SMS | 🟡 | |
| C4.2 | **Target go-live date**, parallel-run period, and the **client-side data-validation owner**? | Undefined | 🔴 | |
| C4.3 | **Training** (on-site / remote / video) and for which roles? | Undefined | 🟡 | |

**Staff access & alerting — added 2026-07-29 (found while closing out the dashboard + RBAC work):**

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| C4.10 | **Should a role's authority differ per property?** Today a role is portfolio-wide: a `manager` is a manager at *every* property they are assigned to. Yardi/MRI/Entrata scope the role per property (manager at Mall A, viewer at Mall B). We deliberately did **not** build that — with 2 properties and **zero** staff assigned to both, it has no expressible case yet, and it reworks the layer above property isolation. **The trigger to revisit: the first time one person is assigned to both malls, does their authority need to differ?** | Role is global; property assignment is a separate list | 🟡 | |
| C4.11 | **Which roles must have two-factor authentication, and when do we switch it on?** The mechanism is built and tested (it was found enforcing on *nobody* — including `super_admin` — and fixed 2026-07-30). **It is currently switched OFF by the operator's decision**, because enabling it marches every listed role through TOTP enrolment at their next login: a rollout to schedule with staff, and one that would block the people doing pre-go-live data validation. `manager`, `accounting`, `leasing`, `operations` and `hr` therefore handle payments and tenant data with **no second factor** today. **Two decisions needed:** (1) which roles — the recommended list is every role that can move money or change a tenancy (`SecurityDefaults::FORCE_2FA_ROLES`, 9 roles); (2) **when** — pick a date, tell staff to have an authenticator app ready, then set `SECURITY_FORCE_2FA_ROLES`. Until then `php artisan atriom:health` reports the production deploy as unhealthy, by design. | **Off** — opt-in via `SECURITY_FORCE_2FA_ROLES` (empty) | 🟠 | |
| C4.12 | **A user with no property assigned — should they see nothing, or everything?** The two layers currently disagree: query scoping treats "no assignment" as *unrestricted* (single-mall back-compat), while the panel refuses entry to every property. The result was an account that could not open any page. We fixed the symptom by assigning the demo auditor, but the **policy** is unanswered: is an unassigned account a misconfiguration (see nothing) or a portfolio account (see all)? | Contradictory; fixed by assigning everyone | 🟠 | |
| C4.13 | **Should a technician be emailed when work is assigned to them?** Assignment notifications are **in-app only**. The five alerts with a deadline (both SLA breaches, vendor certificate expiry, contract notice, ledger-sync failure) now also email; work assignment deliberately does not, because mailing everything trains people to ignore the alerts that matter. If technicians do not sit in the app, this is the one to add. | Bell only | 🟡 | |

---

## D · ETA / tax registration & IT

| # | Question | What we do today | | Answer |
|---|---|---|---|---|
| D.1 | **ETA e-invoicing go-live** — credentials, signing certificate, activity code, item codes. | ⏸️ **FROZEN IN CODE 2026-08-22** (`Modules::FROZEN`): the module answers *disabled* before any settings row is read, and every surface that made an unfinished integration look finished was removed. **Not a question to ask, and not work to schedule, until the freeze is lifted deliberately.** | ⏸️ | |
| D.2 | **Paymob** card payments — activate now or later? (Built, currently off.) | Off | 🟡 | |
| D.3 | **ETA receiver address per tenant** — governorate/city fields. | ⏸️ Same freeze as D.1. | ⏸️ | |
| D.5 | **Where do live Paymob credentials live, and who rotates them?** The 4 live keys sit in plaintext `.env` with no vault and no rotation procedure. **A leaked HMAC secret lets someone forge a "paid" callback** — i.e. mark invoices settled without money arriving. Needs a decision on secret storage before the live cutover, not after. | Plaintext `.env` | 🟠 | |
| D.6 | **Is the app reachable ONLY through the reverse proxy?** We now trust `X-Forwarded-*` from any proxy (`TRUSTED_PROXIES=*`), which is what makes login throttling and the audit trail see the real client IP instead of the proxy's. That is safe **only** if nothing can reach the app directly — otherwise a caller can forge `X-Forwarded-For` and become un-throttleable. If the app has a directly-reachable address, give us the proxy IPs to pin. | Trusts any proxy | 🟠 | |
| D.4 | **Hosting** — cloud SaaS (we manage) or on-prem? Do they have an **IT team**? **Backup/DR** expectations? Need **2FA**? Account lifecycle when someone leaves (deactivate vs delete)? | Cloud-ready; 2FA built but switched off — see C4.11 | 🟡 | |

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
- Everything **🟠** stays unbuilt (notably: no tenant-repair recharge, no owner money-flow, no per-tenant tax exemption, no tenant-side withholding). *(Corrected 2026-08-23: the cheque register and bank reconciliation are BUILT — see the verification pass.)*
- Everything **🔴** is the risk: Section A1 are the numbers the billing engine computes from, and most are unconfirmed assumptions; **A5.1 (employer social insurance/gratuity)** may mean the books are wrong today; **D.1 (ETA)** blocks legal e-invoicing; and **C1.8 (final-month proration)** over-bills every departing tenant by up to a month's rent if the lease says otherwise. Sign these off before the first real invoice.

## Sign-off

| Section | Owner | Date |
|---|---|---|
| A (finance / tax / GL / payroll / assets) | *accountant* | |
| B (owner money-flow) | *owner (Jawad) + Eltizam finance* | |
| C (operations) | *Eltizam operations lead* | |
| D (ETA / IT) | *Eltizam IT + tax registrant* | |
| E (requirement clarifications) | *Eltizam operations lead* | |

**Related:** [BUSINESS-RULES.md](BUSINESS-RULES.md) (every rule + risk level) ·
[discovery/client-discovery-questionnaire.md](requirements/CLIENT-DISCOVERY-ANSWERS.md) (answers
already collected).
