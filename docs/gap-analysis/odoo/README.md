# Atriom vs Odoo — gap analysis of the generic modules

> **What this is.** A capability + architecture comparison of Atriom's *generic ERP* modules
> against **Odoo Community and Enterprise**, for the owner. Produced 2026-07-18.
>
> **Scope — the generic overlap only.** The modules that have a fair Odoo analogue:
> Accounting/GL, AR (invoicing/payments/credit notes), AP (vendor bills), Inventory, Fixed
> Assets, HR/Payroll, Treasury/Custody, Procurement. The property-specific modules —
> **leasing, CAM, percentage rent, ETA e-invoicing, facility maintenance, announcements** — are
> deliberately **out of scope**: Odoo ships no equivalent, so they're Atriom's differentiators,
> not gaps. See the six domain deep-dives linked below.
>
> **Two lenses per domain, both editions.** Each domain has a capability matrix
> (Atriom · Odoo Community · Odoo Enterprise) *and* an architecture read (is what we built
> sound vs how Odoo does it). Odoo claims about the Community/Enterprise line are marked
> *(verify)* where they're version- or localization-sensitive; the Atriom side is grounded in
> the code and the [round-2 gap analysis](../000-progress.md), and the headline claims below
> were re-verified against source.

---

## 1. The headline, in one paragraph

**Atriom is not a smaller Odoo — it's a narrower, deeper one.** On the generic modules it
either matches Odoo or, in several places, matches Odoo **Enterprise** (the paid edition) while
Odoo **Community** ships nothing: a built-in fixed-asset depreciation engine, per-employee
payslips with GL posting, always-on perpetual-inventory costing, a cash-flow statement, and
GRNI clearing. It's also **stricter than Odoo** in a few controls — chart-of-accounts
guardrails, the tie-out reconcile gate, the period-close sync gate, a fail-closed approval
ladder. And it ships things Odoo **doesn't model at all**: the Egyptian **عهدة (custody)**
workflow, a security-deposit register, vendor **SLA-penalties charged onto the bill**, and
hard-coded Egyptian VAT + bilingual EN/AR books.

Where Odoo genuinely leads is **generic-ERP breadth** — the plumbing a product serving every
industry accumulates: bank reconciliation, multiple valuation methods, lot/serial tracking,
reorder-driven auto-purchase, a salary-rule engine, multi-currency, analytic cost centers,
budgets. Most of that breadth is either **Enterprise-gated** (you'd pay for it) or **irrelevant
to a single-entity EGP mall operator** (multi-currency, consolidation). A handful is genuinely
worth having — and they cluster, which is the useful finding.

---

## 2. Where Atriom is ahead (its moat — don't trade it away)

| Capability | vs Odoo | Why it matters here |
|---|---|---|
| **Property scoping (`asset_id`) native** | Odoo needs a multi-company workaround | One operator runs many malls off one chart, one catalog; Odoo would force per-company duplication. |
| **عهدة / custody** (cash-in-hand as an asset, settled by receipts, reversible) | Odoo ships **nothing** | A specific Egyptian workflow. Replicating it in Odoo = custom development. |
| **Fixed-asset depreciation engine** | = Odoo **Enterprise**; Community has none | Register + straight-line + disposal gain/loss, scheduled monthly, GL-posted. |
| **Payroll: payslips + GL + advances (سلف)** | = Odoo **Enterprise**; Community has none | Bilingual payslip PDFs, a first-class advance *receivable* with repayment netting. |
| **Perpetual inventory → GL, always-on** | Odoo auto-valuation is a config / Enterprise | Every receipt/consumption posts to the books automatically, property-dimensioned. |
| **GRNI clearing** | Parity with Enterprise; **ahead of Community** | FIFO allocation across bills, capped at received value — Community leaves it manual. |
| **Vendor SLA-penalty-on-bill + service contracts** | Not core Odoo | Deducts a late penalty straight onto the vendor's AP bill; auto-expiring contracts. |
| **Cash-flow statement** | Ahead of Community; = Enterprise | Indirect method, reconcile-by-construction, bilingual PDF. |
| **Stricter controls** | Beyond Odoo's defaults | CoA leading-digit↔type guardrails, GL↔AR/AP tie-out gate, period-close sync gate, fail-closed strictest-band approval ladder. |
| **Egyptian fit** | Odoo needs the EG localization | 14% VAT / rent-exempt / 5% levy hard-coded correct; bilingual EN/AR + RTL; ETA e-invoicing built in (out of scope here). |

**The through-line:** Atriom layered a real double-entry GL *under* an existing property/billing
engine, and then hardened it with controls a generic ERP doesn't need because its move *is* the
record. That's a legitimate, well-executed architecture — see §4.

---

## 3. Where Odoo is ahead — the real gaps, ranked and clustered

These are the honest gaps across all six domains, ordered by value to a **single-entity EGP mall
operator**. The top cluster is small and specific.

### 🔴 The three that actually matter

1. **Bank reconciliation** *(Accounting + Treasury)* — **the #1 cross-cutting gap.** There is no
   bank-statement import or matching *anywhere*: the cash/bank GL balance is asserted by
   construction (from payments/expenses/custody/payroll) and never verified against a real
   statement. It's the first control an auditor or owner asks for, and it surfaced independently
   in two domains. Odoo answers it (manual in Community, bank feeds in Enterprise).
2. **Egyptian tax depreciation** *(Fixed Assets)* — depreciation is **straight-line only**, but
   Egyptian income tax (Law 91/2005) is **declining-balance / pool-based** *(rates verify)*. So
   Atriom cannot produce a tax-basis depreciation figure at all, and there's no second (tax) book.
   Notably Odoo Enterprise doesn't fully solve the second-book problem either — but the compliance
   need is real.
3. **Employer social insurance + end-of-service gratuity** *(HR — correctness, not features)* —
   verified: payroll posts only the *withheld employee* side; the **employer's own
   social-insurance contribution is never expensed or accrued**, and there's no gratuity accrual.
   These are mandatory Egyptian employer obligations, so the books **understate labour cost and
   liabilities today**. This is a correctness gap, not a missing feature.

### 🟡 Worth doing, module by module

- **Accounting**: per-property year-end close (the known [F-80](../21-general-ledger.md) — closing
  posts consolidated-only, so each owner's retained earnings reads 0 forever); a VAT-return report;
  comparative / period-over-period statements; GL-wide budget-vs-actual.
- **Inventory**: weighted-average costing (closes the standard-cost drift when import/FX prices
  move — *not* FIFO, which is over-engineering here); reorder-driven auto-purchase; **finish the
  dead internal-transfer stub**; lot/serial + UoM conversion where relevant.
- **Purchase**: multi-quote / bid comparison for **capex** (the tier-3 spend where owners most want
  "3 quotes compared"); a purchase-spend report; a 3-way-match tolerance/variance hold.
- **HR**: statutory tax/insurance **rate automation** (a mall-scale helper, not Odoo's rule
  engine); advance repayment *via* payroll deduction; leave/attendance (both are Odoo Community).
- **Treasury**: multiple cash journals / treasuries; employee expense claims; an
  outstanding-advance aging report.

### ⏭️ Real in Odoo, but N/A or premature here

Multi-currency & FX revaluation, multi-entity consolidation, drop-shipping, recruitment/appraisals,
Odoo's full salary-rule engine — all either irrelevant to a single-entity EGP mall operator or
Enterprise-gated features you'd pay for and still customize for Egypt.

---

## 4. The architectural verdict

**Atriom's design is sound, and in several places deliberately stricter than Odoo. The
recommendations above are completeness gaps, not rewrites.** Three cross-cutting notes:

- **The journalizer + sweep GL is a legitimate CQRS choice with a known cost.** Odoo makes the
  accounting *move* the primary object — an invoice **is** its journal entry. Atriom keeps the
  business document primary and *derives* a balanced entry via a per-source journalizer,
  reconciled by an idempotent, lock-safe `LedgerPoster::sync()`. This is the right pattern for
  layering a GL under an existing billing engine, and the discipline around it (one registry,
  a conformance gate, the tie-out) is above average. **Its inherent cost is a reconciliation
  surface Odoo doesn't have** — and that surface is *exactly* where this session's money bugs
  lived (the SLA-penalty dispatch gap, F-79's `entry_date` the reconciler didn't compare, F-101's
  double-cleared GRNI a control-account tie-out can't see). Keep the pattern; keep watching that
  class.
- **One strong dimension (`asset_id`) vs Odoo's analytic accounting.** For a mall whose only real
  cost object is the property, one strong axis covers ~90% of need. Don't rebuild analytic
  accounting; the honest missing axis is departmental/opex cost centers, if ever asked for.
- **Derived-truth everywhere is the consistent, correct spine.** On-hand, outstanding, accumulated
  depreciation, `recomputeTotals`, `settled()` — all derived, never cached. This is more auditable
  than a running-balance field and it's applied uniformly. Keep it; hold the invariant that
  nothing writes those aggregates directly.

---

## 5. The domain deep-dives

Each has the full capability matrix, the architecture read, and its own top-5 gaps.

1. [Accounting core — GL / AR / AP](01-accounting.md)
2. [Inventory & stock](02-inventory.md)
3. [Purchase / procurement & vendors](03-purchase.md)
4. [Fixed assets & depreciation](04-fixed-assets.md)
5. [HR / employees / payroll](05-hr-payroll.md)
6. [Treasury / custody / cash](06-treasury.md)

---

## 6. How this feeds the roadmap

The 🔴 cluster in §3 is a short, specific list — **bank reconciliation, Egyptian tax depreciation
(+ a tax book), and the employer-SI / gratuity correctness gaps.** Those three are the ones a
mall operator's accountant and auditor will actually raise, and they don't overlap with anything
Atriom already does well. They belong in [ROADMAP.md](../../ROADMAP.md) as their own cluster —
"generic-ERP parity for the Egyptian statutory floor" — separate from the property-specific FRD
work. The 🟡 items are genuine but can wait; the ⏭️ items should be explicitly declined so nobody
mistakes Odoo's breadth for a to-do list.

> **A caution on the Odoo side.** These drafts distinguish confident Odoo-core facts from
> *(verify)* claims that are version- or localization-sensitive. Before quoting a specific
> Community-vs-Enterprise boundary to the owner (especially bank-statement handling, blanket
> orders, and Egypt payroll localization), confirm it against the exact Odoo version in question.
> Exact Egyptian tax-depreciation rates should be checked against Law 91/2005 and its executive
> regulations.
