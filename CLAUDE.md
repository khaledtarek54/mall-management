# Atriom — working guide for AI / dev sessions

Egyptian mall-management ERP · Laravel 13 · PHP 8.4 · Filament 4 · MySQL (prod/local) / sqlite `:memory:` (tests).
Operator **Eltizam** runs malls for owners (**Jawad**); **tenants** are the retailers.

**Read [docs/OVERVIEW.md](docs/OVERVIEW.md) first**, then the relevant **[docs/modules/NN-*.md](docs/modules/)** before changing any module's logic — each has *Business rules*, *Extension points (how to change safely)*, and *Gotchas*.

**Orientation:** [docs/PROJECT-MAP.md](docs/PROJECT-MAP.md) (generated census — what exists + what's covered) · [docs/ROADMAP.md](docs/ROADMAP.md) (**the single** prioritized list: go-live + FRD + accounting) · the visual handbook (`npm run docs:dev`) — [the whole system on one page](docs/visual/map.md), [a month in the life](docs/visual/scenarios.md).

## Conventions — do these
- **Tests:** `vendor/bin/pest --parallel` (Pest 4). Keep it green. Add a regression test for every bug fix in `tests/Feature/Regression/`; scenario tests live in `tests/Feature/Scenarios/`.
- **CI auto-runs are PAUSED** (2026-07-29, owner's call — the pipeline is too slow for the push loop). `.github/workflows/ci.yml` fires only on `workflow_dispatch`. So the five conformance gates (PropertyIsolation, GlRegistry, MediaPrivacy, AdminSmokeManifest, **DashboardLayout**) + PHPStan + Playwright + `composer audit` are **ADVISORY**: nothing blocks a bad change. **Keep `pest --parallel` green locally before every push** — a red push is silent, not a red check.
  - **The jobs themselves were repaired 2026-07-29** and a manual run now actually works: `gh workflow run ci.yml`. All four had been failing on *every* run since ≥2026-07-23 for non-product reasons — `composer audit` missing `--locked` (it audited nothing, ever), PHPStan reading a baseline with **absolute paths** (green on one laptop, red everywhere else), and two timeouts set below the real runtime. Worth a manual run before anything risky.
  - **Before re-enabling**, know the cost: the test job needs ~20+ min (453 test files replaying 150 migrations per paratest worker). `php artisan schema:dump` is the lever that would make auto-runs affordable.
- **Business logic** goes in single-action services (`app/Services`); keep controllers + Filament pages thin.
- **Docs are part of "done":** changing a module's logic → update its `docs/modules/NN-*.md` (+ `docs/OVERVIEW.md` if cross-cutting) **in the same commit**.
- **Local DB:** MySQL; reseed with `php artisan migrate:fresh --seed` after a feature (`DemoSeeder` = canonical demo data).
- **Commits:** push to `main` directly (owner's established preference). End commit messages with the `Co-Authored-By:` line.

## Invariants — don't break
- **Money / AR:** `Invoice::recomputeTotals()` is the single source of truth — `paid_amount = captured payments + credit_applied_amount`, `balance = total − paid_amount`. Never set `paid_amount`/`balance` directly elsewhere.
- **General ledger:** `LedgerPoster::JOURNALIZERS` is the **single registry** of what posts to the GL; all four dispatch paths (real-time hooks · `accounting:sync-ledger` sweep · close gate · `billing:reconcile` drift check) **derive** from it via `LedgerPoster::sources()` — never re-list them. New money source = one registry line + its `LedgerRealtimeSync::SOURCE_DATE_COLUMNS` entry; `GlRegistryConformanceTest` fails CI otherwise. **A GL test that calls `LedgerPoster::post()`/`sync()` directly proves only the journalizer's arithmetic** — at least one test per source must drive the real service + the sweep and assert the tie-out. (This exact gap let applied SLA penalties cut a vendor bill while posting nothing — see [modules/21](docs/modules/21-general-ledger.md#gl-registry-gate).)
- **VAT 14%** on service charges; **base rent is VAT-exempt**. **Marketing levy 5%** of base rent (captured per-charge, configurable).
- **Delete = super_admin only**; bulk-delete is off project-wide (opt-in via `$bulkDeletable`). CRUD gating via `RoleGatedActions` on `{module}.{action}` permissions (`RolesPermissionsSeeder`).
- **Property isolation:** every module is isolated to the selected property. **Reads:** resource tables auto-scope; scope any cross-property Filament select via `App\Support\TenantScope::selectable*` / `visibleAssetIds()` (never `currentAssetId()` alone — null in All-mode leaks); "All Properties" pseudo-asset = `Asset::ALL_PROPERTIES_CODE`. **Writes:** if a form exposes/derives an editable `asset_id`, guard it with `GuardsAssetInScope::assertAssetInScope()` on create+edit (Filament only stamps `asset_id` on create, never update). Shared-vs-isolated register = `App\Support\PropertyIsolation`; the `PropertyIsolationConformanceTest` gate fails CI if a new model/resource ships unclassified/unscoped/unguarded. **Read [docs/PROPERTY-ISOLATION.md](docs/PROPERTY-ISOLATION.md) before adding a property-owned module.**
- **Auth surfaces:** `/admin` = `User` + spatie roles · `/portal` = `TenantUser` (multi-user; only `is_admin` may write) · `/api/v1` = Sanctum `tenant-api` against `Tenant`. Cross-tenant API returns **404** (no existence enumeration).
- **`visible()` is NOT an authorization gate.** Filament's `mountAction()` checks `isDisabled()` and **never** `isVisible()`, so a hidden action is still dispatchable by a crafted Livewire call. Every write action must gate in **both** `visible()` (the UI) and `action()` (`abort_unless(...)` — the actual gate); name the predicate once so they can't drift. **In tests, `->callAction()` is not an attack** — it calls `assertActionVisible()` first, so it would report the bug fixed while it was still exploitable; dispatch via `mountAction` + `callMountedAction` (see `tests/Feature/Regression/PortalReadOnlyDispatchGuardTest.php`).
- **Uploaded files:** every media collection must declare its disk explicitly — `useDisk('local')` (private) unless it is branding. medialibrary's default is `env('MEDIA_DISK', 'public')`, i.e. **fail-open**: forgetting to register a collection silently publishes it to the webroot (this exposed signed leases + tenant tax cards). `MediaPrivacyConformanceTest` gates it.
- **NOT-NULL columns:** never let an optional/blank form field send `null` into a NOT-NULL column — coerce in the model (this class of bug bit us: `meter_readings.cost`, `leases.has_percentage_rent`).
- **Scheduled scans** must be idempotent + lock-safe (`lockForUpdate` + re-check the stamp inside the transaction).
- **Terminal records** are immutable: closed/cancelled maintenance, responded owner-requests.

## Key commands
```
php artisan migrate:fresh --seed          # reset local demo data
vendor/bin/pest --parallel                # test suite
npx playwright test --project=chromium    # E2E (against Herd mall-management.test)
npm run docs:dev                          # the visual handbook (docs/visual/)
php artisan atriom:dump-system-census     # regenerate the census in docs/PROJECT-MAP.md
php artisan atriom:dump-admin-manifest    # regenerate the E2E resource manifest
```
**Never hand-type a count into a doc** — that's how PROJECT-MAP came to claim 28 models when there were 61. Run the census instead.
Scheduled (`routes/console.php`): `requests:scan-sla-breaches` · `billing:scan-overdue-invoices` · `billing:apply-late-fees` · `requests:auto-close` · `maintenance:generate-preventive` · `maintenance:scan-wo-sla-breaches` · `vendors:expire-contracts` · `vendors:scan-document-expiry` · `vendors:scan-contract-renewals` · `cam:reconcile` · `accounting:sync-ledger` · `accounting:post-depreciation` · `leases:apply-escalations` · `pdc:scan-maturing` · `sales:scan-missing-declarations` · `backup:clean` · `backup:run` · `backup:monitor`. *(The tenant-request scans are `requests:*`; `maintenance:*` is now facility work-orders/plans only.)*

## Skills (`.claude/skills/`)
- **`/new-module`** — scaffold a module the Atriom way (model+migration, service, RBAC + property-scoped Filament resource, permissions, doc, tests).
- **`/qa-sweep [mode]`** — multi-agent QA: `scenario` · `adversarial` · `field-audit` · `concurrency` · `security` · `e2e` (default `full`).
- **`/safe-change`** — definition-of-done for changing a module's business logic (read doc → service → invariants → test → regression → update doc → commit).

## Demo logins (password `password`)
`admin@mall.test` (super_admin) · `manager@/viewer@/leasing@/maintenance@/accounting@/marketing@/hr@mall.test` · `owner@atriom.test` (owner) · portal `tenant1@atriomwalk.test` (admin) / `staff1@atriomwalk.test` (read-only).
