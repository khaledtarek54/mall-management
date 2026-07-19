# Plan — CAM recovery-clause engine (Yardi's crown jewel)

> **Status:** design complete (judged 3-design synthesis, 2026-07-19); awaiting operator decisions (§9).
> Strengthen item #2 from the [competitive gap analysis](../gap-analysis/competitors/02-cam-turnover-rent.md).
> Closes the CAM recovery-clause gaps: caps, admin fee, gross-up, exclusions, configurable basis, GL-sourced pool.

## 1. Architecture

**Typed columns + one focused effective-dated `lease_cam_terms` table** (not a polymorphic JSON clause engine —
that's the "grow-toward-Odoo" anti-pattern). The **correctness keystone**: `cam_allocations.allocated_amount`
stays the **UNCAPPED** recovery share; **caps hit only the true-up**; the `BooksReconciliationService` CAM check
is re-derived to tie `Σ allocated_amount` against `(recoverable_expense ?? total_actual_expense) × coverage` —
so exclusions/gross-up/GLA/caps all keep the pool-partition invariant instead of red-lighting `billing:reconcile`.
The admin fee rides the **existing Invoice GL source** as a new `cam_admin_fee` invoice line (14% VAT, its own
revenue account) — **zero new `LedgerPoster::JOURNALIZERS` entries**. **Every new field defaults to a no-op**, so a
mall with no clauses is byte-identical to today (the highest-priority regression gate).

## 2. Clause application ORDER (the crux)

```
SOURCE(ledger, Phase 2) → EXCLUDE → GROSS-UP(GLA-only) → ALLOCATE → CAP(on cost) → ADMIN-FEE → TRUE-UP
```
- **Exclude** first (never gross-up/distribute money the landlord can't recover).
- **Gross-up** before allocate, **gated to the GLA basis** (under occupied-area, Σ share = 1 already recovers
  100% → uplift forced to 0, enforced by validation + form-disable).
- **Cap on the cost** (`capped_cost = min(allocated, ceiling)`; `cap_absorbed = allocated − capped_cost` — the
  landlord's auditable cost of the cap). `allocated_amount` untouched → the tie-out holds.
- **Admin fee** on the (net) capped cost; **true-up last**: `true_up = round(capped_cost − estimated_paid, 2)`
  — the estimate is **always split on occupied-area** (real cash billed monthly), and the true-up cost path is
  byte-identical to today (positive → immediate issued recovery invoice; negative → auto-applied FIFO credit note).
  The admin fee is a **sibling** (a positive `cam_admin_fee` line), never folded into `true_up_amount` — so it never
  contaminates the credit-note path.

## 3. GL / VAT (admin fee)

The fee is a **line on the existing CAM recovery Invoice** — no new GL source:
1. `InvoiceItemType::CamAdminFee = 'cam_admin_fee'` (no migration — `type` is string(32)).
2. `InvoiceJournalizer::REVENUE_ROLE += 'cam_admin_fee' => 'cam_admin_fee_revenue'`.
3. Chart + `AccountMappingSeeder`: `cam_admin_fee_revenue => 41108001` (next free 411… leaf; a **distinct** account so the fee reads as margin).
4. i18n `admin.enums.invoice_item_type.cam_admin_fee` (EN/AR).

Entry: Dr AR (cost+fee+fee·0.14) / Cr CAM Recovery (cost, VAT-exempt) / Cr CAM Admin-Fee (fee) / Cr VAT Payable
(fee·0.14). Cost recovery stays **VAT-exempt** (the monthly estimate already carried the 14%); the admin fee is
the **service the landlord sells** → **14%** (flag for the accountant, like the other tax treatments). **After
adding any line, rebuild the header from items** (`subtotal = Σitems`, `vat = Σvat`, `total = subtotal+vat`) THEN
`recomputeTotals()` — never rely on recomputeTotals for the header (it only touches paid/balance/status).

Tie-out test (mandated): drive the **real** `bill()` on a clause-configured pool → `accounting:sync-ledger` →
assert the admin-fee revenue + VAT posted, AR ties, `billing:reconcile` green (not `LedgerPoster::post()` directly).

## 4. Build sequence (each slice's defaults are no-ops → a no-clause mall is byte-identical)

- **Slice 0 — tie-out readiness (behaviour-neutral):** add `recoverable_expense`(null) + `basis_denominator` +
  `allocated_basis_total`; rewrite the CAM books-check RHS to `× coverage`. Coverage = 1 → nothing changes. De-risks the keystone.
- **Slice 1 — admin fee (lowest-risk real revenue):** typed columns + `InvoiceItemType` case + `REVENUE_ROLE` +
  account 41108001 + i18n + `bill()` branches (incl. zero-true-up-but-fee-owed → fee-only invoice) + header-from-items + GL tie-out.
- **Slice 2 — caps:** `lease_cam_terms` + `resolveCap` (absolute / %YoY compounding-or-base-year / both=min) +
  `capped_cost`/`cap_absorbed`; true-up on capped cost; `allocated_amount` stays uncapped.
- **Slice 3 — configurable basis + gross-up + flat exclusions:** `occupied_area`(default) + `gla_area`; gross-up GLA-gated; `non_recoverable_amount`.
- **Slice 4 (Phase 2) — GL-sourced pool:** `source_mode='ledger'` + `CamPoolSourcingService` + `cam_pool_sources`
  drill-through + category exclusions. Build when the accountant wants provenance.

## 5. Data model (summary)

- `cam_expense_pools` += `source_mode`, `allocation_basis`, `recoverable_expense`(null=fallback to
  total_actual_expense), `non_recoverable_amount`, `gross_up_target_pct`, `admin_fee_pct`, `admin_fee_on_net`,
  `basis_denominator`, `allocated_basis_total`, provenance columns. `total_actual_expense` keeps its meaning (freeze anchor).
- `cam_allocations`: **wire the unread `cap_amount` + `exclusions` columns** (exclusions = the per-allocation clause
  trace) + `capped_cost_amount`, `cap_absorbed_amount`, `basis_measure`, `admin_fee_amount`, `admin_fee_vat_amount`,
  `billed_admin_fee_charge_id`. `allocated_amount` stays uncapped; `true_up_amount = capped_cost − estimated_paid`.
- `lease_cam_terms` (new, effective-dated per-lease): cap_type/absolute/yoy_pct/compounding/base + basis_override + fixed_share.
- `cam_pool_sources` (new, Phase 2): one row per contributing posted-expense account (drill-through).

## 6. Isolation / tests / docs

Register `LeaseCamTerm` (lease→asset) + `CamPoolSource` (pool→asset) in `PropertyIsolation`; `assertAssetInScope`
on any exposed asset/lease. Tests: **CamNoClauseParityTest** (byte-identical no-clause — the keystone), the
re-derived tie-out, clause scenarios (exclusion/GLA/gross-up/cap flips-to-credit/YoY), settlement invariants,
GL tie-out on a clause pool, an order-lock golden test. Docs: modules/08-cam, money/04-cam-reconciliation,
gap-analysis/competitors/02 (flip the ❌ rows), modules/21 (worked example), chart doc, isolation doc, census + manifest.
Extend the [[project_cam_trueup_invariant]] memory (admin fee = sibling, never folded; allocated stays uncapped).

## 7. Decisions (operator) — recommended defaults

| # | Decision | Recommended default | Why |
|---|---|---|---|
| 1 | **Cap type** | **None** (5% compounding YoY as the per-anchor lease template) | Anchors negotiate a controllable-increase cap; off-by-default keeps existing leases unchanged |
| 2 | **Admin fee** | **10%, on the NET capped-cost, VAT 14%**, to a dedicated `cam_admin_fee_revenue` | 10% = market floor; net base can't re-breach a cap; a management service is a taxable supply; distinct account = visible margin |
| 3 | **Gross-up target occupancy** | **None** (95% only when the GLA basis is elected) | Gross-up only makes sense with a vacancy-bearing denominator |
| 4 | **Default excluded categories** | **Capex + owner management fee** | Recovering capex or the owner's own fee from tenants is the classic audit dispute |
| 5 | **Default allocation basis** | **Occupied leased area** (today) | Recovers 100% of the pool; the tested, zero-risk behaviour; GLA is a deliberate per-property choice |
