# Generic modules — CLOSED

> **Status: correct and frozen as of 2026-07-18.** The generic-ERP layer under Atriom's
> property business — general ledger, AP / vendor bills, inventory, procurement, fixed assets,
> HR / payroll, treasury / عهدة, marketing spend, deposits — has **zero open correctness
> findings**. Every 🔴 money bug and every book-corrupting defect found in the round-2 audit
> (modules 21–29) is fixed, and the short tail of cheap 🟡 correctness items is now closed too.
>
> **From here, build effort goes to property + facility, not here.** This document is the line:
> read it before reopening any generic module, so you don't re-audit what is already done.

> ## ⚠️ CORRECTED 2026-08-11 — "zero open correctness findings" was wrong
>
> A money-lens pass over three of these modules — **22 inventory, 23 fixed assets, 25 treasury /
> عهدة** — found **six correctness defects**, every one of which put a wrong number on the books:
>
> | Module | Defect | What the books showed |
> |---|---|---|
> | 22 | Consumption relieved Inventory at the item's *current* catalogue cost, not what the stock was loaded at | Inventory **−2,000** with zero stock on hand; R&M overstated by the same |
> | 22 | The work-order part draw priced its **approval tier** and its stock relief from that same stale catalogue | a large draw routed to a junior approver; the drift re-opened through a path that bypassed the fix |
> | 22 | The stock register + its CSV valued stock at the catalogue price | screen said 3,000 where the Inventory account held 1,000 — on the column labelled as the operator's reconciliation figure |
> | 23 | The re-cost floor (`assertRecostValid`) had exactly ONE caller, a Filament page | net fixed assets pushed negative from any non-form writer |
> | 23 | A **disposed** asset's cost stayed editable, and `updated` re-derives its children | a posted, terminal disposal restated — the gain or loss on a sale that already happened |
> | 25 | A settled عهدة's amount / date / paid-from stayed editable | outstanding **negative**; Custodies stopped netting to zero; the credit moved to an account the cash never left |
>
> **Why this sweep missed them, which is the part worth keeping.** It asked two good questions —
> *"did we build it right"* (the round-2 audit) and *"what does a mature generic ERP have that we
> don't"* (the Odoo benchmark). Neither is the question that found these:
>
> > **At which layer is this rule enforced, and does every writer obey it?**
>
> All six are the same shape. The money core was *sound* in all three modules — derived balances,
> locks, idempotent runs, GL sources exercised through the real sweep. What was wrong was the
> **layer**: a rule the module doc states as a fact, enforced on a Filament form and nowhere else.
> Module 22's was even written down as a feature — *"known limitation … keep `unit_cost` stable or
> reconcile Inventory periodically"*, which is advice no operator can follow and no system enforces.
> A `->disabled()` is a claim about one writer; a documented invariant is a claim about all of them.
>
> **So this file's instruction — "don't re-audit what is already done" — was steering people away
> from the modules that had the money bugs.** That instruction now holds only for the questions this
> sweep actually asked. It does **not** hold for the layer question, which has never been run over
> the rest of the generic layer: **21 general ledger, 24 HR / payroll, 29 procurement, deposits and
> marketing spend are still unexamined by it.** Given three of three modules yielded findings, the
> prior for the remaining ones is not zero.
>
> ### The completed pass, 2026-08-11
>
> | Area | Findings | Verdict |
> |---|---|---|
> | 22 inventory | 3 | cost basis, part-draw tiering, register-vs-ledger valuation |
> | 23 fixed assets | 2 | re-cost floor bypassable, disposed asset re-costable |
> | 24 HR / payroll | 2 | approved run's payslips rewritable, header derived one-way |
> | 25 treasury / عهدة | 2 | grant terms + custodian editable after settlement |
> | 29 procurement | 1 | **approval-ladder bypass** — a line added after approval |
> | deposits | 1 | a receipt could shrink after the tenant was credited against it |
> | marketing spend | **0** | clean, and now witnessed (`MarketingSpendStaysDerivedTest`) |
> | **21 general ledger** | **2** | a posted entry, and every line on it, was editable — enforced by a hidden Save button |
>
> **Ten findings across six areas, and one clean.** Marketing is the control that makes the rest
> credible: both its figures are derived from source, so the pattern that produced every other
> finding — a stored figure a second writer can move away from what it was derived from — has no
> purchase there. Its posted spends are deliberately NOT frozen, because editing one leaves the
> books *correct* (the entry re-derives, the posting-date guard holds, the budget follows) — the
> freezes elsewhere exist only where an edit left a WRONG number behind.
>
> **Module 21 is now swept too, and it was the worst of them.** The ledger every other module posts
> into protected its posted entries with `EditJournalEntry::getSaveFormAction()->visible(...)` — a
> hidden Save button — with nothing at the model on either the entry or its lines. Re-pricing,
> adding or removing a line left debits ≠ credits, so the trial balance and everything drawn off it
> was wrong at once; moving `entry_date` restated a closed, reported month from inside the ledger.
> Fixed at the model, with the posting engine as a narrow, named exception
> (`JournalLine::withinPostingEngine()`, one caller).
>
> **So the pass is complete: seven areas, twelve findings, one clean.** The single sentence that
> covers all twelve — *a rule the module doc states as a fact, enforced on a form* — held in the
> general ledger as squarely as in the spare-parts store.
>
> Method and matrices: [VALIDATION-SWEEP-PLAN.md](VALIDATION-SWEEP-PLAN.md). Every guard added was
> mutation-tested — deleted, watched go red, restored — because §10 of that document records a
> finding that was *correct* while the tests proving it were worthless.

Companion reads: the internal audit ([000-progress.md](000-progress.md)) asked *"did we build it
right"*; the external benchmark ([odoo/README.md](odoo/README.md)) asked *"what does a mature
generic ERP have that we don't"*. This file records the **decision that follows from both**: keep
the generic modules correct and thin; do not grow them toward Odoo's breadth.

---

## 1. What "closed" means here

Closed is **not** "feature-complete like Odoo." It is a defensible line:

- **No known correctness gap.** Nothing on the books is wrong; no money document lacks a
  correction path; no operator-typed date can silently diverge business state from the GL.
- **Everything else is *explicitly parked*, with a trigger** — so a deferred item is a decision on
  record, not a surprise a future audit rediscovers as debt.

The design principle, confirmed by the Odoo comparison: **the generic modules exist to *serve* the
property business** (post rent to a ledger, cost maintenance parts, pay staff, track عهدة). They
need to be **correct, not comprehensive.** On several capabilities Atriom already matches Odoo
*Enterprise* while Community ships nothing (depreciation engine, payslips + GL, perpetual costing,
GRNI, cash-flow statement, عهدة). Chasing Odoo's remaining generic breadth would spend the scarcest
resource — build time — on the weakest strategic ground.

## 2. What the closing pass fixed (2026-07-18)

Five cheap, same-class correctness / consistency items — each the sibling of a guard already
written elsewhere in the codebase:

| Ref | Module | Fix | Test |
|---|---|---|---|
| **F-90b** | HR / payroll | A payroll *line*'s net could go negative while the run header summed positive (a payslip printing **Net −1,000** on a frozen run). `PayrollLine` now refuses a net-negative line; the relation-manager form validates it inline. | `PayrollLineNetGuardTest` |
| **F-91** | HR / payroll | A mis-keyed advance repayment was **permanently uncorrectable** (the one money document that couldn't be fixed). `RecordAdvanceRepaymentService::reverse()` soft-deletes the repayment = the GL void; a Reverse action was added to the advances relation manager. Mirrors the custody F-94 fix. | `AdvanceRepaymentReversalTest`, `EmployeeAdvancesRelationManagerTest` |
| **D-96** | AP / vendor bills | `VendorBill::bill_date` is operator-typed and becomes the GL `entry_date`, with no closed-period check — the F-89/F-93 class. Create + edit now run the `App\Support\PostingDate` guard (edit only when the date actually changes). | `VendorBillClosedPeriodTest` |
| **F-96** | Facility maintenance | `maintenance:scan-wo-sla-breaches --dry-run` assessed penalties (wrote real `maintenance_penalties` rows) *before* the dry-run check. It now previews and returns before any write. | `WorkOrderSlaDryRunTest` |
| **D-95** | Procurement | The table `EditAction` carried `visible()` but not `->authorize()`, unlike its five siblings. Added for consistency + defence-in-depth. (In this Filament build a hidden table action already refuses to mount, so this is hardening, not a live-exploit fix — see the test's note.) | `PurchaseRequestEditGateTest` |

## 3. Deliberately parked — generic breadth (do NOT build speculatively)

From the Odoo benchmark. Each is either **N/A to a single-entity EGP operator** or **Enterprise-gated
breadth** that even Odoo customers custom-build for Egypt. Decline unless a real customer forces it.

| Parked | Why parked | Trigger to revisit |
|---|---|---|
| Multi-currency treasury / FX | EGP-only today | A tenant is billed in USD/EUR (TREAS-2) |
| Multiple cash / bank journals, payment batches | One cash + one bank suffices at current scale | An operator runs several petty-cash boxes / pays many vendors in a run (TREAS Phase 2) |
| Employee expense **claims** (self-service submit→reimburse) | عهدة + direct expense cover the operator's actual flow | Staff-reimbursement volume makes accountant-keyed entry the bottleneck |
| Salary-rule / structure engine | Enterprise-gated in Odoo; flat entry is right for ~dozens of staff | Headcount + contract heterogeneity outgrow flat entry |
| Leave / attendance / timesheets | Out of finance-first HR scope | Shift-based staffing needs in-system rostering |
| Asset categories/models, prorata, degressive *book* depreciation, revaluation journal | Straight-line book depreciation is what most operators use | (Degressive is really about *tax* — see §4) |

**Rule of thumb:** if the ask is "Odoo has X," the default answer is **no**. If a customer genuinely
needs deep generic ERP, the answer is still not "become Odoo" — it is to let their accountant use
their own tool for statutory filing while Atriom stays the operational source of truth and hands
over clean statements + ETA e-invoices. Integrate at the edge; don't absorb the horizontal ERP.

## 4. Deliberately parked — the Egyptian statutory cluster (three items, judged on *compliance*, not Odoo)

These three are **not "Odoo features"** — Odoo just happens to have them. They are correctness /
compliance for an Egyptian operator, so they get judged on that basis, not on parity.

1. **Employer social insurance + end-of-service gratuity** — *verify with the accountant first.*
   Payroll records only the amount **withheld from the employee**; the employer's own social-insurance
   contribution and accruing gratuity are not recorded. **This is the only item that might mean the
   books are wrong today.** If those costs are captured *somewhere* (even a manual monthly expense
   entry), Atriom is fine and it's merely un-automated. If they're **nowhere**, the P&L understates
   labour cost and the balance sheet understates liabilities — a correctness hole to close.
   → **Action: confirm with the accountant whether the employer contribution + gratuity are being
   captured at all.** That answer, not an Odoo comparison, decides the priority.

2. **Bank reconciliation** — a basic financial control (not an Odoo feature): tie the cash/bank ledger
   to an actual bank statement. Can be done manually outside the system short-term; it is the first
   thing an auditor asks for at scale. → Build when audit / real cash volume demands it.

3. **Egyptian tax depreciation (declining-balance) + a second (tax) book** — needed for the annual tax
   return; the accountant may compute it separately today. → Build if/when in-system tax depreciation
   is required; confirm rates against Law 91/2005.

## 5. How to reopen a generic module

Reopen only for one of:
- **A correctness regression** (a book is wrong, a guard breaks) — fix + regression test, same as any bug.
- **A confirmed customer/auditor requirement** that maps to a §3 or §4 parked item — then it's scoped
  work, not speculation.
- **The accountant's answer to §4.1** turning "un-automated" into "missing from the books."

Absent one of those, the generic layer stays frozen. The depth goes into property + facility.
