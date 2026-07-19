# Business Model — Leases (Module 04)

> The lease is the **revenue instrument** of the mall. Everything the operator bills a tenant flows
> from here: base rent, service charge, marketing levy, VAT, escalation, and percentage-of-sales
> rent. This doc explains, in plain language and worked numbers, how a lease makes money and how it
> moves through its life. Technical spec: [modules/04-leases.md](../modules/04-leases.md).

---

## 1. What a lease is, and why

A **lease** binds a **tenant** (a retailer) to one or more **units** (retail spaces) for a fixed
**term**, at an agreed **rent**. It is the contract that says *who* occupies *what*, for *how long*,
at *what price*, and on *what terms*. Once a lease is **active**, the monthly billing engine turns
it into invoices; when it ends, billing stops.

A tenant can hold several leases across the mall; a single lease can span several units (a
**multi-unit lease** — e.g. an anchor store spanning three adjacent units on one contract).

---

## 2. What a tenant pays — the money stack

A lease's monthly bill is built from **charges**. The standard stack for a retail lease:

| Charge | Amount | VAT | Why |
|--------|--------|-----|-----|
| **Base rent** | `base_rent_monthly` | **Exempt** (0%) | Rent on real estate is VAT-exempt in Egypt. |
| **Service charge** | `service_charge_monthly` | **14%** | A *service* (common-area upkeep) — a taxable supply. |
| **Marketing levy** | **5% of base rent** | 14% | Funds mall-wide marketing (configurable per charge). |

So a tenant at **50,000 base + 8,000 service** pays each month:

```
Base rent      50,000              (VAT-exempt)
Service charge  8,000  + 1,120 VAT (14%)
Marketing levy  2,500  +   350 VAT (5% of 50,000 base, +14%)
                ------   ------
Subtotal       60,500   1,470 VAT   →  Total 61,970 / month
```

**Rule that protects you:** `base_rent_monthly` on the lease and the base-rent *charge* amount are
kept in lock-step — you can't have the screen say 50,000 while invoices bill 48,000. Rent only
changes through the dedicated "change rent" service, which updates both together (and re-syncs the
5% marketing levy).

---

## 3. Rent escalation — the rent goes up on the anniversary

Most retail leases raise the rent every year. A lease carries:
- `escalation_type`: **none** · **fixed_percent** · **cpi** (inflation-linked)
- `escalation_rate`: e.g. 7 (%)

**How it works:** when a lease is created with `fixed_percent` + a rate, its **anniversary date**
(`next_escalation_date`) is set to *commencement + 1 year*. A daily background job checks for
anniversaries that have arrived and raises the base rent by the rate, then moves the anniversary
forward another year.

**Scenario — a 3-year lease at 50,000, 7% escalation:**

| Date | Event | Base rent after |
|------|-------|-----------------|
| 2026-01-01 | Lease starts. Anniversary armed to 2027-01-01. | 50,000 |
| 2027-01-01 | Anniversary → +7% | 53,500 |
| 2028-01-01 | Anniversary → +7% | 57,245 |

The increase flows straight into the base-rent charge (and re-computes the 5% marketing levy), so
next month's invoice bills the new amount automatically. **`none` (or rate 0) leases never
escalate** — the anniversary is left blank and the job skips them. **CPI** leases are recognised but
*not* auto-applied (there's no inflation-index feed wired in yet — the system will never invent an
index number).

> **Note (fixed 2026-07-19):** escalation used to silently never fire, because no create screen
> ever set the anniversary date. It does now — armed automatically on create and carried forward on
> renewal.

---

## 4. Percentage rent — a share of the tenant's sales

Anchor and high-traffic tenants often pay **percentage rent** — rent tied to how much they sell.
The tenant declares their sales for a period; the operator locks the declaration; the system bills
the percentage-rent amount. A lease turns this on with `has_percentage_rent`, a `rate` (e.g. 8%),
and a **method**.

There are two methods — and this is the part worth understanding:

### Method A — Natural breakpoint = **"the greater of base rent or % of sales"**

`percentage_rent = max(0, sales × rate − base_rent)`

The tenant already pays base rent monthly. This bills only the amount by which `sales × rate`
*exceeds* base rent. Add the two together and you get **`max(base_rent, sales × rate)`** — i.e. the
tenant pays **whichever is higher**.

**Scenario — base 50,000/mo, rate 8%:**

| Monthly sales | sales × 8% | Overage billed | Tenant pays | Winner |
|---|---|---|---|---|
| 500,000 | 40,000 | `max(0, 40k−50k)` = 0 | 50,000 | base rent |
| 625,000 | 50,000 | 0 (the *natural breakpoint* — they're equal) | 50,000 | tie |
| 800,000 | 64,000 | `max(0, 64k−50k)` = **14,000** | 64,000 | % of sales |

Above 625,000 in sales the percentage takes over; below it, base rent stands. **This is the "take
whichever is higher" model — it already exists; select `natural_breakpoint` on the lease.**

### Method B — Artificial breakpoint = base rent **plus** a share above a set threshold

`percentage_rent = max(0, (sales − threshold) × rate)`

Here the tenant pays base rent **and**, on top, a slice of sales above a *separately negotiated*
threshold (not tied to base rent). Use it for a deal like *"base rent, plus 8% of everything over
1,000,000."*

**Scenario — base 50,000, threshold 1,000,000, rate 8%:** tenant sells 1,300,000 →
`(1,300,000 − 1,000,000) × 8% = 24,000` overage → tenant pays **50,000 base + 24,000 = 74,000**.

### How it gets billed (both methods)

The declared sales → operator **locks** the declaration → the overage is invoiced **immediately** as
its own issued invoice (typed `percentage_rent`, posting to Percentage-Rent Revenue in the books).
Locking is idempotent and lock-safe (two clicks can't double-bill), and a locked declaration can be
**voided/re-locked** — which reverses the old overage (voids its invoice, unless it's already been
paid) before billing the corrected one.

> **One thing to confirm (open):** the natural-breakpoint math compares the declaration's sales to
> **one month's** base rent. That's correct for **monthly** declarations. If you ever declare sales
> **annually**, the base would need annualising — flagged for the module-09 (Tenant Sales) close-out.

---

## 5. The lease lifecycle — birth to end

```
 draft ──▶ active ──▶ renewed   (superseded by a new active lease)
   │          │  └───▶ expired   (term ended)
   │          └──────▶ terminated (ended early)
   └─────────────────▶ cancelled (never took effect)
```

- **draft** — negotiated but not live; no billing, the unit shows *reserved*.
- **active** — live. Charges bill monthly; the unit shows *occupied*. **Only one active lease per
  unit at a time** (checked against the *lease_unit* pivot, so a unit held as an additional unit in
  a multi-unit lease can't be double-booked either).
- **terminated** — ended early. The terminating step deactivates the lease's charges (so billing
  stops) and can cancel *fully-unpaid* future invoices; partially-paid ones need a credit note.
- **renewed / expired / cancelled** — the other end states.

**Terminal leases are immutable (fixed 2026-07-19):** once a lease is terminated/expired/cancelled/
renewed, its commercial terms (rent, status, dates, unit) can't be edited — only notes and
soft-delete/restore. This stops a closed lease being quietly re-opened and re-priced. To change a
closed deal, you *reverse* or *renew* it, you don't edit it.

**Renewal** creates a **new** active lease linked to the old one (`previous_lease_id`), carries
forward the full unit set and the escalation clause (re-arming the anniversary to the new
commencement + 1 year), and marks the original **renewed**.

---

## 6. Multi-unit leases

`leases.unit_id` is the **master** unit; the **lease_unit pivot** is the source of truth for the full
set (exactly one master). Editing membership goes through one method that keeps the pivot, the master
pointer, and every affected unit's occupancy in lock-step. Renewal carries the whole set forward.

**Today the whole lease has one `base_rent_monthly`** spanning all its units (a single blended rent).
Per-unit rents are a deferred decision — see below.

---

## 7. What a lease deliberately does **not** do yet (open decisions)

These are real business choices flagged during the close-out, waiting on an operator rule — not
bugs, but boundaries you should know:

- **Holdover / auto-expiry.** A lease past its `expiry_date` stays *active* and keeps the unit
  occupied, but the monthly engine excludes it — so a held-over tenant currently trades **rent-free**
  until someone renews or terminates. *Decision: auto-expire? bill month-to-month, perhaps at a
  premium?*
- **Move-out proration.** A mid-month termination bills the **full** final month (no refund), or
  drops it if processed before that month's run. Only the **first** month prorates. *Decision:
  prorate + credit the final month?*
- **Rent-free / fit-out / stepped-rent schedules.** Anchor deals ("3 months rent-free, then Yr1
  100k / Yr2 120k / Yr3 150k") are today built as manual charges. *Decision: model a first-class
  abatement/step schedule?*
- **Per-unit rent** on a multi-unit lease (§6). *Decision: price per unit, or keep one blended rent?*

Parked as *feature-parity* (not decisions, just not built): lease contract/document generation +
e-signature · CPI index feed · flexible billing cadence/anchor-day · IFRS-16 straight-line revenue
recognition.

---

## 8. How it connects

- **[Billing (05)](../modules/05-billing-invoices.md)** turns active leases into monthly invoices.
- **[Tenant Sales & % Rent (09)](../modules/09-tenant-sales-percentage-rent.md)** owns the sales
  declaration → lock → overage flow described in §4.
- **[Properties & Units (01)](01-properties-units.md)** — a lease's status projects the unit's
  occupancy.
- **[CAM (08)](../modules/08-cam.md)** — the lease's leased area drives its share of common-area
  cost recovery.
