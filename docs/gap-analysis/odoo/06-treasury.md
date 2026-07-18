# Treasury / custody / cash — Atriom vs Odoo

> Domain 6 of the [Atriom vs Odoo gap analysis](README.md). Atriom side grounded in
> `GrantCustodyService`/`SettleCustodyService`, the custody models + journalizers, the direct-expense
> path, and [`docs/modules/25`](../../modules/25-treasury-custody.md) +
> [`docs/gap-analysis/25`](../25-treasury-custody.md). Bank-reconciliation absence and the custody
> asset-account posting were re-verified against source. *(verify)* = version-sensitive.

Legend: ✅ full · 🟡 partial · ❌ absent · ⏭️ on roadmap/deferred

## 1. Capability matrix

| Capability | Atriom | Odoo Community | Odoo Enterprise | Gap note |
|---|---|---|---|---|
| **Custody / عهدة** — cash in a custodian's hands tracked as an asset, settled by categorised receipts, outstanding derived, reversible | ✅ | ❌ | ❌ | **Atriom wins. Odoo ships no عهدة concept** — closest is bending Expenses or a cash advance, needing customization to model "asset until spent/returned." Localized, Egypt-market-fit. |
| Employee expense **claims / reports** | 🟡 | ✅ | ✅ | Odoo's Expenses app is employee-driven claim→approve→reimburse; Atriom's settlement is accountant-keyed against a pre-granted عهدة — no self-service claim, no reimbursement leg. |
| **Direct / petty-cash expense** (pay now, no payable) | ✅ | ✅ | ✅ | Parity. Atriom's `Expense` posts Dr expense / Cr cash\|bank immediately. |
| **Cash journals / multiple cash registers** | ❌ | ✅ | ✅ | Odoo supports many cash & bank journals; Atriom has a **single** cash + single bank distinction. Multi-treasury is TREAS Phase 2 ⏭️. |
| **Bank reconciliation (manual)** | ❌ | 🟡 *(verify)* | ✅ | **The real treasury gap.** Verified absent. Odoo Community reconciles statement lines to entries (leaner UI than Enterprise). |
| **Bank statement import / bank sync** | ❌ | 🟡 *(verify)* — file import (OFX/QIF/CSV/CAMT) | ✅ — automated feeds | No import path at all in Atriom; live sync is Enterprise-only in Odoo. |
| **Payment run / batch** (pay many bills, SEPA) | ❌ | 🟡 *(verify)* — largely Enterprise | ✅ | Atriom registers no batched supplier payment. |
| **Multi-currency treasury / FX** | ⏭️ (EGP only) | ✅ *(verify)* | ✅ — rate feed | Single-currency EGP; TREAS-2 deferred (blocked on: is anything billed USD/EUR?). ⏭️ |
| **Cash-flow forecast** (forward) | ❌ | ❌ | 🟡 *(verify)* | Atriom **has a historical cash-flow *statement*** (indirect, reconcile-by-construction, bilingual PDF) — just no forward forecast. Neither Community edition forecasts. |
| **Deposits / guarantees** (security-deposit register) | ✅ | 🟡 *(verify)* | 🟡 *(verify)* | Atriom has a purpose-built security-deposit ledger (`DepositTransaction`, leasing); Odoo has no native deposit register. |
| **Outstanding-advance tracking + aging** | 🟡 | 🟡 | 🟡 | Atriom derives per-custody outstanding correctly but has **no aging/exposure report** (open item D-83). Odoo ages AR/AP partners, not employee advances, without customization. |
| **Correction / void of a settlement** | ✅ | ✅ | ✅ | Parity. Atriom's Reverse = soft-delete IS the void (real-time GL void + causer/reason activity, lock-safe, refuses double-reverse). |

## 2. Architecture read

**The عهدة model is sound and genuinely differentiated.** Atriom treats a custody as a dedicated
asset account (`Custodies 11204001`, deliberately *not* AR, so the AR tie-out is untouched — verified
in `CustodyJournalizer`), grants Dr Custodies / Cr Cash|Bank, and nets it back down through
categorised settlements (Dr Expense-by-category / Cr Custodies) or cash returns. Outstanding is
**derived, never cached** (`amount − Σ settlements`), the over-settle guard re-reads under a
`lockForUpdate`, and the recently-added `reverse()` closes the one correction hole every other money
document already had — soft-delete doubles as the GL void because the settlement is a registered
child ledger source. A correct, race-safe, audit-complete implementation of a specific Egyptian
workflow. **Mapping it onto Odoo would require customization**: Odoo's Expenses models
employee-spends-then-claims, not company-cash-placed-then-settled-as-an-asset. **Keep it as-is; it's
market-fit, not a gap.**

**The real treasury gap is bank reconciliation, and it should be flagged prominently.** Every
capability above assumes cash and bank balances no one is proving against a statement. Atriom posts
to `Cash 11101001` / `Bank 11102001` from custodies, expenses, payments, and payroll, but there is
**no path to import a bank statement or match it to the ledger** — so the bank GL balance is asserted
by construction, never verified against reality. For a mall operator moving real supplier and payroll
cash, that's the one treasury control auditors and owners ask for first. Odoo answers it (manually in
Community, feeds in Enterprise); Atriom doesn't answer it at all.

**Single-currency and single-treasury are honest, bounded deferrals** — both explicitly on the
roadmap (TREAS Phase 2 multi-treasury, TREAS-2 multi-currency, blocked on whether anything is billed
in USD/EUR). Fine to leave until demand is confirmed; not silent gaps.

**What to reconsider (small):** the outstanding-advance **aging/exposure report** (D-83). Outstanding
is computed correctly per custody, but there's no portfolio view where an accountant would notice a
custody drifting or a divergence surfacing — the module posts money to the GL through two journalizers
but has no reporting surface for its own core metric.

## 3. Top 5 real gaps (ranked for a mall operator)

1. **Bank reconciliation** — no way to match the cash/bank ledger to an actual bank statement, so
   booked cash is never verified against the bank; the single most important treasury control and the
   clearest Odoo advantage.
2. **Cash journals / multiple treasuries** — one cash + one bank only, so an operator running several
   petty-cash boxes or bank accounts per mall can't segregate them (TREAS Phase 2).
3. **Employee expense claims/reimbursement** — no self-service submit-approve-reimburse flow; all
   spend is accountant-keyed against a pre-granted عهدة, missing the everyday staff-reimbursement case.
4. **Outstanding-advance aging/exposure report** — per-custody outstanding is correct but there's no
   portfolio/aging view, so stale advances and GL-vs-outstanding divergence have nowhere to surface (D-83).
5. **Bank statement import + payment batches** — no file import (OFX/QIF/CAMT) and no batched supplier
   payment run, both routine in Odoo for an operator paying many vendors.

**Net:** Atriom is *ahead* of Odoo on the localized عهدة and security-deposit registers and at parity
on direct expenses and settlement correction; it trails on the generic treasury plumbing — bank
reconciliation above all, then multi-treasury/journals and expense claims.
