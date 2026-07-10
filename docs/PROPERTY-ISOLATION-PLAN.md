# Total Property Isolation — Design & Plan

> Branch: `feat/property-isolation` · Status: **APPROVED — implementing** · Author: AI session 2026-07-10
>
> **Sign-off decisions (2026-07-10):** (1) Strategy = **Layers A+B only** — systematic + self-verifying,
> **no** global scope (Layer C rejected), keep the 3 existing traits, minimal blast radius. (2) Shared set
> = **§4a kept as recommended** (Vendor + Tenant stay cross-mall). (3) All-Properties = **kept for portfolio
> roles only** (super_admin/owner); restricted users stay pinned to their assigned set (already enforced).
>
> Goal (user's words): *"Every single module is isolated with its property so when the user selects a
> property to work on, only this property's data appears — no other property's data is shown or can be
> altered."* Plus: **safe for all modules, don't bug the app**, and a clear statement of **what can be
> shared** between properties.

---

## 1. What "property isolation" means here (and what it does *not*)

There are two ways to isolate tenants:

| Model | What it is | Verdict for Atriom |
|---|---|---|
| **Hard isolation** | Separate DB / schema per property | ❌ **Rejected** — breaks the operator's consolidated portfolio books, the *shared* chart of accounts, cross-mall tenants, operator-wide HR/vendors, and portfolio dashboards. Would be a full re-platform. |
| **Soft (row-level) isolation** | One DB; every property-owned row carries `asset_id`; every read/write is scoped to the active property | ✅ **Recommended** — it's what the app already does. The job is to make it **airtight and self-enforcing**, not to re-architect. |

**Why soft isolation is correct for this domain:** Eltizam operates a *portfolio*. Super-admins and owners
legitimately need consolidated views; the chart of accounts is one shared tree with `asset_id` as a
*dimension*; a single retailer (Tenant) can lease in several malls; vendors and staff are operator-wide.
Hard isolation would make all of that impossible. So the guarantee we build is:

> **A property-restricted user, with a property selected, can only ever read or write rows belonging to
> that property (or their assigned set in All-Properties mode). Portfolio roles (super_admin / owner) may
> consolidate, but never accidentally.**

---

## 2. Current state — the machinery that already exists

The app is **already property-scoped**, via Filament per-property tenancy (`Asset` = the tenant, slug =
`code`) plus a synthetic **"All Properties"** pseudo-asset (`Asset::ALL_PROPERTIES_CODE`).

**Scoping helpers**
- `App\Support\TenantScope` — `currentAssetId()`, `visibleAssetIds()`, `applyTo($q, $relation)`,
  `reportAssetIds($selected)`, `selectableAssetOptions()`, `selectableTenantOptions()`.
- `App\Support\AssignedAssets` — the layer *above* tenancy (staff assignments ∪ legal ownership).

**Resource scoping traits** (`app/Filament/Admin/Resources/Concerns/`)
- `BypassesScopingOnAll` — direct-`asset_id` models; Filament auto-scopes, trait adds the All-Properties escape hatch.
- `ScopesViaProperty` — indirect models; declares a `tenantScopeRelation()` chain (e.g. `lease.unit`) and `whereHas`-scopes.
- `BypassesFilamentTenantAutoScope` — special-case manual scoping.

**Write-side guard** — `assertAssetInScope($assetId)` re-validates a client-supplied `asset_id` against
`visibleAssetIds()` and `abort(403)`s on tamper. **Present on 6 resources, missing on 5** (see §5).

**Verdict:** the *reads* are broadly well-scoped (all 12 widgets, report pages via `ScopesLedgerReport`,
portal, owner panel all check out). The problems are: (a) real **write-side holes**, and (b) the whole
thing is **opt-in per resource** — nothing structurally stops the *next* module from leaking. This is
exactly the recurring class of bug the QA sweeps keep re-finding.

---

## 3. The core problem, precisely

1. **Isolation is opt-in, not enforced.** Every resource, form select, widget, report, and service must
   *remember* to scope. Forgetting produces a silent cross-property leak, not an error.
2. **5 verified write-side gaps** let a restricted user in All-Properties mode tamper `asset_id` and
   create/edit into another property's books (Expense, VendorBill, Payroll, JournalEntry,
   DepositTransaction — all financial, all GL-posting).
3. **No self-enforcement.** There is no test that fails when a new property-owned resource ships without
   scoping + a write-guard. So the invariant erodes every time a module is added.

The fix is therefore **not** "add more scoping calls" (that's more of the same fragility). It's:
**make isolation the enforced default, and make forgetting it a CI failure.**

---

## 4. What can be SHARED vs. what is ISOLATED (the register)

This is the authoritative classification. It is encoded in code (a central registry, §6) so it can be
tested, not just documented.

### 4a. GLOBAL / SHARED across all properties (operator-wide)

| Entity | Why it's shared | How property still applies |
|---|---|---|
| **User** (+ roles, permissions) | Operator staff work across malls | Assigned to properties via `asset_user`; owners via `asset_owner` |
| **LedgerAccount** (chart of accounts / دليل الحسابات) | One chart for the whole operator | Property is a **dimension** on entries/lines, not a separate ledger |
| **FiscalYear + AccountingPeriod** | One fiscal calendar for the operator | Reports filter entries by `asset_id` |
| **AccountMapping** | Semantic posting roles → accounts | **Global default + optional per-property override** (`asset_id` nullable) |
| **SystemSetting / ModulesSettings** | System state + feature flags | Global by nature |
| **InventoryItem** | Shared SKU catalog | Stock is per-**Warehouse** (which *is* per-property) |
| **Vendor** | A contractor serves many malls | Engagement is per-property (**VendorContract**, **VendorBill**) |
| **Tenant** | One retailer can lease in several malls | Its money/lifecycle is per-property (**Lease → Invoice/Payment**) |
| **TenantUser** | Portal login for a Tenant | Transitively multi-property via the tenant's leases |
| **Department** (global ones) | Operator-wide org units (HR, Accounting) | `asset_id` nullable — a set value scopes it to one property |

### 4b. PER-PROPERTY / ISOLATED (carry `asset_id`, directly or via a chain)

Leasing & money: **Unit, Lease, Invoice(+items), Charge, Payment, CreditNote(+items),
DepositTransaction, TenantSalesDeclaration** · CAM: **CamExpensePool(+allocations)** · Marketing:
**MarketingBudget(+spend)** · Utilities: **UtilityMeter(+readings)** · Requests/maintenance:
**TenantRequest(+comments), MaintenancePlan, MaintenanceWorkOrder(+items)** · Vendor engagement:
**VendorContract, VendorBill(+payments)** · HR: **Employee(+advances/repayments), Payroll(+lines),
Custody(+transactions)** · Fixed assets: **FixedAsset(+depreciation/disposal)** · Inventory:
**Warehouse, StockMovement** · GL & direct spend: **JournalEntry(+lines carry the `asset_id` dimension),
Expense** · Hybrid nullable: **OwnerRequest** (per-property or cross-property), **Department** (property-scoped ones).

### 4c. The elegant answer to "what can be shared as *business models*"

Five entities use one repeated, powerful pattern — **a shared catalog/master with per-property use**.
This is what lets things be shared *without* leaking:

```
Shared master (global)          →   Per-property usage (asset_id)
──────────────────────────────      ─────────────────────────────
Vendor          (catalog)       →   VendorContract, VendorBill
InventoryItem   (SKU catalog)   →   Warehouse, StockMovement
LedgerAccount   (chart)         →   JournalEntry / JournalLine  (asset_id = dimension)
Tenant          (company)       →   Lease, Invoice, Payment
Department      (org unit)      →   membership + role, per property
AccountMapping  (global rule)   →   per-property override row
```

**Recommendation: keep all of these shared.** Forcing them per-property would duplicate master data,
break cross-mall tenants, and destroy consolidated reporting. Isolation is enforced at the *usage* rows,
which already carry `asset_id`.

---

## 5. The verified write-side gaps (P0)

`assertAssetInScope()` exists and is used correctly on **Employee, FixedAsset, Warehouse, Custody,
MaintenanceWorkOrder, MaintenancePlan**. It is **missing** on these 5 (all direct `asset_id`, all
GL-posting):

| Resource | Missing guard | Leak in All-Properties mode |
|---|---|---|
| **Expense** | Create + Edit | Tampered `asset_id` posts a petty-cash expense to another property's GL |
| **VendorBill** | Create + Edit (+ approve/pay actions) | AP posted to a property outside the user's scope |
| **Payroll** | Create + Edit (+ approve) | Wage expense corrupts another property's income statement |
| **JournalEntry** | Create + Edit (+ post) | Manual entry / reversal lands in the wrong property's books |
| **DepositTransaction** | Create + Edit | Deposit GL posts to a property the lease doesn't belong to |

---

## 6. The design — enforced-by-default + self-verifying

Three layers. The first two are the airtight core; the third is optional defense-in-depth.

### Layer A — A single source of truth for the classification (code, not just docs)

- `App\Support\PropertyIsolation` — a registry that lists **shared** models (§4a) and, for each
  **property-owned** model (§4b), how it reaches `asset_id` (direct column or relation chain).
- A `PropertyScoped` marker interface (or `BelongsToProperty` trait) on every property-owned model,
  exposing a canonical `assetId(): ?int` accessor and the chain metadata. One place defines "this model
  belongs to a property," so tooling and tests can reason about it.

### Layer B — Systematic guard + a conformance test that makes forgetting a CI failure

- Extract `assertAssetInScope()` into **one** shared concern `GuardsAssetInScope` (replaces the 6 copies)
  and wire it into the **5 gap resources'** Create/Edit pages **and** their custom mutating actions
  (approve / pay / post).
- Add a **conformance test** (`tests/Feature/Scenarios/PropertyIsolationConformanceTest.php`) that
  reflects over **every** admin Filament resource and asserts:
  1. If its model is property-owned (per the registry), the resource scopes its table (one of the three
     traits or an equivalent `getEloquentQuery`), **and**
  2. every Create/Edit page (or write path) that accepts an `asset_id` calls the guard.
  3. Every property-owned **model** either has an `asset_id` column or a declared relation chain.

  This test **fails today** (documents the 5 gaps) and goes green when Layer B lands — and **stays** the
  gate for every future module. This is the mechanism that makes isolation "airtight" without a risky
  rewrite.

### Layer C — global scope on the financial core — **REJECTED at sign-off**

Considered and **not** adopted (decision 1): a Filament-request-bound global Eloquent scope on the GL core
would be more airtight but carries a medium blast radius (every job/CLI/portal/API path must bypass it).
The self-verifying conformance test (Layer B) delivers the "can't forget" guarantee without that risk, so
we ship A+B only. Layer C remains documented here as the future hardening option if ever needed.

---

## 7. Phased implementation plan (each phase = green tests before the next)

**Phase 0 — Safety net (no behavior change).**
Registry (`PropertyIsolation`) + `PropertyScoped` marker + the conformance test (initially red) +
`docs/PROPERTY-ISOLATION.md` (this register). Establishes the baseline and the gate.

**Phase 1 — Close the 5 write-side gaps (P0 security).**
`GuardsAssetInScope` concern → wired into Expense / VendorBill / Payroll / JournalEntry /
DepositTransaction Create+Edit pages and their approve/pay/post actions. One regression test each
(`tests/Feature/Regression/Isolation/…`: tampered `asset_id` POST → 403). Conformance test goes green.

**Phase 2 — Enforce-by-default marker across property-owned models.**
Apply `BelongsToProperty`/`PropertyScoped` to all §4b models; conformance test now asserts full coverage.
(Optionally Layer C on the GL core, if approved.)

**Phase 3 — Services & cross-surface hardening.**
Audit every `app/Services/*` write path (GL/AR/CAM/depreciation/custody) to confirm `asset_id` is derived
from the **source record**, never a client value. Confirm scheduled scans run portfolio-wide *by design*
(console = no tenant = no scope) and don't get accidentally scoped. Add explicit
portal/API cross-property tests (API already 404s cross-tenant; add property-cross cases).

**Phase 4 — Docs, demo, verification.**
Finalize `docs/PROPERTY-ISOLATION.md` (register + mechanism + "how to add a module safely"), update
`CLAUDE.md` invariants + `docs/modules/18-rbac-scoping.md`, run `pest --parallel` + a `/qa-sweep`
scoping+security pass, reseed demo and eyeball two properties.

---

## 8. Test strategy (how we prove it and keep it proven)

- **Conformance test** (structural) — every property-owned resource is scoped + write-guarded; fails CI
  on any new unscoped module. *This is the durable guarantee.*
- **Regression tests** (behavioral) — one per closed gap: tampered `asset_id` create/edit → 403.
- **Scenario tests** — extend `ScopingScenarioTest`: restricted user to property A never sees/writes B
  across *all* §4b modules (currently covers a subset).
- **Cross-surface** — portal token never returns another tenant's rows; API stays 404 cross-tenant.
- Keep the suite green (`vendor/bin/pest --parallel`) at every phase boundary.

---

## 9. Risk analysis — how we avoid "bugging the app"

| Risk | Mitigation |
|---|---|
| Global scope silently changes query results in jobs/CLI/portal/API | Layer C is **opt-in, GL-core only, admin-web-guard-bound**, no-op in console/portal/API; every consolidation/sweep path explicitly `withoutGlobalScope`. Phase-gated behind approval. |
| Write-guard breaks legitimate portfolio-role writes | Guard uses `visibleAssetIds()` which returns `null` for super_admin/portfolio → no-op for them (same proven pattern already live on 6 resources). |
| Trait churn destabilizes working resources | We **keep** the 3 existing traits; we only *add* the shared write-guard + the conformance test. No rewrite of read-scoping that already works. |
| Consolidated reports / shared chart regress | Shared entities (§4a) are explicitly **not** touched; GL stays shared-chart-plus-dimension. |
| Scheduled sweeps strand GL child sources | Phase 3 re-verifies the existing child-source cascade + windowed sweep still holds (known trap, already handled). |
| Big-bang breakage | Strict phase gates; each phase ships behind green tests; work happens on `feat/property-isolation` in a separate worktree, never on `main`. |

---

## 10. Open decisions (need sign-off before Phase 1)

1. **Isolation strategy depth** — (B) Systematic + self-verifying + close gaps [recommended, safest], vs.
   (C) B **plus** opt-in global scope on the GL core [more airtight, larger blast radius].
2. **Shared register** — confirm §4a stays shared (esp. **Vendor** and **Tenant** as cross-mall), or call
   out any entity you want forced strictly per-property.
3. **"All Properties" mode** — keep it for portfolio roles (super_admin/owner) only [recommended], with
   restricted users always pinned to their assigned set (already enforced), vs. remove consolidation entirely.
