# Atriom — Business Rules & Assumptions Register

**For sign-off by: the operator (Eltizam) and their accountant / tax advisor.**
**Status: DRAFT — not yet certified for live money or tax filing.**

---

## What this document is

This is a plain-language list of **every business and financial rule the Atriom system enforces today** — VAT treatment, rent formulas, late fees, marketing levy, CAM reconciliation, credit notes, and the values built into the software. It exists so a finance / tax person can confirm each rule matches your **actual lease contracts** and **Egyptian law** *before* the system is used for real invoicing and tax filings.

You do **not** need to read any code. Each rule is described in business terms. A short code reference is given in parentheses or as a footnote only so an engineer can find it if you ask for a change.

### How to use this document

1. **Start with the red section** — [🔴 Top open questions to confirm first](#-top-open-questions-to-confirm-first). These are the highest-risk assumptions that, if wrong, cause incorrect tax or incorrect billing.
2. **Then read [⚠️ Configuration & integrations NOT yet certified](#️-configuration--integrations-not-yet-certified)** — these are things that are switched OFF or in test mode and must be addressed before go-live.
3. Go through each domain table below. Within every table, **HIGH-risk rules are listed first.**
4. For each rule, write your decision in the **Confirm?** column:
   - **✅ Confirm** — the rule is correct as written, no change needed.
   - **✏️ Change** — the value or behaviour is wrong; note what it should be.
5. Sign the [Sign-off](#sign-off) block at the end when the review is complete.

**Risk legend:** 🔴 **HIGH** = wrong value causes wrong tax owed, wrong amounts billed, or a legal/compliance breach. 🟠 **MEDIUM** = wrong value causes disputes, rework, or minor mis-billing. 🟡 **LOW** = cosmetic, operational, or audit-trail detail.

> **Verifying the numbers:** once you've confirmed the *rules* below, confirm the *figures* tie out by running `php artisan billing:reconcile` (optionally `--month=YYYY-MM`). It independently re-derives the receivables from source records and prints control totals (invoiced / collected / credits / outstanding AR / VAT) for you to reconcile against your own books.

**"Configurable?"** tells you whether a value can be changed by an admin in the settings screen (no developer needed), or whether it is fixed in code (a developer must change it).

---

## ✅ Decisions taken from the Yardi standard (2026-08-09)

Standing instruction: **where a business rule is genuinely uncertain, follow Yardi Voyager
Commercial rather than guessing or waiting.** These were open; they are now decided, with the basis
stated so they are not re-litigated.

| Was open | Decided | Basis |
|---|---|---|
| Percentage-rent basis for NEW leases | **Annual (cumulative year-to-date)** — the form default flips from `monthly` | Yardi accrues percentage rent on cumulative YTD sales against an annual breakpoint, settled over the year. A monthly basis charges overage in a strong month that a weak one should have absorbed, so a seasonal tenant pays more across the year than their clause says |
| Straight-line rent (RA-01) | **Build it, ship it switched OFF** — no longer "waiting for the accountant" | Yardi straight-lines commercial rent as standard. The accountant's remaining job is to flip a switch against a before/after they can read, not to authorise the work |
| Does a rent RELIEF abate the marketing levy? | **No** | Yardi abates per charge code: an abatement on the rent code does not touch a separate promotional-fund code |
| Which cap scope, denominator, gross-up and estimate bases are "right" | **Configurable, defaulted to the legacy behaviour** (RC-01/03/04/05/07) | Yardi makes all four per-pool settings rather than conventions. Existing pools keep the basis they were reconciled on |

**Two things Yardi cannot decide, and neither should be assumed:**

- **Which basis each of the 24 EXISTING percentage-rent leases is on.** That is a fact in each
  signed contract, not an industry convention. They stay on `monthly` until someone reads the
  clauses — changing them would restate what tenants are billed on a guess. The lease list now has a
  **Percentage-rent basis** filter so pulling that review list is one click.
- **Jawad's chart of accounts.** An Egyptian statutory and entity-specific artifact.

**And a whole class Yardi is simply not evidence for: Egyptian tax.** Questions 1–5, 7 and 10 below
are VAT rates, exemptions and ETA readiness. A US/UK property system has no standing on whether
base rent is VAT-exempt under Egyptian law. **Those still need the accountant**, and following Yardi
there would be worse than admitting the gap.


## 🔴 Top open questions to confirm first

These are the ten highest-risk assumptions. If any answer is "no", stop and flag it before go-live.

1. **Is 14% the correct VAT rate** on service charges, utilities, and parking — and is **base rent genuinely VAT-exempt** under current Egyptian law? (The whole VAT calculation depends on this.)
2. **Is percentage rent VAT-exempt?** The system charges **0% VAT** on percentage rent. If it should be taxed, every percentage-rent invoice is under-charging VAT.
3. **Are CAM reconciliation (true-up) charges VAT-exempt?** The system applies **0% VAT** to them. This is an *unverified* assumption flagged by us.
4. **Are late fees VAT-exempt?** The system treats late-fee penalties as **0% VAT**. Confirm penalty interest is outside VAT.
5. **Marketing levy VAT.** The levy is 5% of base rent (base-rent only) and — **operator-confirmed 2026-07-19** — **IS billed to the tenant** as a line on the monthly invoice (a "marketing fund contribution"), with the property marketing budget accruing FROM that billed line. **Open:** it currently carries **0% VAT** (mirroring rent's exemption). Since it is a billed marketing/promotion *service*, should it instead be **14% VAT** (a taxable supply)? Accountant sign-off needed.
6. **Is CAM allocated pro-rata by leased area (square metres)?** Does your lease wording actually say "by area"? (Some leases allocate by turnover or a fixed share.)
7. **Late-fee policy:** is it **2% of the outstanding balance, minimum 50 EGP, charged once (not compounding), after a 7-day grace period**? None of these four numbers is backed by a documented legal source — they are business-policy defaults.
8. **Default security deposit = 3 months' rent** and **default annual escalation = 7%** — are these your real contract defaults? Both are *unverified assumptions* baked into new leases.
9. **Is the artificial-breakpoint formula the correct default for percentage rent** — `(sales − threshold) × rate`? Leases with no calculation type set will silently use this. If your leases use the natural breakpoint, percentage rent is wrong.
10. **Is the default payment term 7 days** from invoice issue date, and is the ETA e-invoicing setup (tax IDs, item codes, issuer identity) ready — noting it currently runs in **test/mock mode** and is **not certified** (see next section)?
11. **SLA penalties (FR-CM-08): are they a cost reduction, and does the benefit reach tenants?** See *Vendor SLA Penalties* below. Two questions in one, and the second decides **who gets the money**.
12. **Approval thresholds (FR-CM-11): are 1,000 / 10,000 the right bands?** See *Approval Ladder* below. The FRD gives no numbers — these are our defaults, and they decide who may authorise a spend.
13. **Externally-bought parts (FR-CM-09): must a vendor bill back the record?** See *Externally-bought spare parts* below. Until answered, a job's parts cost is an operational figure, not a GL one.
14. **Does the operator want to recharge tenant-caused repairs at all?** See *Recharging a repair to a tenant* below. We now record who is responsible; we deliberately do **not** bill them, because the FRD never asks us to.
15. **Does procurement approval follow the same price bands as spare parts?** The FRD's own open item — see *Procurement approval hierarchy* below. We defaulted to yes; it is configuration either way.
16. **Are low-stock alerts wanted at all, and is one reorder level per item enough?** FR-INV-03 is the FRD's own *recommended addition — confirm with client if desired*. Built (bell-only, behind the module flag) because an alert cannot do harm. But the threshold is **one number per item, applied per mall** — if a flagship mall should carry more than a small one, the level needs a property dimension, which is a migration.

---

## ⚠️ Configuration & integrations NOT yet certified

These are confirmed facts about the current deployment. **Each one must be resolved before processing real money or filing real tax.**

| Area | Current state | What it means for go-live |
|---|---|---|
| **ETA e-invoicing (Egyptian Tax Authority)** | Runs in **MOCK mode by default** (`ETA_MOCK=true`). The system returns a fake "accepted/Valid" response and does **not** send anything to the real tax authority.¹ | **No invoice has ever been submitted to the real ETA.** Real submission is **not certified**. Item codes (EGS), the taxpayer activity code (6820), and issuer identity are placeholders pending your ETA registration. Must be configured and tested against ETA's live system before any tax filing relies on it. |
| **Paymob card payments** | **DISABLED** (`PAYMOB_ENABLED=false`, no credentials).² | Online card capture has **never been run end-to-end live**. Tenants cannot pay by card until Paymob is enabled and credentials are loaded. |
| **Email delivery** | Mail driver is **`log`** (`MAIL_MAILER=log`).³ | **No real emails are sent.** All "notifications" are in-app bell alerts only. Tenants and owners receive nothing by email until SMTP is configured. Late-payment reminders, invoice notices, etc. are not reaching anyone externally. |
| **Background jobs & scheduler** | `QUEUE_CONNECTION=database`.⁴ | A **queue worker AND the scheduler (cron) MUST be running in production**, or scheduled scans and notifications **silently never fire** — no late fees applied, no overdue scans, no SLA breach alerts, no CAM reconciliation, no monthly billing. This is an operational prerequisite, not a setting. |

¹ `config/eta.php:23`, `app/Services/Eta/EtaApiClient.php` · ² `config/integrations.php:17`, `.env.example:16` · ³ `.env.example:67` · ⁴ `.env.example:55`

---

## VAT & Tax Treatment

| Rule | Current value / formula | Configurable? | Assumption / basis | Risk | Confirm? |
|---|---|---|---|---|---|
| **VAT rate on service charges** | 14% VAT added to service charges by default. | Default fixed in code; can be overridden per individual charge. | Egyptian VAT law on services (documented in FRD + billing module). | 🔴 HIGH | |
| **Base rent VAT-exempt** | Base rent has **no VAT** (0%). | Fixed in code; per-charge override possible. | Egyptian law: base rent treated as VAT-exempt. | 🔴 HIGH | |
| **Utilities VAT** | Utility charges expected to be taxed at 14%, but the **default is not explicitly forced** in code — relies on per-charge setting. | Per-charge. | *Unverified* — docs say utilities are taxed; default not hard-set. | 🔴 HIGH | |
| **Parking VAT** | Docs say parking is taxed at 14%, but implementation **allows it to be set VAT-exempt**. Default unclear. | Per-charge. | *Unverified* — conflict between docs (taxed) and config (overridable). | 🔴 HIGH | |
| **Percentage rent VAT** | **0% VAT** on percentage rent (always). | Fixed in code. | *Unverified* — assumed exempt like base rent. | 🔴 HIGH | |
| **CAM true-up VAT** | **0% VAT** on CAM reconciliation charges. | Fixed in code. | *Unverified* — assumed non-taxable settlement. | 🔴 HIGH | |
| **VAT per-item formula** | VAT per line = amount × (rate ÷ 100), rounded to 2 decimals. Invoice total = sum of amounts + sum of VAT. | Fixed in code. | Standard accounting; tested. | 🔴 HIGH | |
| **Marketing levy VAT** | **0% VAT** on the (tenant-billed) marketing levy. | Fixed in code. | ⚠️ **Open for accountant** — the levy is BILLED to tenants (not internal), so it may be a **14% taxable service**, not exempt like rent. Reconfirm at sign-off. | 🟠 MEDIUM | |
| **Late-fee VAT** | **0% VAT** on late fees. | Fixed in code. | *Unverified* — penalty interest assumed exempt. | 🟠 MEDIUM | |
| **VAT rounding precision** | All VAT rounded to **2 decimal places** (piastres). | Fixed in code. | *Unverified* against ETA rounding rules. | 🟠 MEDIUM | |
| **VAT for ETA submission** | Amounts sent to the tax authority rounded to **5 decimal places**; net amount excludes VAT, total includes it; tax type code **T1 / V009** (standard-rate VAT). | Fixed in code. | ETA API spec — **not yet certified live** (see warning section). | 🔴 HIGH | |

*Code references: `LeaseCreationService.php:102,117-118`; `Charge.php:57`; `InvoiceItem.php:36`; `MonthlyBillingService.php:176-196`; `EtaJsonBuilder.php:62-117,143-146`.*

---

## Leases

| Rule | Current value / formula | Configurable? | Assumption / basis | Risk | Confirm? |
|---|---|---|---|---|---|
| **Default security deposit** | **3 × monthly base rent** when not specified. | Fixed default; can be overridden on the lease form. | *Unverified assumption* — no law/contract cited. | 🔴 HIGH | |
| **Default annual escalation** | **7% per year** (fixed-percent type). | Fixed default; manual override 0–100% on form. | *Unverified assumption* — "typical" but no source. | 🔴 HIGH | |
| **Service-charge VAT on new leases** | 14% VAT applied to the service-charge line. | Fixed in code at lease creation. | Egyptian VAT law. | 🔴 HIGH | |
| **Base-rent VAT exemption on new leases** | Base rent created VAT-exempt. | Fixed in code. | Egyptian VAT law. | 🔴 HIGH | |
| **Default percentage-rent type** | **Artificial breakpoint** = `(sales − threshold) × rate`, floored at zero. | Fixed default; form allows natural breakpoint. | Business choice (module docs). | 🔴 HIGH | |
| **Natural-breakpoint formula** | `(sales × rate) − base rent`, floored at zero. | Fixed formula, used when type = natural. | Business design (docs). | 🔴 HIGH | |
| **Fit-out / rent-free grace** | `rent_commencement_date` on the lease → until that month **NOTHING bills** (rent + service + CAM + marketing levy — a **full** grace). Billing starts the month after. Default **0** (no grace); does **not** carry on renewal. | Per-lease (0–24 months). | **Operator-confirmed 2026-07-19** (OPEN-QUESTIONS C1.5): a full grace, whole-month (no mid-month proration of the tail). | 🔴 HIGH | |
| **Billing frequency** | `billing_frequency` on the lease: `monthly` (default) / `quarterly` / `semiannual` / `annual`. Quarterly+ bill **in advance** — one invoice per cycle covering the whole cycle (rent + service + marketing levy, each monthly amount **× months-in-cycle**), issued on cycle-start months only. Cycles anchored to the **first billable month** (commencement + fit-out); full N-month cycles, except the **final cycle is capped at the expiry month** (never bills past lease end). Edit-locked after the first invoice. **Carries** on renewal. | Per-lease. | **Operator decision 2026-07-19:** commencement-anchored cycles, whole recurring stack billed together, revenue-at-issue (no straight-line spread — A3.2). | 🔴 HIGH | |
| **One active lease per unit** | A unit may have only **one active lease at a time**. | Fixed business rule. | Physical occupancy invariant. | 🔴 HIGH | |
| **Lease expiry date** | expiry = commencement + term months **− 1 day** (inclusive final day). | Fixed formula. | Standard inclusive end date. | 🔴 HIGH | |
| **Renewal rent** | On renewal, rent is **whatever the operator types** — escalation is **not auto-applied**. | Operator must enter new rent. | Business practice (manual decision). | 🔴 HIGH | |
| **Termination — partially-paid invoices** | On termination, only **fully-unpaid** invoices are auto-cancelled. **Partially-paid invoices are kept** and need a manual credit note. | Fixed rule. | Protects tenant payments from being orphaned. | 🔴 HIGH | |
| **Due date** | due date = issue date + payment-terms days. | Per-lease (default 7). | Business standard. | 🔴 HIGH | |
| **Default payment terms** | **7 days** from issue date. | Per-lease (0–120); global default in settings. | *Unverified* — business practice. | 🔴 HIGH | |
| **Default lease term** | **36 months** (3 years). | Fixed default; form allows 1–120 months. | *Unverified* — typical retail term. | 🟠 MEDIUM | |
| **Default status on creation** | New leases become **active immediately**. | Fixed default; form offers draft/pending. | Business process. | 🟠 MEDIUM | |
| **Rent change — only active/pending** | Rent can only be changed on active or pending leases. | Fixed rule. | Business logic. | 🟠 MEDIUM | |
| **Termination — only active/pending** | Only active/pending leases can be terminated. | Fixed rule. | Business logic. | 🟠 MEDIUM | |
| **Renewal commencement** | Defaults to the **day after** the old lease expires. | Overridable on renewal form. | Continuous-occupancy assumption. | 🟠 MEDIUM | |
| **Renewal term / service charge** | Default to the **original lease's** values unless changed. | Overridable on renewal form. | Preserve structure. | 🟠 MEDIUM | |
| **Default currency** | **EGP** (only). | Fixed in code. | Egypt-only deployment. | 🟡 LOW | |
| **Expiry warning thresholds (UI)** | 30 days = red, 90 days = orange. | Fixed in code. | Operator visual alert. | 🟡 LOW | |

*Code references: `LeaseCreationService.php`; `LeaseRenewalService.php`; `LeaseTerminationService.php`; `LeaseRentChangeService.php`; `Lease.php`; `PercentageRentCalculationService.php`.*

---

## Invoices & Accounts Receivable (AR)

| Rule | Current value / formula | Configurable? | Assumption / basis | Risk | Confirm? |
|---|---|---|---|---|---|
| **Balance formula** | balance = max(0, total − paid − credit applied), never negative, 2 decimals. | Fixed. | Documented billing rule. | 🔴 HIGH | |
| **Paid-amount formula** | paid = **captured payments only** + credit applied. Pending/failed payments do **not** count. | Fixed. | Documented; prevents credit erasure. | 🔴 HIGH | |
| **Status auto-transitions** | issued → partially_paid → paid / overdue, driven by balance & dates. Manual statuses (disputed / cancelled / credited) are **never** auto-overwritten. | Fixed. | Documented state machine. | 🔴 HIGH | |
| **Invoice total composition** | total = (sum of line amounts) + (sum of line VAT), each rounded to 2 decimals independently. | Fixed. | Prevents penny drift. | 🔴 HIGH | |
| **Billing eligibility** | Only **active** leases whose dates overlap the period are billed; terminated/future leases excluded. | Fixed. | Documented. | 🔴 HIGH | |
| **Charge window enforcement** | A charge bills only if the period overlaps its start/end dates. | Fixed. | Documented. | 🔴 HIGH | |
| **Billing idempotency** | Re-running monthly billing **never** creates a duplicate invoice for the same lease + month. | Fixed. | Documented; safe to re-run. | 🔴 HIGH | |
| **Overdue definition** | Overdue = status 'overdue', OR (issued/partially-paid AND due date in past AND balance > 0). | Fixed. | Documented. | 🔴 HIGH | |
| **Only captured payments allocate** | Initiated/authorised/pending/failed payments do **not** settle invoices. | Fixed. | Documented. | 🔴 HIGH | |
| **Credit applied tracked durably** | Applied credit notes are stored in a separate field so a later payment recompute can't erase them. | Fixed. | Documented AR-drift fix. | 🔴 HIGH | |
| **Default currency** | **EGP**. | Fixed default. | Egypt deployment. | 🔴 HIGH | |
| **Money precision** | All money stored to **exactly 2 decimal places**. | Fixed. | Banking standard. | 🔴 HIGH | |
| **Invoice number format** | `INV-{ASSET}-{YYYYMM}-{0001}`, unique, sequence resets monthly per property. | Fixed. | Internal convention — *check against any ETA numbering rule.* | 🟠 MEDIUM | |
| **Due date after issue date** | Due date must be strictly **after** issue date. | Fixed validation. | Prevents instant-overdue invoices. | 🟠 MEDIUM | |
| **Period end after start** | Invoice period end must be after start. | Fixed validation. | Prevents bad proration. | 🟠 MEDIUM | |

*Code references: `Invoice.php:178-206`; `MonthlyBillingService.php:41-100,194-196`; `Payment.php`.*

### AR Aging Buckets (collections report)

| Rule | Current value / formula | Configurable? | Assumption / basis | Risk | Confirm? |
|---|---|---|---|---|---|
| **Only open invoices counted** | Aging includes only issued / partially-paid / overdue invoices with balance > 0; excludes paid, cancelled, credited, disputed. | Fixed. | Documented. | 🔴 HIGH | |
| **Buckets sum balance, not total** | Each bucket totals the **open balance** of partially-paid invoices, not the full invoice total. | Fixed. | Documented. | 🔴 HIGH | |
| **Bucket boundaries** | Current (not yet due) · 1–30 · 31–60 · 61–90 · 90+ days overdue. | Fixed. | Documented. | 🟠 MEDIUM | |
| **Null due-date** | Treated as "current" (not yet due). | Fixed. | Documented. | 🟡 LOW | |

### Monthly close / revenue reports

| Rule | Current value / formula | Configurable? | Assumption / basis | Risk | Confirm? |
|---|---|---|---|---|---|
| **Payments in close window** | Only **captured** payments dated within the month. | Fixed. | Documented. | 🔴 HIGH | |
| **Collections rate** | (captured payments ÷ invoices issued) × 100, 1 decimal, zero-guarded. | Fixed. | Documented. | 🔴 HIGH | |
| **Invoices in close window** | Invoices with issue date within the calendar month (inclusive). | Fixed. | Documented. | 🟠 MEDIUM | |
| **Revenue by type** | Excludes cancelled & draft invoices. | Fixed. | Documented. | 🟠 MEDIUM | |
| **Credit notes in close** | Only issued / applied status. | Fixed. | Documented. | 🟠 MEDIUM | |

---

## Late Fees & Overdue Invoices

| Rule | Current value / formula | Configurable? | Assumption / basis | Risk | Confirm? |
|---|---|---|---|---|---|
| **Grace period** | **7 days** after due date before any fee. | Admin-settable (Billing settings) or env. | *Unverified* — business policy, no legal source. | 🔴 HIGH | |
| **Late-fee rate** | **2.0%** of the outstanding balance. | Admin-settable or env. | *Unverified* — business policy. | 🔴 HIGH | |
| **Fee formula** | fee = max(minimum, balance × 2% ), rounded 2 decimals, applied **once** per invoice. | Min/rate configurable; formula fixed. | Code implementation. | 🔴 HIGH | |
| **Flat, not compounded** | Charged **once** — never recalculated or accrued daily/monthly however long it stays overdue. | Fixed. | Code design. | 🔴 HIGH | |
| **Late-fee VAT** | **0% VAT** on late fees. | Fixed. | Likely exempt (penalty) — *confirm.* | 🔴 HIGH | |
| **Idempotency / locking** | Row is locked and re-checked so a fee is never applied twice. | Fixed. | Concurrency safety. | 🔴 HIGH | |
| **Adds to invoice total** | Fee increases the invoice's subtotal, total **and** balance (raises the legal amount owed). | Fixed. | Code design. | 🔴 HIGH | |
| **Minimum fee** | **50.00 EGP** floor. | Admin-settable or env. | *Unverified* — business policy. | 🟠 MEDIUM | |
| **Application time** | Computed daily at **04:00** (requires scheduler running). | Fixed schedule. | Operational. | 🟠 MEDIUM | |
| **Status set to overdue** | Invoice marked 'overdue' when a fee is applied. | Fixed. | Workflow. | 🟠 MEDIUM | |
| **Owner overdue alert** | Owners notified once per overdue invoice, daily scan at **06:00**. | Fixed schedule. | Operational. | 🟠 MEDIUM | |

*Code references: `LateFeeService.php:62-90`; `config/billing.php:14-16`; `routes/console.php:32-35`.*

---

## Proration & Charge Frequencies

| Rule | Current value / formula | Configurable? | Assumption / basis | Risk | Confirm? |
|---|---|---|---|---|---|
| **Day-count method** | Proration uses **actual calendar days** (31 in March, 28/29 in Feb) — **not** a flat 30-day month. | Fixed. | *Unverified* — no documented basis. | 🔴 HIGH | |
| **When proration applies** | Only when **opt-in** AND lease commences **strictly after** the 1st of the month. | Fixed; opt-in flag (off by default in UI). | *Unverified.* | 🔴 HIGH | |
| **Billing period** | Each invoice covers a **whole calendar month** (1st → last day). | Fixed (calendar months). | Documented. | 🔴 HIGH | |
| **Prorated invoice dates** | When prorating, period start, issue date and due date all shift to the **commencement date** (not the 1st). | Fixed. | Documented. | 🔴 HIGH | |
| **Quarterly cadence** | Bills **every 3 calendar months** from the start month, ignoring day-of-month (e.g. Jan 15 start → Apr, Jul, Oct). | Fixed. | Documented (FR BIL-3). | 🔴 HIGH | |
| **Annual cadence** | Bills in the **anniversary month** of the start date (or January if no start date). | Fixed. | Documented. | 🔴 HIGH | |
| **Charge window boundary** | A charge never bills outside its start/end dates. | Fixed. | Documented. | 🔴 HIGH | |
| **Proration factor** | factor = days billed ÷ days in period, **inclusive** of both endpoints (Mar 15–31 = 17 days), rounded to 4 decimals; charge × factor rounded to 2. | Fixed. | Documented (avoids under-counting). | 🟠 MEDIUM | |
| **One-time charge** | Bills exactly once, in the month its start date falls. | Fixed. | Documented. | 🟠 MEDIUM | |
| **Monthly billing day / time** | Runs on **day 1** at **02:00** (configurable). | Admin-settable / env. | *Unverified* — operational. | 🟠 MEDIUM | |
| **Monthly charge** | Included in every month within its active window. | Fixed. | Business logic. | 🟡 LOW | |

*Code references: `MonthlyBillingService.php:167-281`.*

---

## Marketing Levy

| Rule | Current value / formula | Configurable? | Assumption / basis | Risk | Confirm? |
|---|---|---|---|---|---|
| **Levy rate** | **5.0% of base rent** (the mall default). | Admin-settable (`/admin/settings`); **per-lease override** (`marketing_levy_rate`). | Documented (FR MKT-2) — industry norm 1–5%. | 🔴 HIGH | |
| **Per-lease opt-out** | A lease can turn the levy **off** (`has_marketing_levy = false`) — some tenants (anchors, kiosks, storage) negotiate out of it. Default is **on**, preserving today's behaviour. Turning it off deactivates the `marketing` charge (kept, not deleted, so prior history is intact); it stops appearing on future invoices. | Fixed in code; **operator request 2026-07-19**. | Standard commercial-leasing flexibility — the marketing fund contribution is negotiated per deal. | 🔴 HIGH | |
| **Per-lease rate override** | A lease can override the levy % (`marketing_levy_rate`); blank = the mall default. The override carries forward on renewal along with the opt-out. | Fixed in code. | Negotiated per deal. | 🔴 HIGH | |
| **Calculation basis** | Calculated on **base rent only** — excludes service charges, utilities, percentage rent, and VAT. | Fixed. | Documented (FR MKT-2/5). | 🔴 HIGH | |
| **Levy formula** | monthly levy = base rent × 5% ÷ 100, rounded to 2 decimals. | Rate configurable; formula fixed. | Documented. | 🔴 HIGH | |
| **Billed to the tenant** | The levy **IS a line on the tenant's monthly invoice** (a `marketing` charge = 5% of base rent). The property's marketing budget **accrues FROM the billed line item** (no double-count). | Fixed in code; **operator-confirmed 2026-07-19**. | Standard mall "marketing fund contribution" — tenants pay it on top of rent. | 🔴 HIGH | |
| **Levy charge VAT-exempt** | Marketing charge is VAT-exempt, monthly frequency. | Fixed. | Documented (mirrors rent). | 🔴 HIGH | |
| **Accrues on billed rent** | Each invoice run accrues 5% of the **actually-billed** base rent (so a prorated month accrues 5% of the prorated rent, not the full month). | Fixed. | Documented (FR MKT-5). | 🔴 HIGH | |
| **Accrual is cumulative** | Each cycle **adds** to the accrued amount (does not replace it). | Fixed. | Documented. | 🔴 HIGH | |
| **Spent amount auto-derived** | Budget's "spent" is recomputed from spend records on every save/delete/restore — never hand-edited. | Fixed. | Documented. | 🔴 HIGH | |
| **Soft-deleted spends excluded** | Deleting a spend is recoverable and immediately reduces the spent total. | Fixed. | Documented. | 🔴 HIGH | |
| **Rate versioning** | Changing the rate (e.g. 5% → 8%) affects **future** billing only; **past** charges/accruals stay at the old rate. | Rate configurable; behaviour fixed. | Documented + tested. | 🔴 HIGH | |
| **Overspend allowed** | A spend larger than the budget balance is **accepted** — balance goes negative with only a warning, not a block. | Form-layer; a hard cap can be added. | Documented design decision — **confirm this is intended.** | 🔴 HIGH | |
| **Accrual failure non-blocking** | If accrual fails, the invoice is still created; only a warning is logged. | Fixed. | Documented. | 🟠 MEDIUM | |
| **One budget per property per year** | Exactly one marketing budget per property per calendar year. | Fixed. | Documented. | 🟠 MEDIUM | |
| **Accrual idempotency** | Billing the same month twice accrues the levy only once. | Fixed. | Documented. | 🟠 MEDIUM | |
| **One marketing charge per lease** | Each lease has exactly one marketing charge (amount updates in place). | Fixed. | Documented. | 🟠 MEDIUM | |
| **Backfill re-runnable** | The catch-up command overwrites rather than adds, so it's safe to re-run. | CLI command. | Documented. | 🟠 MEDIUM | |
| **Spend categories** | Exactly 5: offer, promotion, event, printed_work, other. | Fixed enum. | Documented. | 🟡 LOW | |
| **Spend validation** | Negative/zero spends rejected at the form. | Form-layer. | Documented. | 🟡 LOW | |

*Code references: `MarketingLevyService.php`; `MarketingSettings.php:17`; `MonthlyBillingService.php:221-258`; `MarketingBudget.php`.*

---

## Percentage Rent (turnover rent)

| Rule | Current value / formula | Configurable? | Assumption / basis | Risk | Confirm? |
|---|---|---|---|---|---|
| **Artificial-breakpoint formula** | rent = (declared sales − threshold) × rate, floored at zero. | Threshold & rate per lease. | Documented + tested. | 🔴 HIGH | |
| **Natural-breakpoint formula** | rent = (declared sales × rate) − base rent, floored at zero. | Base rent & rate per lease. | Documented + tested. | 🔴 HIGH | |
| **Default when type missing** | If calculation type is blank, the system uses **artificial breakpoint**. | Fixed default. | Implementation default — **confirm.** | 🔴 HIGH | |
| **Never negative** | If sales are below threshold, owed = **exactly zero** (never a credit). | Fixed floor. | Documented invariant. | 🔴 HIGH | |
| **On/off per lease** | If "has percentage rent" is off, **zero** is always calculated regardless of sales. | Per-lease toggle. | Core logic. | 🔴 HIGH | |
| **Charge on lock = VAT-exempt** | When a declaration is locked and rent > 0, exactly one one-time, **0% VAT** charge is created, bounded to the declaration's period. | Fixed. | Documented. | 🔴 HIGH | |
| **Period scoping** | Each percentage-rent charge is bounded to its declaration's period, so voiding one period doesn't touch another. | Fixed. | Documented. | 🔴 HIGH | |
| **Lock idempotency** | Locking an already-locked declaration never double-charges. | Fixed. | Documented. | 🔴 HIGH | |
| **Re-lock after void** | Re-locking a disputed declaration creates a **fresh** charge; the old one stays deactivated. Only one active charge at a time. | Fixed. | Documented. | 🔴 HIGH | |
| **One declaration per lease per month** | A second declaration for the same lease + month is rejected. | DB constraint. | Documented. | 🔴 HIGH | |
| **Renewal carries config** | Renewing a lease copies the percentage-rent flag, threshold, rate, and type. | Fixed. | Documented bug-fix. | 🔴 HIGH | |
| **Only active leases may declare** | Declarations allowed only for active leases with percentage rent enabled. | Fixed. | Documented. | 🔴 HIGH | |
| **Void → disputed + audit** | Voiding a locked declaration deactivates the charge, sets status to disputed, and records who/when/why. | Fixed. | Documented. | 🔴 HIGH | |
| **Rate stored as percent** | e.g. 5.00 = 5%; converted to 0.05 in calculation; form limits 0–100. | Per lease. | Standard. | 🔴 HIGH | |
| **Threshold meaning** | The monthly sales level below which zero is owed (artificial only). | Per lease. | Documented. | 🔴 HIGH | |
| **Rounding** | Result rounded to 2 decimals after flooring at zero. | Fixed. | Currency standard. | 🟠 MEDIUM | |
| **Zero owed → no charge** | If owed = 0, the declaration still locks but no charge line is created. | Fixed. | Documented. | 🟠 MEDIUM | |
| **Charge soft-deactivated on void** | The charge is deactivated (kept for history), not deleted. | Fixed. | Documented. | 🟠 MEDIUM | |
| **Recalculate vs calculate** | "Recalculate" updates the displayed estimate only; "calculate" is read-only. Neither creates a charge. | Fixed. | Documented. | 🟠 MEDIUM | |
| **Lock notifies tenant** | Tenant notified on lock; lock succeeds even if the notification fails. | Fixed. | Documented. | 🟠 MEDIUM | |
| **Charge currency / type / frequency** | Always EGP, type 'percentage_rent', one-time. | Fixed. | System convention. | 🟠 MEDIUM | |

*Code references: `PercentageRentCalculationService.php`; `LeaseRenewalService.php:57-60`; `TenantSalesDeclaration.php`.*

---

## CAM Reconciliation (Common Area Maintenance)

| Rule | Current value / formula | Configurable? | Assumption / basis | Risk | Confirm? |
|---|---|---|---|---|---|
| **Allocation basis** | Each tenant's share = their **leased area (sqm) ÷ total leased sqm**. | Fixed formula; area is per-unit data. | Common international practice — **does your lease say "by area"?** | 🔴 HIGH | |
| **Allocated amount** | share × total **actual** CAM expense, rounded to 2 decimals. | Pool total configurable. | Derived from share. | 🔴 HIGH | |
| **Estimated paid** | share × total **estimated collected**, rounded 2 decimals. | Pool total configurable. | Mirrors allocation. | 🔴 HIGH | |
| **True-up** | allocated − estimated. Positive = tenant owes; negative = credit. | Fixed. | Reconciliation delta. | 🔴 HIGH | |
| **Scope** | Only **active** leases on the pool's property are included. | Fixed. | Only occupying tenants billed. | 🔴 HIGH | |
| **True-up VAT** | **0% VAT** on CAM charges. | Fixed. | *Unverified* — needs tax advisor confirmation. | 🔴 HIGH | |
| **Re-run safety** | Re-generating allocations **skips** any already billed — never resets them or double-bills. | Fixed. | Documented regression fix. | 🔴 HIGH | |
| **One pool per property per year** | Database enforces one CAM pool per property per calendar year. | Fixed. | Documented. | 🔴 HIGH | |
| **One allocation per lease per pool** | No duplicate tenant billing within a pool. | Fixed. | Documented. | 🔴 HIGH | |
| **Eligible pools** | Annual reconciliation runs only on draft/reconciling pools; skips reconciled/closed. | Fixed. | Documented. | 🔴 HIGH | |
| **Currency** | EGP. | Fixed. | Jurisdiction currency. | 🟠 MEDIUM | |
| **Negative true-up = credit** | Over-paying tenants get a negative charge (credit on next invoice). | Fixed. | Documented. | 🟠 MEDIUM | |
| **One-time charge** | True-up is a one-time annual charge, covering Jan 1 – Dec 31. | Fixed. | Documented. | 🟠 MEDIUM | |
| **Auto-bill default off** | The CLI command defaults to **review-only**; billing requires the `--auto-bill` flag. | CLI flag. | Safe default. | 🟠 MEDIUM | |
| **Rounding residual** | Up to ±0.01 EGP per pool from rounding indivisible shares. | Inherent. | Accounting tolerance. | 🟡 LOW | |
| **Reconciliation schedule** | Defaults to **15 January 03:00** for the previous year. | Admin-settable / env. | Typical timing. | 🟡 LOW | |

*Code references: `CamReconciliationService.php:25-166`; CAM migrations.*

---

## Credit Notes

| Rule | Current value / formula | Configurable? | Assumption / basis | Risk | Confirm? |
|---|---|---|---|---|---|
| **Lifecycle states** | draft → issued → applied → void. New notes start as **draft**. | Fixed enum. | Documented state machine. | 🔴 HIGH | |
| **Apply capping** | Amount applied = the **smallest** of: credit balance, invoice balance, and requested amount. | Fixed. | Documented. | 🔴 HIGH | |
| **Cannot void if applied** | A note with any amount already applied **cannot** be voided — reverse via an offsetting note instead. | Fixed. | Documented. | 🔴 HIGH | |
| **Void note can't apply** | A voided note applies as zero (no-op). | Fixed. | Documented. | 🔴 HIGH | |
| **Same-tenant only** | A credit note can only be applied to invoices of the **same tenant**. | Fixed. | Documented. | 🔴 HIGH | |
| **Updates invoice durably** | Applying a note increases the invoice's tracked credit and recomputes its paid amount so it survives later payment recomputes. | Fixed. | Documented AR-drift fix. | 🔴 HIGH | |
| **Atomic apply** | The note and the invoice update together or not at all. | Fixed. | Documented. | 🔴 HIGH | |
| **Balance never negative** | Invoice balance floored at zero even when over-credited. | Fixed. | Code. | 🔴 HIGH | |
| **Tenant outstanding balance** | = open invoice balances − available credit-note balances. | Fixed. | Documented. | 🔴 HIGH | |
| **Numbers globally unique** | `CN-{ASSET}-{YYYYMM}-{0001}`, unique even across deleted notes; sequence resets monthly per property. | Fixed. | Documented. | 🟠 MEDIUM | |
| **Soft-deleted** | Credit notes are kept (soft-deleted) for audit. | Fixed. | Documented. | 🟠 MEDIUM | |
| **Issue flips status** | On issue, status → issued (if balance remains) or applied (if zero). | Fixed. | Documented. | 🔴 HIGH | |
| **Reason required** | One of return / dispute / adjustment / discount / refund / other (default adjustment). | Fixed enum. | Documented. | 🟡 LOW | |
| **VAT per item** | VAT = amount × rate ÷ 100, rounded 2 decimals; rate limited 0–100%. | Per line. | Documented. | 🔴 HIGH | |
| **Default currency** | EGP. | Fixed. | Documented. | 🟡 LOW | |

*Code references: `CreditNoteService.php`; `CreditNote.php`; credit-note migration & forms.*

---

## Utilities (meters & readings)

| Rule | Current value / formula | Configurable? | Assumption / basis | Risk | Confirm? |
|---|---|---|---|---|---|
| **Consumption = delta** | consumption = current reading − most recent prior reading, rounded 2 decimals. Not an average, not adjusted for days. | Fixed. | Documented. | 🔴 HIGH | |
| **Prior reading = most recent** | Uses the immediately-prior reading, not the oldest. | Fixed. | Tested. | 🔴 HIGH | |
| **Meter rollback guard** | If the reading goes **backwards** (reset/replacement), auto-calc is skipped and the operator must enter consumption manually. | Fixed. | Documented + tested. | 🔴 HIGH | |
| **One reading per meter per date** | Database blocks a second reading on the same date for the same meter. | Fixed. | Documented + tested. | 🔴 HIGH | |
| **Meter number unique** | Meter numbers are globally unique across all properties. | Fixed. | Documented + tested. | 🔴 HIGH | |
| **Meter belongs to one property** | Every meter is tied to exactly one property; never shared. | Fixed. | Documented + tested. | 🔴 HIGH | |
| **Readings cascade on meter delete** | Deleting a meter deletes all its readings. | Fixed. | Documented (intended). | 🔴 HIGH | |
| **Currency** | All costs in EGP. | Fixed. | UI/test. | 🔴 HIGH | |
| **Cost defaults to 0** | Blank cost saves as 0 EGP; never null. | Default 0; field optional. | Documented. | 🟠 MEDIUM | |
| **First reading blank** | The very first reading has no auto consumption. | Fixed. | Documented + tested. | 🟠 MEDIUM | |
| **Readings immutable** | No edit after creation — delete and re-create to correct (preserves audit trail). | UI-enforced. | Documented. | 🟠 MEDIUM | |
| **Consumption required** | Every reading must have a consumption figure (manual on rollback). | Fixed. | Documented + tested. | 🟠 MEDIUM | |
| **12-month trend widget** | Dashboard sums consumption over 12 months by month & type, including faulty meters. | Fixed. | Documented + tested. | 🟠 MEDIUM | |
| **Common-area meter** | A meter with no unit is treated as common-area. | Fixed. | Documented. | 🟡 LOW | |
| **Three meter types** | electric / water / gas only; adding more needs a developer. | Fixed enum. | Documented. | 🟡 LOW | |

*Code references: `ReadingsRelationManager.php`; `UtilityMeter.php`; `MeterReading.php`; `EnergyConsumptionTrend.php`.*

---

## Currency & Decimal Precision

| Rule | Current value / formula | Configurable? | Assumption / basis | Risk | Confirm? |
|---|---|---|---|---|---|
| **Operating currency** | **EGP** for all invoices, payments, charges, leases. | Fixed defaults. | Egypt deployment. | 🔴 HIGH | |
| **Money precision** | Invoices, payments, rent, charges, credit notes stored to **2 decimal places** (max ~9.9M EGP per field for invoices/leases). | Fixed in DB schema. | Banking standard. | 🔴 HIGH | |
| **CAM / large-total precision** | CAM pools, allocations, marketing budgets, applied-credit field use a **larger** capacity (up to ~99 billion) with 2 decimals, to avoid drift on big annual totals. | Fixed in DB schema. | Drift avoidance. | 🔴 HIGH | |
| **Rounding method** | Standard PHP rounding (half-up) to 2 decimals; each aggregate (subtotal, VAT, total) rounded **independently** to prevent penny drift. | Fixed. | Standard. | 🔴 HIGH | |
| **Proration factor precision** | Kept to **4 decimals** before applying to amounts. | Fixed. | Drift avoidance. | 🟠 MEDIUM | |
| **CAM share precision** | Pro-rata share stored to **4 decimals** (e.g. 33.3333%). | Fixed. | Audit/display. | 🟡 LOW | |
| **Paymob piastre conversion** | Amount × 100, rounded, cast to integer (piastres) — Paymob is **disabled**, untested live. | Config currency. | Paymob API. | 🔴 HIGH | |
| **Occupancy rate** | Rounded to 1 decimal (display metric, not money). | Fixed. | Display only. | 🟡 LOW | |

*Code references: invoice/lease/charge/CAM/credit-note migrations; `MonthlyBillingService.php`; `Invoice.php`; `PaymobClient.php`.*

---

## Maintenance SLA & Auto-Close

*(Operational, not financial — included for completeness. SLA targets affect service obligations, not tax.)*

| Rule | Current value / formula | Configurable? | Assumption / basis | Risk | Confirm? |
|---|---|---|---|---|---|
| **Urgent SLA** | Resolve within **4 hours** of submission. | Admin-settable (Maintenance settings); config fallback 24h. | *Unverified* SLA policy. | 🔴 HIGH | |
| **High SLA** | Resolve within **24 hours**. | Admin-settable; fallback 72h. | *Unverified* SLA policy. | 🔴 HIGH | |
| **Medium SLA** | Resolve within **72 hours (3 days)**. | Admin-settable; fallback 168h. | *Unverified* SLA policy. | 🔴 HIGH | |
| **Low SLA** | Resolve within **168 hours (7 days)**. | Admin-settable; fallback 336h. | *Unverified* SLA policy. | 🔴 HIGH | |
| **SLA target** | target = submission time + priority hours. | Fixed formula. | Code design. | 🔴 HIGH | |
| **Breach detection** | Request still open AND target time passed. | Fixed. | Code design. | 🔴 HIGH | |
| **Open statuses** | submitted / acknowledged / in_progress / awaiting_tenant. | Fixed. | State machine. | 🔴 HIGH | |
| **Auto-close** | Resolved requests auto-close after **7 days**. | Config / CLI flag. | Housekeeping. | 🟡 LOW | |
| **Breach scan** | Hourly; alerts once per breach. | Fixed schedule. | Operational. | 🟡 LOW | |

*Code references: `MaintenanceSettings.php`; `config/maintenance.php`; `MaintenanceRequestService.php`; `MaintenanceRequest.php`.*

---

## Sign-off

By signing below, the operator and their tax/finance advisor confirm they have reviewed every rule above, recorded ✅ Confirm or ✏️ Change against each, and resolved (or formally accepted) every item in the [⚠️ NOT yet certified](#️-configuration--integrations-not-yet-certified) section before the system is used for real invoicing and tax filings.

| Role | Name | Signature | Date |
|---|---|---|---|
| Operator (Eltizam) representative | | | |
| Accountant / Tax advisor | | | |
| Reviewed by (Atriom / IT) | | | |

**Outstanding items / notes:**

_(Use this space to list any rules marked ✏️ Change and the agreed correct value, plus any NOT-certified items still open.)_

---

*Generated for Atriom (Egyptian mall-management ERP). This register reflects the system's behaviour as built; it is not legal or tax advice. Values cited as "unverified" require confirmation against your lease contracts and current Egyptian tax law before go-live.*


---

## Vendor SLA Penalties (FR-CM-08) — **NEEDS ACCOUNTANT SIGN-OFF**

When an external maintenance company misses the SLA on a corrective job, the system assesses a
penalty and (once charged) deducts it from what that vendor is owed.

| Rule | What the system does | Status |
|---|---|---|
| **Basis** | The **contract** decides: a flat fee, an amount **per day late**, or a **% of the job's value**. Nothing is assumed — `none` is the default, so a contract with no negotiated penalty charges nothing. | ✅ configurable |
| **Who pays** | Only **external** (vendor) jobs. An in-house job that runs late is flagged, not billed. | ⚠️ assumption |
| **Part days** | Part of a day counts as **a whole day** on the per-day basis. | ⚠️ assumption |
| **Accounting treatment** | **Dr Accounts Payable / Cr the same expense the bill charged** — i.e. a **cost reduction**, not other income. | 🔴 **confirm** |
| **VAT** | **No VAT adjustment.** Treated as compensation (liquidated damages), which is outside the scope of VAT — so the input VAT the original bill recovered is untouched. | 🔴 **confirm** |
| **Cap** | A penalty can never exceed what the bill still owes (AP is never pushed negative). Larger penalties must be split across bills. | ⚠️ assumption |

### Why the treatment matters — and the CAM catch

The accounting question is not cosmetic. Money received from a supplier is normally an adjustment to
the price you paid them rather than new revenue, unless it buys a distinct good or service. An SLA
penalty is a price adjustment on the maintenance service, so the system **credits the same expense
the bill debited** — the penalty follows the cost. The alternative (crediting *other income*) would
overstate both maintenance cost and income.

**🔴 The consequence to decide: does the benefit reach tenants?**

Maintenance is a CAM-recoverable cost. If a penalty reduces maintenance expense, the tenants who
funded that cost through CAM should logically get the benefit of the reduction.

**Atriom does not do this automatically.** `CamExpensePool.total_actual_expense` is **typed in by an
operator** — it is not derived from the GL. So reducing the expense in the ledger does **not** reduce
what tenants are recharged.

> **Therefore:** whoever records the year's actual CAM spend must use the maintenance figure **net of
> SLA penalties**. Otherwise tenants over-pay CAM while the operator keeps the vendor's penalty —
> which is both a reconciliation error and, depending on the lease wording, arguably a recovery the
> tenants are entitled to.

Confirm with the operator and the lease wording: **does an SLA penalty reduce the CAM pool, or is it
Eltizam's to keep?** If it should reduce CAM, the safe answer today is procedural (a documented step
at reconciliation time); wiring the CAM pool to the GL is a larger change and is not in scope.


---

## Externally-bought spare parts (FR-CM-09) — **NEEDS OPERATOR CONFIRMATION**

A part bought outside for a maintenance job is recorded on the work order (what, from whom, on
which supplier invoice) and counts toward the job's parts cost. It posts **nothing** to the general
ledger: it never touched our stock, so there is no inventory to relieve.

The accounting document for that money is the **vendor bill**. Today nothing links the two, and
nothing requires a bill to exist for a recorded external part — so a job's parts cost can exceed
what the books know about it. The books stay balanced (the purchase is absent, not wrong).

**Confirm with the operator/accountant:**
1. Should recording an external part **require** a vendor bill (or an expense) before the job can
   be closed — or is the WO record a memo, with the bill entered independently by accounting?
2. If a bill is required, does it need to reference the work order (for job costing), or is the
   property + category dimension enough?

Until this is answered, treat `partsCost()` as an **operational** figure, not a GL one.

---

## Recharging a repair to a tenant — **NOT BUILT; NEEDS THE CLIENT**

The system now **records** who caused a failure and whether the mall or the tenant is financially
responsible (FR-CM-12/13). It does **not** bill the tenant, because the FRD never asks it to: both
requirements say *determine* and *record*, and no requirement in the document asks the system to
invoice a tenant for a repair. Khaled confirmed record-only (2026-07-16).

If the client wants the recharge, these must be answered **before** it is built — each one changes
the code:
1. **Is a recharge VATable** (14%, as a service), or is it a cost recovery outside VAT?
2. **What is recoverable** — parts only, or parts + labour + the vendor's invoice?
3. **What amount, given the external-part seam?** `partsCost()` can already exceed what the GL knows
   about a job, because an externally-bought part posts nothing (its accounting document is the
   vendor bill — see open question 13). Billing a tenant off `partsCost()` would bill them for money
   the books never saw.
4. **What happens when the cost changes after the recharge?** A part draw voided or an external
   record removed leaves a tenant billed for something that did not happen.
5. **Can the tenant dispute it**, and what does a successful dispute do to the invoice — a credit
   note, or a void?

Until then, `cost_bearer` is a **record of responsibility, not an instruction to charge**.

---

## Procurement approval hierarchy (FR-PROC-02) — **THE FRD'S OWN OPEN ITEM**

The FRD says: *"The client did not specify a formal approval hierarchy for procurement itself.
Confirm whether procurement approval also follows a price-based manager hierarchy or a separate
rule."*

**We defaulted to price-based**, with the same bands as a spare-part draw:

| Request value (EGP) | Needs |
|---|---|
| 0 – 999.99 | `approvals.tier_1` — a supervisor |
| 1,000 – 9,999.99 | `approvals.tier_2` — a manager |
| 10,000 and above | `approvals.tier_3` — senior approval |

Why that default: it is the **only** hierarchy the client has ever described (FR-CM-11), and it is
**data** (`approval_rules`), so a different answer is a row change rather than a rewrite.

**Confirm with the operator:**
1. Do procurement approvals follow the same price bands as spare-part draws, or different amounts?
2. Is there a **separate rule** — e.g. by category, by supplier, or a fixed approver regardless of
   value?
3. Does a large purchase need **more than one** approver? (Today the ladder is a level lookup, not a
   sequential chain — see [module 28](modules/28-approvals.md) for why.)

---

## Approval Ladder (FR-CM-11) — **NEEDS OPERATOR SIGN-OFF**

"Higher-value parts require higher-level approval" is all the FRD says. **It gives no
amounts.** These are business-policy defaults we chose, not documented figures — confirm them.

| Amount (EGP) | Needs | Seeded roles that qualify |
|---|---|---|
| 0 – 999.99 | `approvals.tier_1` | operations (supervisor), manager, super_admin |
| 1,000 – 9,999.99 | `approvals.tier_2` | manager, super_admin |
| 10,000 and above | `approvals.tier_3` | **super_admin only** |

- **The bands are data** (`approval_rules`), so changing an amount is configuration, not a
  release. The tiers are spatie permissions, so re-pointing a tier at a different role is a
  permission change.
- **Authority is cumulative**: a manager can approve a small draw a supervisor could have
  handled. The ladder gates the *ceiling*, not the floor.
- **A manager cannot approve ≥ 10,000.** This is deliberate: `manager` otherwise receives
  every non-delete permission by blanket grant, and a ladder whose top rung everyone reaches
  is not a ladder. Large spend escalates — which is the point of FR-CM-11. **Confirm this is
  the intended escalation**, and who at Eltizam should hold tier 3 in practice.
- **A gap in the ladder fails closed** — an amount matching no band requires the **strictest
  tier configured for that module**, never none. Note "strictest", not "the top band": nothing
  forces a band's tier to rise with its amount, so a ladder edited out of order would otherwise
  hand a gap the *weakest* tier. Misconfiguration makes spending harder, not easier.

🔴 **Confirm:** are 1,000 and 10,000 the right thresholds, and is super_admin-only correct for
the top band — or should a mall manager be able to approve larger draws?
