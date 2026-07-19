# Business Model — Billing & Invoices (Module 05)

> The engine that turns active leases into **money owed**. Once a month it looks at every live lease,
> works out what each tenant owes for the period, and issues an invoice — with the right VAT, the
> right proration, and without ever billing the same month twice.
> Technical spec: [modules/05-billing-invoices.md](../modules/05-billing-invoices.md).

---

## 1. What the monthly run does

For a given month, the run walks every **active** lease and, for each, gathers its **applicable
charges** for that period, builds one invoice, and posts it to the books. A charge is "applicable"
if it's active and due in that month (by its frequency — see §3). The tenant gets a bell/email that
their invoice is ready.

It's **idempotent**: re-running the same month never double-bills a lease (it checks whether an
invoice already covers that period). And it's **resilient**: one bad lease is logged and skipped,
it never aborts the whole run.

---

## 2. What lands on the invoice — the money stack + VAT

For a standard retail lease (base 50,000, service 8,000):

| Line | Amount | VAT | Posts to (GL) |
|------|--------|-----|---------------|
| Base rent | 50,000 | **0% (exempt)** | Rent Revenue |
| Service charge | 8,000 | **14%** → 1,120 | Service Charge Revenue |
| Marketing levy (5% of base) | 2,500 | 0%* | Marketing Revenue |
| *(if configured)* Parking | e.g. 3,000 | 14% | **Parking Revenue** |
| *(if configured)* Utilities | metered | 14% | Utility Revenue |

`\* Marketing levy VAT is currently 0% — flagged for the accountant as possibly 14% (a billed
marketing service). The levy IS billed to the tenant (a "marketing fund contribution"); the mall's
marketing budget accrues from that billed line.`

**The invoice total = Σ line amounts + Σ line VAT.** Each line posts to its own revenue account, and
the VAT to VAT Payable — so the books tie out to the penny (a close-out fix routed *parking* to its
own account instead of "misc income").

---

## 3. Charge frequencies — billing the right thing in the right month

A charge carries a **frequency**: `monthly` (every run), `quarterly` / `annually` (only in the
anniversary month), or `one_time` (once). So an annual charge configured in January only appears on
January's invoice, not every month. Base rent + service + marketing are monthly; a one-off fit-out
fee is `one_time`.

---

## 4. Proration — the first month

A tenant who moves in mid-month shouldn't pay a full month. When you generate the **first** invoice
with proration on, the run charges only the days occupied.

**Scenario:** lease commences **March 20**, base rent 31,000/mo (a 31-day month) → the first invoice
bills `31,000 × 12/31 = 12,000` for the 12 days (March 20–31). April onward bills the full month.

> **Open (deferred, same as leases):** proration is only applied to the FIRST month and only via the
> per-lease action. The scheduled run doesn't auto-prorate move-ins, and the **final** month of a
> mid-month move-out isn't prorated. Recorded as domain decisions.

---

## 5. The AR single source of truth

An invoice's **paid_amount** and **balance** are never typed or set by hand. `recomputeTotals()` is
the one place that derives them:

> **paid_amount = captured payments + applied credit notes** · **balance = total − paid_amount** (floored at 0)

Every money event (a payment, a credit note) runs through it, so the balance is always the truth of
what's been received. *(A legacy `recalculateBalance()` that set the balance directly — ignoring
credits — was removed in the close-out; it was dead code that made the wrong pattern look endorsed.)*

---

## 6. Late fees & overdue

- An **overdue scan** flips an invoice past its due date to `overdue` (idempotent).
- A **late-fee run** adds a one-off late-fee line to overdue invoices: **`max(50 EGP minimum, 2% of
  balance)`**, after a **7-day grace**, **charged once** (not compounding), and never on a
  now-settled invoice. So a small 1,000 balance is charged the **50 floor** (not 20); a 10,000
  balance is charged 200.

**Scenario:** invoice due March 1, unpaid. On March 9 (past the 7-day grace) the late-fee run adds a
50/2%-of-balance line; a second run the same week adds **nothing** (idempotent).

---

## 7. What it deliberately does **not** do yet

- **Auto-proration on move-in / move-out** in the scheduled run (see §4) — domain decision.
- **Flexible billing cadence** — everything is monthly on a fixed calendar; no per-lease anchor day
  or semi-annual cycles. Deferred (parity).
- **Escalating dunning** — one arrears reminder, not a staged sequence. Deferred (parity).
- **Re-billing after a void** — voiding an issued invoice suppresses re-billing that period until
  someone re-issues manually. Recorded.

---

## 8. How it connects

- **[Leases (04)](04-leases.md)** — the source of every charge billed here.
- **[Payments (06)](../modules/06-payments.md)** — settle the AR this creates (feeds `recomputeTotals`).
- **[Credit Notes (07)](../modules/07-credit-notes.md)** — reduce AR (also feed `recomputeTotals`).
- **[General Ledger (21)](../modules/21-general-ledger.md)** — every line posts to its revenue account + VAT payable.
- **[ETA (16)](../modules/16-eta-einvoicing.md)** — issued invoices submit to the Tax Authority.
