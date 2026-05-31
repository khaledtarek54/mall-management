# Gap-Analysis Progress Log

> Running log. One entry per module + pre-flight. Most recent at the bottom.
> See [000-plan.md](000-plan.md) for the plan this log tracks.

## Status snapshot

| # | Module | Status | Doc | Notes |
|---|---|---|---|---|
| 00 | Pre-flight | 🟢 Green | [00-preflight.md](00-preflight.md) | Pest 287/287 · migrate+seed clean · 3 panels respond · API JSON contract OK |
| 01 | Dashboard & Widgets | ⬜ Not started | — | |
| 02 | Tenants | ⬜ Not started | — | |
| 03 | Units | ⬜ Not started | — | |
| 04 | Leases | ⬜ Not started | — | |
| 05 | Invoices | ⬜ Not started | — | |
| 06 | Payments | ⬜ Not started | — | |
| 07 | CAM | ⬜ Not started | — | |
| 08 | ETA e-invoicing | ⬜ Not started | — | |
| 09 | Maintenance / CAFM | ⬜ Not started | — | |
| 10 | Owner Portal panel | ⬜ Not started | — | |
| 11 | Tenant Portal panel | ⬜ Not started | — | |
| 12 | Tenant Sales Declarations | ⬜ Not started | — | |
| 13 | Utility Meters & Energy | ⬜ Not started | — | |
| 14 | Credit Notes | ⬜ Not started | — | |
| 15 | Vendors & Contracts | ⬜ Not started | — | |
| 16 | Assets (tenancy) | ⬜ Not started | — | |
| 17 | Users & Roles | ⬜ Not started | — | |
| 18 | Reports | ⬜ Not started | — | |
| 19 | Mobile API `/api/v1` | ⬜ Not started | — | |
| 20 | Cross-cutting | ⬜ Not started | — | |

Legend: ⬜ Not started · 🟦 In progress · 🟢 Green · 🟡 Yellow · 🔴 Red

## Log entries

### 2026-05-31 — Module 00 Pre-flight 🟢

- Branch `main` at SHA `4a96a67`; clean working tree.
- Pest baseline: **287 passed / 0 failed** in 3.93 s (`--parallel`).
- `migrate:fresh --seed` on scratch sqlite: 40 migrations clean, `RolesPermissionsSeeder` + `HayaWalkSeeder` produce expected demo state (66 % occupancy, 33 leases, 167 invoices, 533k AR).
- `/admin`, `/owner`, `/portal` all redirect 200 → login pages on Herd.
- `/api/v1/auth/login` returns JSON 422 with `Accept: application/json`; returns 302 without it (logged as Module 19 finding — not a blocker).
- Playwright count: 164 tests in 18 specs — deferred to per-module + final gate.
- See [00-preflight.md](00-preflight.md) for full detail.

**Next:** Module 01 — Dashboard & Widgets.
