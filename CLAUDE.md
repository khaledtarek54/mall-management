# Atriom — working guide for AI / dev sessions

Egyptian mall-management ERP · Laravel 13 · PHP 8.4 · Filament 4 · MySQL (prod/local) / sqlite `:memory:` (tests).
Operator **Eltizam** runs malls for owners (**Jawad**); **tenants** are the retailers.

**Read [docs/OVERVIEW.md](docs/OVERVIEW.md) first**, then the relevant **[docs/modules/NN-*.md](docs/modules/)** before changing any module's logic — each has *Business rules*, *Extension points (how to change safely)*, and *Gotchas*.

## Conventions — do these
- **Tests:** `vendor/bin/pest --parallel` (Pest 4). Keep it green. Add a regression test for every bug fix in `tests/Feature/Regression/`; scenario tests live in `tests/Feature/Scenarios/`.
- **Business logic** goes in single-action services (`app/Services`); keep controllers + Filament pages thin.
- **Docs are part of "done":** changing a module's logic → update its `docs/modules/NN-*.md` (+ `docs/OVERVIEW.md` if cross-cutting) **in the same commit**.
- **Local DB:** MySQL; reseed with `php artisan migrate:fresh --seed` after a feature (`DemoSeeder` = canonical demo data).
- **Commits:** push to `main` directly (owner's established preference). End commit messages with the `Co-Authored-By:` line.

## Invariants — don't break
- **Money / AR:** `Invoice::recomputeTotals()` is the single source of truth — `paid_amount = captured payments + credit_applied_amount`, `balance = total − paid_amount`. Never set `paid_amount`/`balance` directly elsewhere.
- **VAT 14%** on service charges; **base rent is VAT-exempt**. **Marketing levy 5%** of base rent (captured per-charge, configurable).
- **Delete = super_admin only**; bulk-delete is off project-wide (opt-in via `$bulkDeletable`). CRUD gating via `RoleGatedActions` on `{module}.{action}` permissions (`RolesPermissionsSeeder`).
- **Property scoping:** scope any cross-property Filament select via `App\Support\TenantScope::selectable*` helpers (tenant / asset / unit / department); resource tables auto-scope; "All Properties" pseudo-asset = `Asset::ALL_PROPERTIES_CODE`.
- **Auth surfaces:** `/admin` = `User` + spatie roles · `/portal` = `TenantUser` (multi-user; only `is_admin` may write) · `/api/v1` = Sanctum `tenant-api` against `Tenant`. Cross-tenant API returns **404** (no existence enumeration).
- **NOT-NULL columns:** never let an optional/blank form field send `null` into a NOT-NULL column — coerce in the model (this class of bug bit us: `meter_readings.cost`, `leases.has_percentage_rent`).
- **Scheduled scans** must be idempotent + lock-safe (`lockForUpdate` + re-check the stamp inside the transaction).
- **Terminal records** are immutable: closed/cancelled maintenance, responded owner-requests.

## Key commands
```
php artisan migrate:fresh --seed         # reset local demo data
vendor/bin/pest --parallel               # test suite (1043+)
npx playwright test --project=chromium   # E2E (against Herd mall-management.test)
```
Scheduled (`routes/console.php`): `maintenance:scan-sla-breaches` · `billing:scan-overdue-invoices` · `billing:apply-late-fees` · `maintenance:auto-close` · `maintenance:generate-preventive` · `vendors:expire-contracts` · `cam:reconcile` · `accounting:sync-ledger` · `accounting:post-depreciation`.

## Skills (`.claude/skills/`)
- **`/new-module`** — scaffold a module the Atriom way (model+migration, service, RBAC + property-scoped Filament resource, permissions, doc, tests).
- **`/qa-sweep [mode]`** — multi-agent QA: `scenario` · `adversarial` · `field-audit` · `concurrency` · `security` · `e2e` (default `full`).
- **`/safe-change`** — definition-of-done for changing a module's business logic (read doc → service → invariants → test → regression → update doc → commit).

## Demo logins (password `password`)
`admin@mall.test` (super_admin) · `manager@/viewer@/leasing@/maintenance@/accounting@/marketing@/hr@mall.test` · `owner@atriom.test` (owner) · portal `tenant1@atriomwalk.test` (admin) / `staff1@atriomwalk.test` (read-only).
