# Atriom — the backlog after go-live

**What is worth building once the system is live, what is blocked, and what is already done.**
Compiled 2026-08-11.

> **Read [GO-LIVE.md](GO-LIVE.md) first.** Nothing on this page blocks launch. Every row here is
> improvement to a system that already bills, posts a complete double-entry ledger and reconciles to
> a bank statement. Building any of it before §1 of the gate is done is motion, not progress.
>
> **Two kinds of row, kept apart on purpose.** Some were **re-verified against the code** while
> writing this; the rest are **carried forward from the roadmap and NOT re-checked**. Today three
> "open" tail rows turned out to be shipped already, so the distinction is not pedantry — treat an
> unverified row as a claim to check before acting on it, not as work to start.

---

## 1. Buildable now — no client input needed

### Verified still missing (checked 2026-08-11)

| Item | What it is | Size |
|---|---|---|
| **Asset criticality** | One field on equipment (critical / important / routine), driving PM priority and work-order triage. The roadmap calls it "one field, high leverage" and it is right — **verified absent**. | S |
| **Vendor scorecards** | Rank vendors on what the system already records: SLA breaches, penalties applied, response and resolution times, document expiry. **Verified absent** — and everything it needs is already stored, so this is a report, not a data model. | S–M |
| **Comparative statements** | An income statement and balance sheet with a prior-period column. **Verified absent** — `IncomeStatement` has no comparison logic. Pure reporting over the existing GL. | M |

### Carried from the roadmap — NOT re-verified

Check each against the code before starting; several neighbours turned out to be shipped.

- Weighted-average inventory costing (perpetual FIFO ships today).
- Reorder-driven auto-purchase.
- Capex bid comparison.
- Statutory rate automation.
- Meter/usage-based preventive-maintenance triggers.
- Fit-out permit approval workflow.
- Utility tariff / recharge automation.
- Lease document generation + e-sign.
- Annual / YTD turnover breakpoints for percentage rent.
- The inventory **transfer** stub — *note: `StockMovementService` already handles `transfer_in` /
  `transfer_out` and deliberately posts nothing for an intra-company move, so this row may already
  be closed. Verify before treating it as work.*

### Deliberately held

| Item | Why it is held rather than pending |
|---|---|
| **Bank reconciliation — suggested matches** (slice 4) | The manual path works. A suggester that is *usually* right is exactly the thing that stops being read, and a wrong match marks money as verified — the failure the module exists to prevent. Worth building **only after someone has reconciled a real month by hand** and can say where the tedium actually is. Otherwise it optimises a workflow nobody has performed. |

---

## 2. Blocked — the answer has to come first

These are not unstarted; they are unstartable. Building them without the answer means inventing
policy, and policy invented in code is the hardest kind to find later.

| Item | Blocked on | Where the question lives |
|---|---|---|
| **Egyptian tax depreciation + a second tax book** | The accountant: pool method and rates under Law 91/2005 | [GO-LIVE §2 A6](GO-LIVE.md) |
| **End-of-service gratuity accrual** | The accountant: basis and rate. *(Employer social insurance is already recorded — only gratuity remains.)* | [GO-LIVE §2 A5](GO-LIVE.md) |
| **Tenant-side withholding tax** | The accountant: do tenants withhold on rent, at what rate. *(The vendor side shipped.)* | [GO-LIVE §2 A2.1](GO-LIVE.md) |
| **Owner-statement management fee** | The owner: B.4 — how Eltizam is paid | [GO-LIVE §3](GO-LIVE.md) |
| **Jawad/Eltizam revenue split** (FR-FIN-06..09) | A finance workshop. Needs legal entities, issuer-vs-payer separation, effective-dated split rules, a remittance ledger and per-entity VAT — and it touches **ETA's single hardcoded issuer TRN, which cannot express two entities**, so it constrains ETA go-live too. | [GO-LIVE §3](GO-LIVE.md) |
| **FR-REQ-01 "delegation (from/to)"** | The client: no such concept exists anywhere in the system, and it cannot be inferred from anything described. | [OPEN-QUESTIONS §E](OPEN-QUESTIONS.md) |

---

## 3. Already shipped — retire these rows, do not rebuild

Verified in the code 2026-08-11. Each was still listed as open somewhere.

| Row | Actually |
|---|---|
| "Bill the violation fine to AR" | **Shipped** — `BillViolationFineService` raises a VAT-exempt invoice posting to `misc_income`. |
| "Deposit balance / reconciliation layer" | **Shipped** — `DepositApplication` nets a deposit against an invoice as its own dated GL source, with the move-out statement on top. |
| "VAT-return report" | **Shipped 2026-08-11** — `VatReturnService`, read from the ledger and proved against the invoices. |
| "First-class rent-free / stepped schedules" | **Substantially shipped** — `ChargeScheduleService` (a change closes the row in force and opens the next) plus `LeaseReliefService`. Confirm what is genuinely left before re-opening it. |

---

## 4. Declined — recorded so nobody mistakes them for a backlog

Full reasoning in [GO-LIVE §5](GO-LIVE.md) and the two benchmark analyses. In short: deposit
batches, bank feeds, multiple books, multi-currency, consolidation, POS feeds, IoT / predictive
maintenance, a vendor marketplace, Maximo-grade reliability analytics, office-lease base-year CAM,
interest-bearing deposit accounts, and Odoo's full salary-rule engine.

The discipline behind all of them is the same and worth restating: **keep layering depth onto the
property + facility + Egyptian-books spine; do not grow sideways toward every-industry breadth.**
The moat is being the system that gets Egyptian mall accounting right, not the one that does
everything adequately.

---

## 5. If you want something built and go-live is not ready

Take them in this order, and know that none of it shortens the path to launch:

1. **Asset criticality** — smallest thing with real operational leverage, and verified missing.
2. **Vendor scorecards** — a report over data the system already has, and the first thing an
   operator asks once vendors are being managed rather than merely recorded.
3. **Comparative statements** — the reporting gap an accountant notices first after the books are
   real.

**What I would not start** is anything in §2 (it needs an answer), slice 4 (it needs a real month
reconciled by hand first), or anything in the carried-forward list without checking it still exists.
