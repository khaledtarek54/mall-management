# Module 23 — Fixed Assets & Depreciation

> **Round 2**, audited 2026-07-16 — first ever gap analysis. Spec:
> [../modules/23-fixed-assets.md](../modules/23-fixed-assets.md) · methodology: [000-plan.md](000-plan.md).
> Findings **reproduced live** against the dev DB inside a rolled-back transaction.

**Status: 🟡 Yellow** — F-86 fixed 2026-07-17; F-87/F-88 (period/classification, no lost money) remain. Depreciation math, the idempotent lock-safe run, the terminal-disposal guard
and the disposal double-entry are all correct (disposal balance checked algebraically in every
branch: loss → Dr = Cr = cost; gain → Dr = Cr = proceeds + accumulated). **One real defect, and it's
in the *edit* path, not the engine.**

`pest --parallel --filter='FixedAsset|Depreciation'` → **green**.

---

## 1. Findings

### 🔴 F-86. Editing an active asset's `acquisition_cost` after charges have posted → accumulated exceeds the base, NBV goes negative, depreciation stops forever · **FIXED 2026-07-17**
`app/Filament/Admin/Resources/FixedAssets/Pages/EditFixedAsset.php:22` (blocks only **disposed**
assets) · `app/Services/DepreciationService.php:90`

Nothing re-validates a new cost against **already-posted** charges. **Reproduced:**
```
cost=120000 life=12 → monthly=10000
after Jan–Jun: accumulated=60000  NBV=60000  (base=120000)
>>> correct acquisition_cost 120000 → 30000   (asset still ACTIVE, edit allowed)
base            = 30000
accumulated     = 60000    ⇐ EXCEEDS the depreciable base
NET BOOK VALUE  = -30000   ⇐ NEGATIVE
July run created 0 entries (remaining <= 0 → charging silently stops forever)
```
The sweep re-derives the acquisition to **Dr Furniture 30,000** while Accumulated Depreciation stays
**Cr 60,000** → the balance sheet carries **−30,000 of net fixed assets**, and `FixedAssetsTable`
renders NBV as −30,000.

This breaks doc rules 2 and 4 (*"accumulated tops out at cost − salvage, **never beyond**"*) — the
clamp only protects the **forward** run, never a retroactive cost change. Reachable by anyone with
`fixed_assets.edit`, and the model's own `updated` hook explicitly anticipates re-costing
(`FixedAsset.php:134`) — so this is a **supported operation with an unguarded outcome**.

**Fix (2026-07-17).** `DepreciationService::assertRecostValid()` refuses a cost/salvage pair whose
base (cost − salvage) would fall below accumulated depreciation — the single source of truth on the
math. Called server-side from `EditFixedAsset` (thrown as a `ValidationException` so it renders on
the field, never a 500) and mirrored as an inline form `rule()` so the operator sees it before
submit. The boundary (base = accumulated) is allowed — the asset is simply fully depreciated.
Guard: `tests/Feature/Regression/FixedAssetRecostGuardTest.php` + an edit-page case in
`FixedAssetResourceTest` (5 + 1); all fail without the guard, and the legitimate downward
correction is proven still to work and leave depreciation running.

> Note the interaction with **[F-79](21-general-ledger.md)**: `acquisition_date` is likewise
> editable on an active asset, and until F-79 was fixed a date change silently kept the entry in the
> old period. That half is now closed; the *cost* half (F-86) is not.

### 🟡 F-87. No catch-up for a missed month; disposal freezes the schedule wherever the cron left it
`app/Services/DepreciationService.php:58` · `DisposeFixedAssetService.php:32`

`run()` posts only the **requested** month and never back-fills. If the cron (28th monthly) fails in
March, March's charge is posted **by nobody** — the schedule silently stretches a month past useful
life, and nothing alerts. Related: `dispose()` flips `status` immediately and `run()` skips
non-active, so disposing on the 20th loses that month's charge while disposing on the 29th keeps it
— identical economics, different books.

Net income is unaffected in both cases (the amount migrates between Depreciation Expense and Loss on
Disposal), so this is **period/classification, not lost money**. No mid-month proration is claimed
by the doc.

### 🟡 F-88. `disposed_on` is unvalidated against `acquisition_date`
`app/Filament/Admin/Resources/FixedAssets/Tables/FixedAssetsTable.php:84` — a DatePicker with no
`minDate`. Disposing an asset acquired 2026-01-01 with `disposed_on = 2020-01-01` posts the
write-off into FY2020 — and `SyncLedgerCommand::ensureFiscalYears` will helpfully **open 2020** for
it — leaving 2020–2025 balance sheets showing negative Furniture while the acquisition sits in 2026.

---

## 2. Verified-correct — don't re-audit

- **`run()` is idempotent + lock-safe exactly as the invariant demands** — `lockForUpdate` then the
  `whereDate('period_month')` stamp re-checked **inside** the transaction on a freshly-loaded row,
  plus a DB unique `(fixed_asset_id, period_month)`. The clamp `min(monthly, remaining)` correctly
  prevents **forward** over-depreciation, and salvage floors NBV.
- **Terminal-disposal guard** — `DisposeFixedAssetService.php:29` locks and re-checks
  `status === 'active'` inside the transaction; negative proceeds rejected server-side (`minValue`
  is client-only).
- **Child-source parent-lifecycle cascade** (`FixedAsset.php:111`) — soft-delete stamps children with
  the parent's **exact** `deleted_at`, so restore targets only the rows *this* cascade trashed. The
  child-source windowed-sweep trap, handled properly.
- **Disposal double-entry balances in every branch** (checked algebraically, not assumed).

---

## 3. Test gaps

- ~~No test edits `acquisition_cost` below posted accumulated depreciation~~ — ✅ covered by
  `FixedAssetRecostGuardTest` + the edit-page case in `FixedAssetResourceTest`.
- Dispatch is genuinely proven — acquisition via `GlPostingSourcesScenarioTest:240`; depreciation +
  disposal via real services and a real `--all` sweep in `CrossModuleGlScenarioTest`. The direct
  `poster->post()` calls in `FixedAssetLedgerTest` are used for **arithmetic**, with reachability
  proven separately — a legitimate split. *(But see [D-66](21-general-ledger.md): that scenario
  test's assertions would hold even if nothing posted.)*

## 4. Deferred

- ~~**D-73**~~ — ✅ **F-86 fixed 2026-07-17.**
- **D-74** — F-87 catch-up for a missed depreciation month (+ alert), and decide the
  disposal-month convention.
- **D-75** — F-88 `minDate` on `disposed_on`.
