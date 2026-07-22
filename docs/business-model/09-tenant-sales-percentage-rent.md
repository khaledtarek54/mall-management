# Tenant Sales & Percentage Rent — the business model

> Many mall tenants pay **base rent + a slice of their sales** ("percentage rent" / نسبة من المبيعات).
> To bill that slice, the tenant reports their sales each month; the operator reviews the report and
> locks it, which bills the overage. Pairs with the technical spec in
> [modules/09](../modules/09-tenant-sales-percentage-rent.md) and the close-out in
> [gap-analysis](../gap-analysis/PROPERTY-FACILITY-CLOSURE.md).

## 1. The idea

A retailer's rent has two parts:
1. **Base rent** — the fixed monthly minimum.
2. **Percentage rent** — a % of the tenant's actual sales *above a breakpoint*. A strong month means
   the landlord shares in the upside.

The tenant can't be trusted to volunteer this, so the flow is: **the tenant uploads their sales
report** (image/PDF) each month → **the operator reads the figure off it and enters it**, then
**locks** the declaration. Locking bills the percentage-rent overage. The sales report file is
private (it's the tenant's turnover) and only ever streamed to authenticated, tenant-scoped requests.

## 2. The two breakpoint formulas

Set per lease:

- **Artificial breakpoint** — `overage = max(0, (sales − breakpoint) × rate)`. Sales 100,000,
  breakpoint 50,000, rate 5% → (100k − 50k) × 5% = **2,500 owed**.
- **Natural breakpoint** — `overage = max(0, sales × rate − base rent)`. Sales 200,000, rate 8%, base
  rent 10,000 → 16,000 − 10,000 = **6,000 owed**.

Under-breakpoint months owe exactly **0** (never negative). Percentage rent, like base rent, is
**VAT-exempt**.

## 3. Locking bills the overage — immediately, on its own invoice

When the operator locks a declaration that owes something, the overage is billed **right away** as
its own issued invoice (posted to the GL as *percentage rent revenue*).

> **Why immediate (a hard-won lesson).** An earlier design created a charge dated to the (past) sales
> month and left it for the monthly billing run — but the monthly run only bills the *current*
> month's charges, so a charge dated to a bygone month was **never billed** and the overage silently
> vanished. Billing it immediately (the same pattern as the CAM year-end recovery) closes that leak.

**Correcting a mistake.** A locked declaration whose figure turns out wrong is **Voided** — that
cancels its overage invoice and re-opens it for a fresh figure; re-locking bills the corrected amount
(never two). A tenant who *already paid* the overage must be refunded before it can be voided.

## 4. Worked scenario

Tenant leases 120 m², artificial breakpoint 50,000, rate 5%. They upload a January report showing
**90,000** in sales. The operator reads it, enters 90,000, and locks →
`(90,000 − 50,000) × 5% = 2,000` → a 2,000 invoice is issued and lands in the tenant's portal. In
February the tenant does 40,000 (under breakpoint) → locking owes **0**, no invoice, but the
declaration is still recorded (an audit result: "reported, under breakpoint").

## 5. Chasing the tenants who don't report *(close-out fix)*

The whole model depends on the tenant reporting. If they never upload, **no declaration exists — so
nothing bills and nothing alerts** (the same silent-leak class as the billing gap, one step earlier).
The close-out added:

- A monthly **scan** (`sales:scan-missing-declarations`) that reminds every percentage-rent tenant
  who hasn't reported the closed month (skipping leases still in their fit-out grace). Idempotent —
  one reminder per tenant per period.
- A dashboard **"missing sales declarations" card** so the operator sees the un-reported leases live
  and can chase them.

## 6. The guardrails

- **One declaration per lease per month** (a tenant can't submit twice for the same period).
- **Only the operator can lock/void; only tenant-admins can submit** — a read-only auditor or the
  owner can *see* declarations but can't bill or void one. *(Close-out fix: those actions were
  dispatchable by a read-only user via a crafted request — now hard-gated.)*
- **A locked declaration is final** — corrections go through Void → re-lock, never a silent edit.
- **The sales report is private** — one tenant can never fetch another's turnover figures.

## 6b. Annual / cumulative breakpoints (built 2026-07-22)

Originally the biggest deferral; **now built** after the operator confirmed annual-breakpoint leases
exist / are being signed. A lease's **breakpoint frequency** is now `monthly` (the default — the
threshold resets every month) or `annual` (the threshold is a **yearly** figure). On an annual lease
each locked month bills only the **increment** needed to bring the year's percentage rent up to what
the *cumulative* sales owe — so a strong Ramadan/December month is **netted against the slow months**
instead of over-charged against a monthly breakpoint. Worked example: annual breakpoint 150,000 @ 10%,
two slow months of 40,000 then a 120,000 spike → the annual lease bills **5,000** (on cumulative
200,000 over 150,000), where a monthly-breakpoint lease would bill **7,000** on the spike alone. The
slow months shelter the spike, which is the whole point of an annual deal. The year's monthly bills
always sum to the true annual overage; locking months out of order still trues up correctly.

## 7. What's deferred (and the trigger)

- **Non-calendar % rent year + stub-year proration.** The annual cumulative resets on **1 January**
  (Gregorian calendar year) and the breakpoint is a full-year figure. A lease whose % rent year runs on
  a **lease anniversary** (Apr→Mar) or a **fiscal** year, or one **commencing/exiting mid-year** (which
  should get a prorated breakpoint), isn't handled yet — the first would reset mid-year, the second
  would under-bill the partial year. Calendar-year is the common Egyptian default, so this is a fair
  deferral. *Trigger: the first annual lease with a non-calendar % rent year, or a mid-year
  commencement where a prorated breakpoint is expected.*
- **Monthly estimates + annual true-up** (a *different* structure — the tenant pays a monthly % rent
  estimate and reconciles once a year with a true-up/credit). Atriom instead bills nothing until the
  cumulative crosses the breakpoint. *Trigger: a lease that stipulates monthly % rent estimates.*
- **Structured sales basis / exclusions** (gross vs. net, returns, online, inter-store) — today one
  operator-entered figure off the uploaded report. *Trigger: a lease that defines sales exclusions, or
  a basis dispute.*
- **Estimated / deemed-sales billing** when a tenant defaults on reporting (bill a prior-period
  estimate + a reporting penalty). *Trigger: chronic non-reporting the reminder can't fix.*
- **A tenant-facing % rent statement PDF** (the sales → breakpoint → rate → owed working). *Trigger: a
  tenant wants the workings before paying.*
