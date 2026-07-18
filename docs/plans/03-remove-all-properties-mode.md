# Plan — remove / demote the "All Properties" mode (property-first UX)

> **Status:** **Phase A executed** (property-first operational removal). Phase B (a dedicated read-only
> consolidation surface) is **deferred** until mall #2 is onboarded — the operator starts with one mall.
> **Why now:** the All-Properties view is confusing for day-to-day work, adds no value when you
> operate one mall, and was the root cause of the "create clobber" bug class (see
> [memory: all-mode create clobber] / `docs/PROPERTY-ISOLATION.md` "Test D"). The operator wants a
> **property-first** app: always work inside one specific mall; property selection is the primary,
> unavoidable choice.
>
> **Decision taken (§1):** Eltizam manages many malls but **starts with one**, may later host other
> developers' malls, and treats per-mall **isolation** as paramount → the operational-side removal
> (Phase A) is correct regardless of eventual mall count; the consolidation plumbing is **kept, not
> deleted**, so Phase B can build a read-only "Portfolio/Consolidated" surface later without re-plumbing.
>
> ## What Phase A shipped
> - `User::getTenants()` no longer prepends the ALL pseudo-asset — the switcher offers **only real malls**.
> - `User::canAccessTenant()` **refuses** the ALL pseudo-asset for everyone (incl. super_admin), so a
>   crafted `/admin/ALL` URL now **404s** (Filament's no-enumeration abort) instead of entering All-mode.
> - **Kept intact** (consolidation plumbing / defense-in-depth): `Asset::ALL_PROPERTIES_CODE` + the seeded
>   pseudo-asset row, `Asset::isAllProperties()`, all of `TenantScope` (incl. pseudo-asset handling),
>   `AssignedAssets`, `BypassesScopingOnAll`, the `AdminPanelProvider` branding fallbacks (they render the
>   pseudo-asset as "Atriom" if it is ever force-set), and the `AllPropertiesCreatePinsAsset` / conformance
>   "Test D" clobber gates (they force-set the tenant via `Filament::setTenant`, which is unaffected).
> - Tests updated: `UserTenantsTest`, `UserPropertyAssignmentTest`, `AdminPanelBrandingHttpTest`
>   (now asserts `/admin/ALL` → 404), `ScopingScenarioTest` (title accuracy). Full suite green.
>
> ## Phase A step 3 (asset_id picker) — already delivered; no extra code
> Investigating step 3 showed the "auto-stamp the picker / make the wrong-mall create structurally
> impossible" goal is **already achieved** the moment All-Properties leaves the switcher — for two
> independent reasons — so **no form churn and no server-side pin were added**. (A pin would have been
> redundant, and worse: it would silently *coerce* a tampered value instead of rejecting it, discarding
> the tamper signal.)
> - **The picker is already read-only / auto-stamped.** Every direct-FK form declares its `asset_id`
>   Select as `->default(currentAssetId())->disabled(fn () => currentAssetId() !== null)->dehydrated()`.
>   It only ever *enabled* in All-Properties mode; with `currentAssetId()` now always a real mall on
>   operational screens, the Select is **permanently disabled and stamped to the current mall**.
> - **The write guard already confines to the current mall.** `TenantScope::visibleAssetIds()` returns
>   the full assigned set *only* in All-Properties mode; with a real mall active it collapses to
>   `[currentMall]`. So `assertAssetInScope()` (in every create/edit mutate hook) **already** rejects any
>   other mall — even for super_admin, even against a crafted request that bypasses the disabled UI — and
>   the scoped `Select` (`selectableAssetOptions()`) rejects it at validation too.
> - **Locked in by** `tests/Feature/Regression/CreateConfinedToCurrentMallTest.php`: for all 13 direct-FK
>   resources, a super_admin in mall REAL crafting `asset_id = OTHER` is rejected and nothing is filed
>   into OTHER. (`AllPropertiesCreatePinsAssetTest` still covers the forced All-mode path.)
>
> Net: property-first isolation on create/edit is **complete**; the only remaining work is the Phase B
> consolidation surface (below), needed before a second mall is onboarded.
>
> ## Manual / E2E verification — one real regression caught + fixed
> A full manual drive of the live app (property switcher, `/admin/ALL` → 404, create-form pin) plus a
> run of the Playwright suite surfaced things the Pest suite could not (Pest force-sets the tenant; it
> never hits real `/admin/ALL` URLs or exercises `canCreate()` under a real tenant):
> - **REGRESSION (fixed): you could no longer create a property.** `AssetResource::canCreate()` returned
>   `false` whenever `currentAssetId() !== null` — it only ever allowed creation from the All-Properties
>   view. With that view removed, onboarding a new mall became impossible. Fixed: creation now follows the
>   `assets.create` permission alone, and `AssetResource::getEloquentQuery()` lists the user's whole
>   portfolio (assigned set; all real properties for super_admin) rather than only the active mall — the
>   Properties resource sits ABOVE the per-property context, so a newly-created mall stays visible/editable.
>   `AssetResourceTest` updated. **Lesson:** the risky pattern is not only `isAllProperties()` but any
>   `currentAssetId() === null` used as an *All-mode proxy*. A sweep of all `can*` authorization methods
>   confirmed `canCreate` was the only such gate (the 13 form `->disabled(fn () => currentAssetId() !== null)`
>   pickers are correct — they just become permanently read-only, which is the intended step-3 behavior).
> - **E2E specs: `/admin/ALL/…` → `/admin/AW/…`.** 16 Playwright specs (~91 URLs) used the ALL pseudo-tenant
>   as a "see everything" slug; those now 404. Swapped to the primary demo mall `AW` (Atriom Walk), which
>   holds all the isolated demo data (PA/Plaza Annex is near-empty), so the swap is data-transparent.
> - **Not our bug:** `21-rbac-smoke` failed on stale cached Playwright sessions (`role-*.json` expired after
>   7 days; `SESSION_LIFETIME=120`) — regenerating them fixes it; unrelated to this change.

---

## 1. The decision to make FIRST (it branches the whole plan)

**How many malls does Eltizam run in production today?**

| Answer | Recommended path |
|---|---|
| **One property (today)** | **Hide All-Properties entirely.** The single mall *is* the whole portfolio, so "All Properties" shows nothing new — pure noise + risk. Make that one mall the default context; drop the switcher's All-Properties entry. Re-introduce a purpose-built *consolidated reports* surface only when mall #2 is onboarded. Lowest effort, biggest UX win. |
| **Several properties** | **Demote, don't delete.** Remove All-Properties from all **operational create/edit/list** flows and make a specific property the default; **keep** a read-only *consolidation* surface for the few things that genuinely need "all malls at once" (dashboards, financial reports, owner oversight). |

**Do NOT hard-delete the consolidation plumbing either way** — `TenantScope::visibleAssetIds()` /
`reportAssetIds()` and the portfolio-aware widgets/reports are load-bearing. The change is about
**not exposing "All Properties" as an operational mode**, not ripping out cross-mall aggregation.

**Recommendation:** the **operational-side removal (Phase A below) is correct regardless of the
answer** and delivers ~80% of the UX win at low risk. Do it first. Decide the consolidation
question (Phase B) after, once the mall-count answer is known.

---

## 2. What "All Properties" is today (so the plan is grounded)

- A synthetic **pseudo-asset** row (`Asset::ALL_PROPERTIES_CODE`) that acts as a Filament panel
  *tenant* meaning "the whole portfolio."
- **Enters the tenant switcher** in `app/Models/User.php::getTenants()` (adds the pseudo-asset for
  portfolio-capable users); `canAccessTenant()` allows selecting it.
- When selected, resource tables show **portfolio-wide** data, scoped to the user's visible malls:
  - Direct-FK resources: the 13 fixed in the clobber pass now scope via their own
    `getEloquentQuery()` (`currentAssetId()` → `visibleAssetIds()`).
  - Reads across the app derive the constraint from `App\Support\TenantScope::visibleAssetIds()`
    (null = unrestricted/portfolio) — **never `currentAssetId()` alone** (null in All-mode).
- **`currentAssetId()` returns null in All-mode** — that's the signal for "no single property".
- **Branding/UX** in `AdminPanelProvider` special-cases All-mode (favicon, per-property color, tenant
  menu label).

## 3. Blast radius — the inventory (13 app files + ~130 test files)

**App references (`grep ALL_PROPERTIES|isAllProperties|allProperties|ensureAllPropertiesAsset app/`):**

| File | What it does with All-Properties | Action |
|---|---|---|
| `app/Models/User.php` | `getTenants()` adds the pseudo-asset to the switcher; `canAccessTenant()` allows it | **Core** — stop adding it (Phase A). Keep the multi-mall switcher of *real* malls. |
| `app/Models/Asset.php` | `ALL_PROPERTIES_CODE` const + `isAllProperties()` | Keep the const (reports/plumbing may still use the pseudo id internally) or retire — decide in Phase B. |
| `app/Providers/Filament/AdminPanelProvider.php` | ~4 `isAllProperties()` branches (favicon, branding color, tenant menu) | Simplify once All-mode isn't selectable. |
| `app/Support/TenantScope.php` | `currentAssetId()`/`visibleAssetIds()`/`reportAssetIds()`/`clamp*` all understand the pseudo-asset | **Keep the API** (consolidation depends on it); the pseudo-asset just stops being a *selectable tenant*. `currentAssetId()` will now always be a real mall on operational screens. |
| `app/Support/AssignedAssets.php` | excludes the pseudo-asset when computing visible ids | Keep (still correct). |
| `app/Filament/Admin/Resources/Concerns/BypassesScopingOnAll.php` | the All-mode read escape hatch | Only `MarketingBudget` still uses it (post-clobber-fix). If All-mode is removed, this trait may be retirable — verify no other user. |
| `app/Filament/Admin/Resources/Assets/AssetResource.php` | hides/labels the pseudo-asset | Keep hiding it from the Assets list. |
| `app/Filament/Admin/Resources/OwnerRequests/Schemas/OwnerRequestForm.php`, `Users/Schemas/UserForm.php` | scope selects around the pseudo-asset | Re-check once All-mode is gone. |
| `app/Filament/Admin/Pages/OccupancyMap.php` | portfolio occupancy view | Consolidation surface — Phase B decision. |
| `app/Filament/Owner/Resources/Properties/PropertyResource.php` | **owner portal** portfolio | Owners own *multiple* properties → they likely still need a portfolio view (Phase B). |
| `app/Console/Commands/{EnsureMarketingBudgets,ScanLowStock}Command.php` | run portfolio-wide in console (no tenant) | Unaffected — scheduled scans run tenant-less by design. Keep. |

**Dashboards/widgets that read the portfolio context** (`MonthlyRevenueTrend`, `EnergyConsumptionTrend`,
`TenantMix`, `MallStats`, `ActionRequired`): these show "across your malls" numbers → they are the
**consolidation** use-case. In single-mall mode they naturally show the one mall. In multi-mall,
they need a home (Phase B).

**Tests: ~130 files** call `ensureAllPropertiesAsset()` / assert All-mode scoping. This is the
largest single cost of the change — most just need the helper to become a no-op / the assertions to
target a specific-property default. Budget real time here; the `PropertyIsolationConformanceTest`,
`ResourceScopingTest`, `OpsIsolationScenarioTest`, and the clobber `AllPropertiesCreatePinsAssetTest`
are the load-bearing ones.

## 4. The plan, phased

### Phase A — property-first operational UX (do first; correct regardless of mall count)
1. **Default context = a specific mall.** On login, land the user in a real property (their only
   mall, or their last-used / primary one), never All-Properties. Make the property switcher the
   prominent, first-class control.
2. **Stop offering All-Properties as a selectable tenant** for operational roles — remove the
   pseudo-asset from `User::getTenants()` (or gate it to consolidation-only roles pending Phase B).
3. **No create/edit in a portfolio context** — with All-mode gone from operational screens, every
   create happens inside one mall, so:
   - The 13 resources' `asset_id` picker can become **read-only / auto-stamped from the current
     mall** again (drop the mall dropdown from those forms — the clobber's UX root).
   - This is option "B" from the discussion; it makes the wrong-mall mistake structurally impossible.
4. **Simplify `AdminPanelProvider`** branding branches (no All-mode case on operational panels).
5. Update the ~130 tests: turn `ensureAllPropertiesAsset()` into the "default to a specific mall"
   setup; re-point All-mode scoping assertions at the specific-property default.

### Phase B — the consolidation decision (after the mall-count answer)
- **Single-mall today:** skip B. Reintroduce a "Consolidated" reports/dashboard area when mall #2 lands.
- **Multi-mall:** build a dedicated **read-only "Portfolio / Consolidated" surface** (dashboards +
  financial reports + owner oversight) that reads `TenantScope::visibleAssetIds()` directly, NOT via
  a selectable panel tenant. The owner portal (`app/Filament/Owner`) likely keeps its cross-property
  view. Nothing writable is portfolio-scoped.

## 5. Invariants that must NOT break
- **Property isolation** stays airtight — the conformance gate must still pass; every owned record
  still carries a real `asset_id`; no cross-property read/write leak.
- **The clobber can't come back** — with no portfolio create context, the "Test D" gate stays green
  (and the create-in-All-mode path it guards simply no longer exists on operational screens).
- **Consolidation still works** for whoever genuinely needs it (owner, accounting reports, GL trial
  balance — the GL is operator-wide). Don't strand these.
- **Scheduled scans** (`routes/console.php`) run tenant-less/portfolio-wide by design — unaffected.

## 6. Open questions to answer before starting
1. **Mall count today** (the §1 branch).
2. Do **owner (Jawad)** and **accounting** need a cross-mall *consolidated* view, or do they work
   one property at a time? (Decides how much of Phase B is needed.)
3. What's the **default property** on login when a user has several (last-used? a `primary` flag?).
4. Retire the pseudo-asset row + `ALL_PROPERTIES_CODE` entirely, or keep it as an internal
   reporting sentinel? (Retiring touches `Asset`, `AssignedAssets`, `TenantScope`, seeders.)

## 7. Effort estimate
- **Phase A:** medium code, **large test churn** (~130 files). ~1 focused session.
- **Phase B:** medium (build a consolidated surface) — only if multi-mall.
- Do Phase A behind confidence from the full suite + the isolation gates each step.

---

*Related: `docs/PROPERTY-ISOLATION.md` (the invariant + "Test D"), memory `project_all_mode_create_clobber`
(why the operational portfolio-create path is the bug source), memory `project_property_isolation`.*
