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

### ✅ Shipped 2026-08-11

- **Asset criticality** — `equipment.criticality` (critical / important / routine), and it **changes
  behaviour rather than rendering a badge**: a fault raised on critical equipment starts at urgent,
  a PM round on one does too, and the create form pre-fills the priority visibly when a machine is
  picked. An operator who states a priority still gets it. On the tenant-request path it takes the
  **higher** of the tenant's reported priority and the machine's — the tenant's figure is their view
  of the disruption and is usually left on the default, so taking the higher can only raise a job,
  never quietly lower one. Three values, not five: a scale nobody applies consistently is a field
  that stays on its default.

- **Comparative statements** — `ComparativeStatementService`. The income statement beside the
  immediately preceding span of the **same length**, derived rather than asked for: comparing a
  31-day month against a 28-day one invents a variance that is really just February. Built ON
  `LedgerReportService::incomeStatement()`, so both periods read through one definition of revenue
  and expense; a second implementation would drift and the drift would surface as a variance nobody
  could explain.

### Carried from the roadmap — VERIFIED against the code 2026-08-19

This list was ten rows and a warning that several were probably already built. Checked, one by one:
**five of the ten were.** They are struck below rather than deleted, because a deleted row gets
re-added by the next person who has the same idea.

| Row | Verdict | Evidence |
|---|---|---|
| ~~Weighted-average inventory costing~~ | ✅ **Built** | `StockMovementService::weightedAverageCost()` — and it is what values stock on hand, not a helper nobody calls. The row said "perpetual FIFO ships today", which is true of ISSUE costing and was never the whole question |
| ~~Meter/usage-based preventive-maintenance triggers~~ | ✅ **Built** | `GeneratePreventiveWorkOrdersService` runs two rounds — the calendar round and the **counter round**, evaluated in PHP because the usage baseline is what makes a usage plan non-repeating |
| ~~Utility tariff / recharge automation~~ | ✅ **Built** | `ReadingsRelationManager::deriveCost()` prices consumption at `resolvedRatePerUnit()` **for the reading's own date** — a tariff is a dated ladder, so a back-filled reading is priced at what the supply cost when it was consumed |
| ~~Annual / YTD turnover breakpoints for percentage rent~~ | ✅ **Built** | `leases.percentage_rent_frequency` takes `annual`, which is the cumulative breakpoint; `monthly` is the fresh-each-month one |
| ~~The inventory transfer stub~~ | ✅ **Built** | `StockMovementService::TRANSFER_TYPES` — exactly as the row's own note suspected. It deliberately posts nothing for an intra-company move |
| **Reorder-driven auto-purchase** | 🟡 **Half open** | `inventory:scan-low-stock` alerts every property at or below `reorder_level`. What does not exist is raising the purchase request automatically — and that is a policy question (who approves a system-raised commitment?) rather than missing plumbing |
| **Capex bid comparison** | 🔴 **Open** | There is no quote or bid model at all: procurement is `PurchaseRequest` + `PurchaseRequestLine`. Comparing bids means a new entity, not a screen over an existing one |
| **Fit-out permit approval workflow** | 🔴 **Open** | `leases.fit_out_scope` exists and is about rent ABATEMENT during fit-out. There is no permit, no approval and no contractor record — a different thing that shares a word |
| **Lease document generation + e-sign** | 🔴 **Open** | A signed contract is UPLOADED to a private disk (`Lease` media collection). Nothing generates the document and nothing signs it |
| **Statutory rate automation** | ⛔ **Decline** | The mechanism already exists and is better than the row imagined: `tax_rates` is a dated ladder, so a decreed rise is entered in advance and starts applying by itself on the day, with back-dated documents keeping the rate that was in force. What "automation" would add is an external feed of Egyptian statutory rates, and there is no such feed to consume. Recorded as declined so it is not re-raised |

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
| "VAT-return report" | **Shipped 2026-08-11** — `VatReturnService`, read from the ledger and proved against the invoices, at `/admin/vat-return`. *(It first landed with no caller at all and this row already said "shipped" — reachability is half of done.)* |
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

**All three items that were verified buildable are now shipped** (asset criticality, vendor
scorecards, comparative statements — 2026-08-11). What remains needs either an answer or a check:

- Everything in **§2** needs a client answer first. Building it without one means inventing policy.
- **Slice 4** (suggested matches) needs a real month reconciled by hand before it is worth shaping.
- The **carried-forward list** above is now VERIFIED (2026-08-19). Five of the ten were already
  built, one is declined, one is half-built, and three are genuinely open: capex bid comparison,
  the fit-out permit workflow, and lease document generation + e-sign. All three are new entities
  rather than screens over existing ones, so each is a module-sized piece of work.

So the honest position is that **the buildable-without-input backlog is three module-sized items**
— capex bid comparison, the fit-out permit workflow, and lease document generation + e-sign — none
of which anyone has asked for. Everything smaller is either built or blocked on an answer. The next
move is still a conversation rather than a commit.
