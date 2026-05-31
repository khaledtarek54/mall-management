# Gap-Analysis Progress Log

> Running log. One entry per module + pre-flight. Most recent at the bottom.
> See [000-plan.md](000-plan.md) for the plan this log tracks.

## Status snapshot

| # | Module | Status | Doc | Notes |
|---|---|---|---|---|
| 00 | Pre-flight | 🟢 Green | [00-preflight.md](00-preflight.md) | Pest 287/287 · migrate+seed clean · 3 panels respond · API JSON contract OK |
| 01 | Dashboard & Widgets | 🟡 Yellow | [01-dashboard.md](01-dashboard.md) | Code healthy (45 widget + 36 page + 15 e2e tests green); 5 findings — DEMO.md narrative drift (F-1..F-3), MRR sparkline UX (F-4), percentDelta latent bug (F-5); 4 deferred decisions D-1..D-4; one inline fix F-6 (seeder log message). |
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

### 2026-05-31 — Module 01 Dashboard & Widgets 🟡

- 13 widgets registered explicitly in `AdminPanelProvider`; all use `RoleScopedWidget` + `TenantScope`. Code healthy.
- Tests: widget tests 45/45 green · page tests 36/36 green · e2e admin pages 15/15 green · full Pest 287/287 still green after the inline fix.
- **5 findings**:
  - **F-1 🔴**: DEMO §2 "33 of 50 units, 66 % occupancy" — actual is **33/58 (56.9 %) in "All Properties" view** because seeder also stubs an 8-unit "Plaza Annex" asset. 66 % only matches when scoped to Haya Walk.
  - **F-2 🔴**: DEMO §2 "Collected EGP 220K" → seeder produces ~EGP 187K (drift from randomness in seed).
  - **F-3 🔴**: DEMO §2 "AR EGP 270K, 7 overdue" → seeder produces ~EGP 493K, 13 overdue (1.8–2.2× drift; most jarring).
  - **F-4 🟡**: MallStats MRR sparkline shows billed-in-month (oscillates, drops 92 % in partial current month) while headline is contractual MRR (stable). Visually confusing.
  - **F-5 🟡**: `MallStats::percentDelta(_, 0)` returns 100.0 for any nonzero current. Renders "↑ 100.0 % vs last month" on fresh installs.
- **F-6 🟢 inline fix**: `HayaWalkSeeder` summary log was "17 vacant" but actually created 25 (8 hidden in Plaza Annex). Now prints "33 occupied, 17 vacant units (+ 8 vacant units on Plaza Annex demo asset)" and scopes the metrics block to "(Haya Walk)".
- **4 decisions deferred** to end-of-sweep walk-through: D-1 Plaza Annex policy · D-2 demo numbers (deterministic seeder vs update DEMO copy) · D-3 MRR sparkline semantic · D-4 percentDelta fix.

**Next:** Module 02 — Tenants.
