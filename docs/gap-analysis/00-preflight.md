# Module 00 — Pre-flight Baseline

> Date: 2026-05-31
> Status: 🟢 Green — baseline established, no blockers
> See [000-plan.md](000-plan.md) for the plan this baseline anchors.

## Repository state

| Field | Value |
|---|---|
| Branch | `main` |
| Commit SHA | `4a96a67c97eb71b75b96e8c4854caac26ad92e13` |
| Working tree | Clean (only `docs/gap-analysis/` untracked) |
| Most recent commit subject | `Fix: per-tenant theme override emitted invalid CSS, brand colour ignored` |

## Runtime

| Field | Value |
|---|---|
| PHP | 8.4.19 (NTS clang) |
| Composer | 2.9.5 |
| Laravel | 13.11.2 |
| Filament | v4.11.3 |
| Livewire | v3.8.0 |
| App env | `local`, debug ON, locale `en`, URL `mall-management.test` |
| Drivers (local) | DB `mysql` (`mall_management`), cache `database`, queue `database`, session `database`, mail `log` |
| Drivers (testing, from `phpunit.xml`) | DB `sqlite:memory:`, cache `array`, queue `sync`, session `array`, mail `array`, bcrypt rounds 4 |
| Storage | `public/storage` NOT linked locally — irrelevant for tests, will need to run `php artisan storage:link` before any prod deploy |

## Pest baseline

```
{"tool":"pest","result":"passed","tests":287,"passed":287,"assertions":772,"duration_ms":3931}
```

- Command: `php artisan test --parallel`
- Result: **287 passed / 287 total, 772 assertions, 3.93 s**
- No failures, no risky tests, no skipped tests.

## Playwright baseline

- Total spec files: 18 (`tests/e2e/01-auth … 18-reports … 99-system-smoke`)
- Total test count (chromium): **164 tests**
- Not executed in pre-flight (multi-minute run; will be exercised per-module and again at end-state gate).

## Migration + seed baseline (scratch sqlite)

- `DB_CONNECTION=sqlite DB_DATABASE=/tmp/atriom-preflight.sqlite php artisan migrate:fresh --seed --force`
- Migrations: **40 ran clean** (auth/cache/jobs → settings → assets/units/tenants/leases/charges/invoices/payments → activity-log/permissions → tenant-auth → maintenance → operators/tenancy → tenant-sales/CAM/ETA/utility-meters → and the rest)
- Seeders ran clean:
  - `RolesPermissionsSeeder` — 331 ms
  - `HayaWalkSeeder` — 3,286 ms
    - 33/50 units occupied (66 % occupancy)
    - 33 leases, 167 invoices
    - 7 current-month payments, AR aging spread across 5 buckets
    - 8 vendors, 5 maintenance requests, 4 credit notes, 15 tenant notes, 3 staff assignments
    - 72 tenant sales declarations → 43 locked percentage rent charges
    - 2 CAM pools (2025 reconciled, 2026 draft)
    - ETA: 48 valid + 8 rejected, rest unsubmitted
    - 48 utility meters, 576 monthly readings
- Closing demo metrics: occupancy 66 %, outstanding AR EGP 533,022.04

## Panel smoke (HTTP only)

| URL | Result |
|---|---|
| `https://mall-management.test/admin` | 200 → redirects to `/admin/login` |
| `https://mall-management.test/owner` | 200 → redirects to `/owner/login` |
| `https://mall-management.test/portal` | 200 → redirects to `/portal/login` |

## Mobile API smoke (`/api/v1`)

| Request | Result |
|---|---|
| `POST /api/v1/auth/login` *(no `Accept: application/json`)* | **302** → redirects to web login. Not strictly a bug — Laravel's default web/api router fallback — but **mobile clients that forget the Accept header will see HTML 302**. Logged as a Module 19 finding. |
| `POST /api/v1/auth/login` *(Accept: application/json, empty body)* | 422 with structured `{message, errors}` JSON — correct |
| `GET /api/v1/auth/me` *(Accept: application/json, no token)* | 401 with `{"message":"Unauthenticated."}` — correct |

## Deferred to per-module audits

- Login each role from DEMO.md (Super Admin / Manager / Viewer / Leasing Manager / Maintenance Manager) — covered per-module as roles touch the relevant module.
- `npx playwright test` full run — covered per-module + final gate.
- `php artisan storage:link` — production checklist item, not a dev blocker.

## End-state diff anchor

The diff for the full audit run is rooted at:

```
git diff 4a96a67c97eb71b75b96e8c4854caac26ad92e13..HEAD
```

That commit is the baseline. Anything after it is audit work.

## Next

Module 01 — Dashboard & Widgets. Start with `app/Filament/Admin/Widgets/` (13 widgets) + `app/Filament/Admin/Pages/{ArAging,OccupancyMap,Reports,Settings,ActivityLog}.php`. Map widgets to DEMO.md §2 (KPI strip, Revenue Trend, AR Aging, Tenant Mix, expiring leases, top tenants, recent payments).
