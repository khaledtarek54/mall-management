# Accounting core (GL / AR / AP) — Atriom vs Odoo

> Domain 1 of the [Atriom vs Odoo gap analysis](README.md). Atriom side grounded in
> `app/Services/Accounting/*`, the models, and [`docs/modules/21`](../../modules/21-general-ledger.md)
> + [`docs/gap-analysis/21`](../21-general-ledger.md). Odoo: core behaviour stated confidently;
> version/localization-specific claims marked *(verify)*. Frame: a **single legal entity, EGP-only,
> multi-property mall operator**. "Community" = Odoo Invoicing app; "Enterprise" = full Accounting app.

## 1. Capability matrix

Legend: ✅ full · 🟡 partial · ❌ missing · ⏭️ N/A here.

| Capability | Atriom | Odoo Community | Odoo Enterprise | Gap (who's ahead, and does it matter here) |
|---|---|---|---|---|
| Chart of accounts (tree, bilingual, Egyptian codes) | ✅ | ✅ (l10n_eg) | ✅ | **Even.** Atriom's derived-parent + leading-digit↔type guardrails are stricter than Odoo's free-form CoA; bilingual EN/AR names are a genuine edge for an Egyptian accountant. |
| Double-entry journals / journal items | ✅ | ✅ | ✅ | **Even.** Both enforce Σdr=Σcr. |
| Manual entries + post / void (reverse) | ✅ | ✅ | ✅ | **Even.** Atriom voids-not-edits with a linked reversing entry — same discipline as Odoo. |
| Fiscal periods & period close (lock) | ✅ | 🟡 *(verify)* | ✅ | **Slight Odoo (Ent).** Atriom has real per-month open/closed periods + a pre-close sync gate; Odoo uses **lock dates**. Community period locking limited *(verify)*. |
| Year-end close → retained earnings | 🟡 | ✅ | ✅ | **Odoo, architecturally.** Odoo auto-rolls P&L via a **Current-Year-Earnings** account — no manual closing entry. Atriom's closing entry is **consolidated-only** ([F-80](../21-general-ledger.md)): per-property balance sheets never see retained earnings. |
| Trial balance | ✅ | 🟡 | ✅ | **Even/Odoo.** Atriom ties out + property-scoped; rich drill/pivot is Enterprise. |
| General ledger / account statement | ✅ | 🟡 | ✅ | **Even.** Running balance per account, property-scoped. |
| Income statement (P&L) | ✅ | 🟡 | ✅ | **Even.** Per-property + consolidated + bilingual RTL PDF. |
| Balance sheet | ✅ | 🟡 | ✅ | **Even.** |
| Cash-flow statement | ✅ | ❌ | ✅ | **Atriom ahead of Community.** Indirect method, reconcile-by-construction, code-range classified. |
| Comparative / period-over-period | ❌ | ❌ | ✅ | **Odoo (Ent).** Atriom is single-period only. Owners expect vs-last-year — real, low-effort gap. |
| AR invoicing + aging | ✅ | ✅ inv / 🟡 aging | ✅ | **Even.** Plus lease/CAM/%-rent billing Odoo can't do natively. |
| AP bills + aging | ✅ | ✅ bills / 🟡 aging | ✅ | **Even.** Vendor bills + payments + AP tie-out. |
| Credit notes | ✅ | ✅ | ✅ | **Even.** Atriom folds credits into `credit_applied_amount`; Odoo issues a reversing move. |
| Payment matching / allocation | ✅ | ✅ | ✅ | **Even.** One receipt across many invoices via a pivot (captured-only), with per-row + cross-tenant guards. |
| **Bank reconciliation** (statement import/match, feeds) | ❌ | 🟡 *(verify)* | ✅ | **Odoo (Ent). Biggest genuine control gap.** No statement import/matching at all — cash is only ever as accurate as manually-entered payments. |
| Multi-currency (txn) | ⏭️ | ✅ | ✅ | **N/A here.** EGP throughout. |
| FX revaluation (unrealized) | ⏭️ | ❌ | ✅ | **N/A here.** |
| Analytic accounting / cost centers | 🟡 | ✅ | ✅ | **Odoo.** Atriom carries `asset_id`/`tenant_id`/`lease_id` on lines but only `asset_id` is a first-class axis; no free-form cost center. Odoo's analytic is multi-axis, in Community. For property-centric books the single axis mostly suffices. |
| Budgets vs actual | 🟡 | ✅ *(verify)* | ✅ | **Odoo.** Atriom budgets are marketing-fund-only; no GL-wide budget-vs-actual. |
| Tax / VAT computation (output + input) | ✅ | ✅ | ✅ | **Even.** 14% service charge, base rent exempt, input-VAT-recoverable, 5% levy — hard-coded for Egypt. Odoo needs EG localization. |
| **VAT return** (periodic filing report) | ✅ | 🟡 *(verify)* | ✅ | **Even → Atriom.** Shipped 2026-08-11: `/admin/vat-return` reads output/input VAT from the ledger, splits standard vs exempt from the document *lines*, nets credit notes, and **proves the two sides tie** — a cross-check Odoo's tax report does not run. Odoo's own return needs Enterprise + an EG localization that does not exist. (ETA e-invoicing itself is out of scope — Atriom does it natively.) |
| Opening balances tool | 🟡 | 🟡 | 🟡 | **Even.** Both do it via a manual opening journal; neither has a strong wizard. Matters once, at go-live. |
| Audit trail | ✅ | 🟡 | ✅ | **Even/Odoo.** Spatie activity log on every accounting model + post-lock + void-not-edit. Odoo's inalterable/hash-chained trail is Enterprise + l10n *(verify)*. |
| Deferred revenue / expense | ❌ | ❌ | ✅ | **Odoo (Ent).** No spread-over-periods engine (rent recognised at issue). Modest value for a mall. |
| Consolidation (multi-legal-entity) | 🟡 | ❌ | ✅ | **Odoo (Ent).** Atriom consolidates across *properties* within one entity, not legal entities. N/A while Eltizam is one entity. |
| Fixed assets + depreciation | ✅ | ❌ | ✅ | **Atriom ahead of Community.** See the [Fixed Assets domain](04-fixed-assets.md). |

## 2. Architecture read

**Overall: sound, and in several places stricter than Odoo. The few things to reconsider are
completeness gaps, not structural mistakes.**

**Journalizer + sweep vs Odoo's move-based posting.** Odoo makes the accounting move the *primary*
object — an invoice/payment **is** its `account.move`. Atriom keeps the business document primary
and *derives* a balanced entry from it via a per-source `Journalizer`, reconciled by an idempotent
`LedgerPoster::sync()` (post / re-derive / void) that runs real-time-after-commit, on a
daily/weekly sweep, and on-demand. This is a **CQRS-style read-model** for the GL and it's well
built: one `JOURNALIZERS` registry is the single source all four dispatch paths derive from, a
conformance gate fails CI if a source is unregistered (born from the real SLA-penalty money bug),
`sync()` is lock-safe against double-posting, a trashed source self-heals to a void. The **cost**
vs Odoo is *latency and a reconciliation surface*: an entry can be briefly stale, and "is the GL in
step with the sub-ledger?" — which cannot arise in Odoo because the move *is* the record — becomes
something Atriom must actively defend (tie-out gate, `wouldChange` drift check, freshness banner).
They defend it well, but it's inherent extra machinery. **Keep it** — for a GL layered *under* an
existing billing engine, this is the right pattern. **The class of bug to keep watching** is exactly
the one round 2 found ([F-79](../21-general-ledger.md)): a source *field* the reconciler's
`matches()` doesn't compare (there, `entry_date`) silently strands entries — a failure mode Odoo
structurally can't have.

**Single `asset_id` dimension vs Odoo analytic accounting.** Property is a *dimension on the line*,
not a separate ledger — the correct call. But only `asset_id` is a first-class report axis, and
there's no free-form cost center. For a mall whose only meaningful cost object *is* the property,
one strong axis covers ~90% of need. **Keep it, don't rebuild analytic accounting**; the honest
missing axis is departmental/opex reporting, if ever asked for.

**Tie-out reconcile gate vs Odoo controls.** `billing:reconcile`'s GL↔AR/AP control-account tie-out
(gating close/filing with a non-zero exit) plus the pre-close sync gate is a **strong explicit
control Odoo doesn't need but also doesn't offer as a first-class gate**. A legitimate strength of
the derived-GL design. **Keep it.** Caveat (the GRNI/[F-101](29-procurement.md) note): a
balanced-but-wrong entry — e.g. double-cleared GRNI — is invisible to a *control-account* tie-out;
`--deep`'s `wouldChange`-over-everything is the right pre-filing backstop.

**Period-close model.** Sound and more defensive than Odoo's lock date (real month objects + a gate
that refuses to close while any document in the period is out of sync). **Keep it.** The one thing to
**genuinely reconsider is year-end close ([F-80](../21-general-ledger.md))**: posting the closing
entry with `asset_id = null` means per-property balance sheets read retained-earnings = 0 forever.
Odoo sidesteps this with an automatic current-year-earnings account. Atriom should post
**per-property** closing entries or document retained-earnings as consolidated-only — the clearest
architectural debt in this domain.

**`recomputeTotals` as AR single-source-of-truth.** Different from Odoo, and for AR arguably
*cleaner*. Atriom stores derived `paid_amount`/`balance` recomputed from captured payments +
`credit_applied_amount`, centralised and floored at zero. Odoo derives `amount_residual` from
reconciled move-lines (no stored aggregate to drift). Atriom keeps a *stored* aggregate — a drift
risk in principle — but mitigates it by funneling every path through the one method and never writing
the columns directly. Comparable integrity, easier for a non-accountant to reason about. **Keep it;
hold the invariant that nothing writes those columns directly.**

## 3. Top 5 real gaps (ranked for a mall operator)

*Three of the original five have since shipped. Kept below with what closed them, because a gap
list that only ever grows stops being read.*

1. ~~**Bank reconciliation**~~ — ✅ `ReconcileBankStatementService` + the Bank Statements resource:
   statement lines, matching, and an unexplained-line age.
2. ~~**Per-property year-end close ([F-80](../21-general-ledger.md))**~~ — ✅ per-asset closing
   entries, which were also the owner-statements prerequisite.
3. ~~**VAT return report**~~ — ✅ `/admin/vat-return` (2026-08-11), ledger-derived and tied out
   against the documents.
4. **Comparative / period-over-period statements** — single-period only, but owners expect
   "this year vs last year"; low effort, high perceived value. **The top remaining gap.**
5. **GL-wide budget vs actual** — budgeting exists only for the marketing fund; no opex
   budget-to-actual for the operator.

*Uncertainty flags: Community's exact bank-statement / period-lock / budget placement and the
inalterable-audit-trail edition line are l10n/version-sensitive — marked* (verify) *above. Confident:
the double-entry/move model, analytic accounting in Community, auto current-year-earnings, and
cash-flow/deferrals/consolidation being Enterprise-only.*
