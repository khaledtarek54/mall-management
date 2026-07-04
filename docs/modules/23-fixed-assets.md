# Module 23 — Fixed Assets & Depreciation (الأصول الثابتة والإهلاك)

> **Status: Phase 2 shipped (GL posting).** Fixed-asset register + straight-line
> depreciation service + the monthly `accounting:post-depreciation` run + a
> property-scoped Filament `FixedAssetResource` (cost / monthly / accumulated / NBV
> columns, dispose, read-only depreciation schedule, "post this month" action) +
> `fixed_assets.*` RBAC + the `fixed_assets` module flag + **double-entry GL posting**
> (acquisition + monthly depreciation, via the sweep) + tests. Delivers the
> FRD/gap-analysis backlog item FA-1 — the chart already had the accounts (furniture,
> accumulated depreciation, depreciation expense) but they were **dormant** (nothing
> posted). This makes them **live**. The only remaining item is the disposal write-off
> entry (Phase 2b — see roadmap).

Property operators own real assets — furniture, HVAC, fit-out, IT, deep-clean
machines. This module keeps the **register** of those assets and recognises their
cost over time via **straight-line depreciation**, so the balance sheet shows net
book value and the P&L carries the monthly depreciation charge.

---

## 0. Design decisions

| Decision | Choice | Why |
|---|---|---|
| **Depreciation method** | **Straight-line** | (cost − salvage) ÷ useful-life-months; the standard default. Declining-balance is a future option. |
| **Schedule model** | **Entry ledger** (one `depreciation_entry` per asset per month; accumulated DERIVED) | Auditable + reconcilable — mirrors the GL / inventory "derived truth"; the monthly run is idempotent via a unique (asset, month). |
| **Scope** | **Per-property** (`asset_id`) | Each mall's fixed assets belong to it; scoped like units/leases/inventory. |
| **Acquisition funding** | `funded_from` (cash \| bank) | The credit side of the acquisition GL entry (Phase 2) — most fixed assets are paid, so this avoids inflating Accounts Payable. |

---

## 1. Domain model

### `fixed_assets` — the register (per property)
| Column | Meaning |
|--------|---------|
| `asset_id` | the property (FK, cascade) |
| `name` · `tag` | label + asset tag (**unique per property**) |
| `category` | free-form (furniture / HVAC / IT …) |
| `acquisition_date` · `acquisition_cost` · `salvage_value` | cost basis |
| `useful_life_months` | straight-line period |
| `method` | `straight_line` (only method today) |
| `funded_from` | `cash` \| `bank` — acquisition credit (Phase 2 GL) |
| `status` · `disposed_on` | `active` / `disposed` |

### `depreciation_entries` — the monthly charge ledger
| Column | Meaning |
|--------|---------|
| `fixed_asset_id` | the asset (FK, cascade) |
| `period_month` | first day of the depreciated month (**unique per asset**) |
| `amount` | that month's charge |
| `created_by_user_id` | audit |

---

## 2. Business rules

1. **Monthly charge** = `(acquisition_cost − salvage_value) ÷ useful_life_months`, rounded 2dp.
2. **Accumulated depreciation is derived** = `SUM(depreciation_entries.amount)`; net book
   value = `cost − accumulated`. Never a cached column.
3. **The run is idempotent + lock-safe**: one entry per (asset, month); each asset row
   is locked and re-checked inside its own transaction (the project scheduled-scan invariant).
4. **Never over-depreciates**: the final charge is clamped to the remaining depreciable
   base, so accumulated tops out at `cost − salvage` (never beyond).
5. **No charge before the acquisition month**, and **none once fully depreciated** or `disposed`.
6. **NOT-NULL money** — blank `acquisition_cost`/`salvage_value` coerce to 0 in the model.
7. **Posting respects property authority** — the scheduled `accounting:post-depreciation`
   run is portfolio-wide, but the admin **"Post this month"** button passes the operator's
   visible-property set (`TenantScope::visibleAssetIds()`), so a single-property accounting
   user can never post another mall's depreciation. `run(?period, ?assetIds)` — `null` = all.
8. **Property is scope-guarded on write** — create/edit re-validate the submitted `asset_id`
   against `visibleAssetIds()` server-side (`FixedAssetResource::assertAssetInScope`), closing
   the All-Properties-mode tamper hole even though the Select already scopes its options.

---

## 3. Services & commands

- **`DepreciationService`** — `monthlyAmount()`, `depreciableBase()`, `accumulatedFor()`,
  `netBookValue()`, and `run(?period, ?assetIds)` (the idempotent, lock-safe monthly poster;
  `assetIds` scopes to a property set, `null` = portfolio-wide).
- **`accounting:post-depreciation {--month=YYYY-MM}`** — runs the month's depreciation;
  scheduled monthly (28th 03:30). Idempotent, so a re-run is harmless.

---

## 3.5 GL posting (Phase 2)

Fixed assets post to the double-entry ledger through **two journalizers**, registered in
`LedgerPoster` and reconciled by the `accounting:sync-ledger` sweep — the same
self-healing, idempotent path inventory and marketing spend use (no entanglement with the
write path). Both credit/debit only asset/expense accounts — **never AR or AP** — so the
GL↔AR/AP tie-out that gates monthly close is unaffected (the GRNI lesson from module 22).

| Event | Source | Entry |
|-------|--------|-------|
| **Acquisition** | `FixedAsset` | Dr Furniture & Equipment `12101001` / Cr **Cash `11101001` \| Bank `11102001`** (per `funded_from`) |
| **Monthly depreciation** | `DepreciationEntry` | Dr Depreciation Expense `51107001` / Cr Accumulated Depreciation `12201001` (contra-asset) |

- **Cash/Bank, not AP** — most fixed assets are paid on acquisition and a fixed asset has
  no vendor bill; crediting the AP control (which ties out to vendor bills) would falsely
  fail the reconcile. Same reasoning as inventory's GRNI clearing account.
- **Mappings:** `furniture_equipment`, `accumulated_depreciation` (added to `AccountMappingSeeder`;
  `depreciation_expense`, `cash`, `bank` already existed). Re-point from the UI without code.
- **Parent-lifecycle cascade (self-healing under the WINDOWED sweep):** each depreciation charge
  is its own ledger source, but the sweep discovers sources by their own `updated_at`. So
  `FixedAsset::booted()` cascades the parent's lifecycle to its charges — soft-delete
  soft-deletes them (their entries void), restore restores them, and a property re-home touches
  them (re-dimensions their entries) — bumping their `updated_at` so the daily sweep re-visits
  them. Without this the charges would strand (phantom depreciation / wrong property) until a
  manual `--all` backfill. A merely *disposed* asset keeps its history on the books. **Caveat:**
  `forceDelete()` (out-of-band only — no admin/console path exposes it) physically removes the
  child rows via the FK cascade and orphans their posted entries; use soft-delete to correct a
  mistaken asset. This is a general property of force-deleting any GL source, not fixed-asset-specific.
- **Not yet posted — disposal write-off (Phase 2b):** disposing an asset stops depreciation but
  does not yet journalize the removal (Dr Accumulated Depreciation + Dr loss/Cr proceeds / Cr
  Furniture). Until then a disposed asset's gross cost + accumulated stay on the balance sheet.

---

## 4. Roadmap

| Phase | Scope | Status |
|-------|-------|--------|
| **1a — Depreciation engine** | register + `DepreciationEntry` + `DepreciationService` (straight-line, derived accumulated/NBV, clamp) + `accounting:post-depreciation` + schedule + tests | ✅ shipped |
| **1b — Admin surfaces** | Filament `FixedAssetResource` (register + schedule columns: cost / accumulated / NBV / monthly) property-scoped, `fixed_assets.*` RBAC (accounting role), `fixed_assets` module flag, read-only depreciation-history relation manager, dispose action, "post this month" list action | ✅ shipped |
| **2 — GL posting** | acquisition → Dr Furniture & Equipment (12101001) / Cr Cash\|Bank (per `funded_from`); depreciation entry → Dr Depreciation Expense (51107001) / Cr Accumulated Depreciation (12201001). Journalizers + mappings + sweep + a tie-out-safe check. | ✅ shipped |
| **2b — Disposal write-off** | on dispose, journalize the removal (Dr Accumulated Depreciation + gain/loss / Cr Furniture & Equipment) so the balance sheet clears the disposed asset. | ⏳ next |

---

## 5. Tests

`tests/Feature/Services/DepreciationServiceTest.php` — monthly amount (net of salvage),
one entry per asset per month, derived accumulated/NBV, idempotent re-run, no charge
before acquisition, stops-at-base (never over-depreciates), disposed skipped, NOT-NULL
coercion, the command.

`tests/Feature/Resources/FixedAssetResourceTest.php` — `fixed_assets.*` RBAC gating,
module-off hiding, property scoping, the derived accumulated/NBV columns, the dispose
action (flips status + stops future depreciation) with an authz guard, the "post this
month" list action with a read-only guard, that a scoped user posts **only** their
visible properties (never portfolio-wide), and the `assertAssetInScope` write guard
(rejects an out-of-scope `asset_id`, lets a portfolio user target any property).

`tests/Feature/Services/FixedAssetLedgerTest.php` — acquisition (Dr Furniture / Cr Cash,
Cr Bank when funded from bank, zero-cost skipped, idempotent, not touching AP), the
depreciation charge (Dr Depreciation Expense / Cr Accumulated Depreciation, zero skipped),
void-on-delete (acquisition + charges net to zero), the **GL↔AR/AP tie-out stays balanced**
after posting fixed-asset entries (the GRNI-class regression), and the parent-lifecycle
cascade exercised through the **actual windowed `accounting:sync-ledger` run**: an aged
depreciation charge voids after soft-delete, charges restore on restore, and the entries
re-dimension after a property re-home (the review-caught windowed-discovery regression).

**Related:** 21 General Ledger (Phase 2 posting), 01 Properties (asset scope),
22 Inventory (sibling module, same ledger patterns), 18 RBAC (Phase 1b).
