# Module 23 — Fixed Assets & Depreciation (الأصول الثابتة والإهلاك)

> **Status: Phase 1a shipped (depreciation engine).** Fixed-asset register +
> straight-line depreciation service + the monthly `accounting:post-depreciation`
> run + tests. Admin surfaces & RBAC (1b) and GL posting (2) are the remaining
> phases. Delivers the FRD/gap-analysis backlog item FA-1 — the chart already had
> the accounts (furniture, accumulated depreciation, depreciation expense) but they
> were **dormant** (nothing posted). This makes them live.

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

---

## 3. Services & commands

- **`DepreciationService`** — `monthlyAmount()`, `depreciableBase()`, `accumulatedFor()`,
  `netBookValue()`, and `run(?period)` (the idempotent, lock-safe monthly poster).
- **`accounting:post-depreciation {--month=YYYY-MM}`** — runs the month's depreciation;
  scheduled monthly (28th 03:30). Idempotent, so a re-run is harmless.

---

## 4. Roadmap

| Phase | Scope | Status |
|-------|-------|--------|
| **1a — Depreciation engine** | register + `DepreciationEntry` + `DepreciationService` (straight-line, derived accumulated/NBV, clamp) + `accounting:post-depreciation` + schedule + tests | ✅ shipped |
| **1b — Admin surfaces** | Filament `FixedAssetResource` (register + schedule columns: cost / accumulated / NBV / monthly) property-scoped, `fixed_assets.*` RBAC, `fixed_assets` module flag, depreciation-history view, dispose action | ⏳ next |
| **2 — GL posting** | acquisition → Dr Furniture & Equipment (12101001) / Cr Cash\|Bank (per `funded_from`); depreciation entry → Dr Depreciation Expense (51107001) / Cr Accumulated Depreciation (12201001). Journalizers + mappings + sweep + a tie-out-safe check. | ⏳ |

---

## 5. Tests

`tests/Feature/Services/DepreciationServiceTest.php` — monthly amount (net of salvage),
one entry per asset per month, derived accumulated/NBV, idempotent re-run, no charge
before acquisition, stops-at-base (never over-depreciates), disposed skipped, NOT-NULL
coercion, the command.

**Related:** 21 General Ledger (Phase 2 posting), 01 Properties (asset scope),
22 Inventory (sibling module, same ledger patterns), 18 RBAC (Phase 1b).
