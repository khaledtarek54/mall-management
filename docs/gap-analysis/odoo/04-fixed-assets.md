# Fixed assets & depreciation — Atriom vs Odoo

> Domain 4 of the [Atriom vs Odoo gap analysis](README.md). Atriom side grounded in
> `DepreciationService`/`DisposeFixedAssetService`, the models + journalizers, and
> [`docs/modules/23`](../../modules/23-fixed-assets.md) + [`docs/gap-analysis/23`](../23-fixed-assets.md).
>
> **Headline:** Atriom ships a working double-entry depreciation *engine*. In Odoo that entire
> capability (`account_asset`) lives in **Enterprise (paid)** — **Odoo Community has no automated
> asset depreciation at all.** So the correct baseline for most of this domain is Atriom-vs-Enterprise;
> against Community, Atriom is strictly ahead. The real gaps below are all vs *Enterprise*.
> Odoo facts from current versions (14–18); less-certain items marked *(verify)*.

Legend: ✅ full · 🟡 partial · ❌ none · ⏭️ N/A.

## 1. Capability matrix

| Capability | Atriom | Odoo Community | Odoo Enterprise | Gap note |
|---|---|---|---|---|
| Asset register | ✅ | ❌ (no asset app) | ✅ | Community has nothing; Atriom matches Enterprise's register (per-property scoped). |
| **Straight-line depreciation** | ✅ | ❌ | ✅ | Parity with Enterprise; Community absent. |
| **Declining-balance / degressive** | ❌ | ❌ | ✅ | **Real gap vs Enterprise** — matters for Egyptian *tax* depreciation (§3). |
| **Degressive-then-linear** | ❌ | ❌ | ✅ | Real gap vs Enterprise. |
| Units-of-production | ❌ | ❌ | ❌ *(verify)* | Neither does UoP natively — non-differentiator, low priority. |
| **Asset categories/models w/ default accounts + rate + life** | ❌ (free-form `category` text) | ❌ | ✅ | Real gap; Atriom's `category` is a bare label — no default accounts, method, or life. |
| Automated periodic posting | ✅ (cron, 28th monthly, idempotent) | ❌ | ✅ | Atriom matches Enterprise; Community has none. |
| **Prorata temporis / mid-month** | ❌ (whole-month charge) | ❌ | ✅ *(verify default)* | Real gap — first/last month not prorated; disposal-month convention undefined ([F-87/F-88](../23-fixed-assets.md)). |
| Salvage value | ✅ | ❌ | ✅ | Parity. |
| **Asset revaluation / modification** | 🟡 (re-cost guarded; no revaluation journal) | ❌ | ✅ (Modify → recompute board) | Atriom permits a downward re-cost only if base ≥ accumulated; no formal revaluation entry, no upward modification. |
| **Disposal with gain/loss** | ✅ (correct, GL-posted, immutable) | ❌ | ✅ | Parity — balanced in every branch. |
| Componentization | ❌ | ❌ | ❌ *(verify)* | Neither has first-class component depreciation — non-differentiator. |
| **Deferred revenue / deferred expense (same engine)** | ❌ | ❌ | ✅ | Real gap vs Enterprise; could matter for lease key-money / upfront-fee amortization. |
| **Tax-vs-book depreciation (multi-book)** | ❌ | ❌ | ❌/🟡 (localization/3rd-party) *(verify)* | **Neither** natively — but Egyptian tax needs a second basis (§3). Shared gap, high local value. |
| Catch-up for a missed period | ❌ ([F-87](../23-fixed-assets.md)) | ❌ | ✅ (dated board lines wait) | Real gap — a failed cron silently stretches useful life a month past. |
| Reporting (depreciation board/schedule) | 🟡 (register + posted-history) | ❌ | ✅ (forward board) | Atriom shows *posted* history, not a forward projected schedule across the asset's life. |

## 2. Architecture read

**The design is sound and, structurally, better-founded than a bolt-on.** Three choices are
genuinely good and should be **kept**:

- **The derived-ledger engine.** Accumulated depreciation is `SUM(depreciation_entries.amount)` —
  never a cached column — mirroring the GL/inventory derived-truth pattern; more auditable than a
  running-balance field. The idempotent, lock-safe monthly `run()` (`lockForUpdate` + a re-checked
  `(asset, month)` stamp inside the transaction + a DB unique) is the scheduled-scan invariant done
  correctly, and the forward `min(monthly, remaining)` clamp cleanly floors NBV at salvage.
- **The GL integration.** Three journalizers (acquisition, monthly charge, disposal) post through
  the same self-healing sweep as inventory/marketing, touch only asset/expense/income accounts
  (never AR/AP, preserving the close tie-out), and the parent-lifecycle cascade keeps each child
  source's entries in lock-step. This is the part Odoo Community simply cannot do without Enterprise.
- **The re-cost guard** ([`assertRecostValid`](../23-fixed-assets.md)) — refuses a cost/salvage pair
  whose base would fall below posted accumulated depreciation, closing the negative-NBV hole (F-86).

**What straight-line-only costs, and what to reconsider.** The single method is the one
architecturally significant gap. Odoo Enterprise offers linear / degressive / degressive-then-linear
per asset or per model; Atriom hard-codes straight-line and carries a `method` column that only ever
holds `straight_line`. For ordinary *book* depreciation, straight-line is defensible and what most
operators use. The pressure comes from **tax**: Egyptian corporate income tax (Law 91/2005) uses
**declining-balance pools** for most assets — broadly 50% for computers/IT/software and 25% for other
machinery, 5% straight-line for buildings *(verify exact rates)*. So a compliant operator keeps books
straight-line but computes tax depreciation on a *diminishing-value* basis — which Atriom cannot
express at all, and which even Odoo Enterprise doesn't fully solve (no clean second/tax book). So
degressive support is worth adding, but its real value is only unlocked alongside a **second
depreciation basis**.

The remaining gaps are lower-stakes and mostly *classification/period* rather than *money*: no
prorata means first/last and disposal months land whole (F-87/F-88 — net income unaffected, only
period placement); no catch-up means a failed cron silently stretches the schedule (an operational
reliability gap, cheap to fix with a back-fill loop + alert); the free-form `category` should become
an **asset model/category** carrying default accounts, method, life and rate. Revaluation is 🟡 — the
guarded re-cost is a safety measure, not a real revaluation journal, and there's no upward path. None
are architectural dead-ends; the derived-ledger engine can absorb all of them as extension points.

## 3. Top 5 real gaps (ranked, Egyptian-tax-weighted)

1. **Declining-balance / degressive method** — Egyptian income-tax depreciation is pool-based
   diminishing-value (≈25% general / 50% IT; 5% SL buildings *(verify rates)*), and Atriom is
   straight-line only, so it cannot produce tax-basis depreciation at all — the highest-value gap.
2. **A second (tax) depreciation basis / multi-book** — book stays straight-line while the tax return
   runs declining-balance; Odoo Enterprise doesn't solve this cleanly either, but Egyptian compliance
   needs it, and it's the natural partner to #1.
3. **Prorata temporis + a defined disposal-month convention (F-87/F-88)** — prorate the first/last
   (and disposal) month so period placement is deterministic; low money impact, real audit accuracy.
4. **Catch-up for a missed depreciation month + alert (F-87)** — a failed cron silently stretches
   useful life with no signal; a back-fill loop plus a monitoring alert closes it cheaply.
5. **Asset categories/models with default accounts, method, life & rate** — replace the free-form
   `category` string with real asset models, for data consistency and faster onboarding at scale.

*Uncertainty flags: the Community-has-no-asset-depreciation claim is confident for current Odoo;
`account_asset` being Enterprise-only, prorata defaults, absence of native UoP/componentization/
multi-book, and the unified deferred-revenue/expense engine are marked* (verify) *where noted. Exact
Egyptian tax rates should be confirmed against Law 91/2005 and its executive regulations.*
