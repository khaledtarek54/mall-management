# Atriom Module-by-Module Gap Analysis & Production-Readiness Plan

> Goal: every module audited end-to-end, every test green, demo scripts runnable live, production checklist signed off.
> Started: 2026-05-31
> Owner: khaled + Claude (Opus 4.7)

## Decisions locked in at kickoff

| Decision | Choice | Implication |
|---|---|---|
| Module order | **Demo-critical first** | Modules touched by [DEMO.md](../../DEMO.md) / [DEMO-ELTIZAM.md](../../DEMO-ELTIZAM.md) get audited first, in dependency order (Tenants before Leases, Leases before Invoices, etc.) |
| Mobile API `/api/v1` | **In scope — audit + design missing endpoints** | Treated as Module 19. Implementation depth decided per-endpoint; default is design-only unless trivial |
| Fix policy | **Fix small, batch large** | Bugs ≤ ~30 min and missing test coverage fixed inline. Schema changes, feature gaps, design questions cataloged and flagged for explicit approval |
| Truth source | **[FEATURES.md](../../FEATURES.md) + DEMO scripts** | Anything claimed there but not working = P0. MASTER-PLAN.md treated as strategy, not requirements |

## Per-module workflow (applied to each)

Every module gets the same 7-step pass. The output lives in `docs/gap-analysis/NN-<module>.md`.

1. **Inventory** — list every file in scope: Model + migration, Filament Resource (Admin/Owner/Portal), Pages/Widgets, Actions, Services, Jobs, Mail, Console commands, Settings, Seeders, Routes, Tests (Pest + Playwright), lang keys (en + ar), policies/permissions, tenancy scoping traits.
2. **Spec map** — quote the relevant sentences from FEATURES.md + DEMO.md + DEMO-ELTIZAM.md. Anything the docs claim becomes an acceptance check.
3. **Static read** — read every file in the inventory. Note dead code, TODOs, stubs, "hacks", and untested branches.
4. **Behavior trace** — for each user-facing flow, walk it end-to-end in code: CRUD, domain ops (e.g. Invoice → issue → pay → credit → void), cross-module effects, PDFs, emails, queued jobs, permissions per role, multi-property tenant scoping, EN/AR/RTL.
5. **Test sweep** — run existing Pest tests for the module with `php artisan test --parallel`; run Playwright specs that touch it. Log pass/fail. Add missing tests for any uncovered critical path (happy + 1 unhappy minimum).
6. **Manual UX pass** — log into the relevant Filament panel(s) using the seeded `HayaWalkSeeder` data, walk the module's CRUD + main flows in the browser. Test EN and AR. Screenshot anything broken.
7. **Write up + commit** — produce `docs/gap-analysis/NN-<module>.md` with: status (Green / Yellow / Red), inventory, defects fixed inline, gaps deferred for explicit approval, test additions. Commit as `Audit: <module> — <one-line summary>` (small fixes ride along; large changes get their own PR).

**Per-module status legend**

- 🟢 Green — code, tests, and docs aligned; demo path works
- 🟡 Yellow — works but has documented gaps for the deferred backlog
- 🔴 Red — blocks demo or production; needs explicit fix decision

## Module order (demo-critical first)

Sequencing balances demo weight with dependency order — you can't audit Invoices cleanly without Leases first.

| # | Module | Surface | Why this slot |
|---|---|---|---|
| 01 | **Dashboard & Widgets** | `Pages/`, `Widgets/`, `SetupGuide` | First thing in DEMO.md §2; KPIs drive trust |
| 02 | **Tenants** | `Resources/Tenants`, `Tenant` model | Anchor entity for leases, sales, portal, mobile API |
| 03 | **Units** | `Resources/Units`, `OccupancyMap` page | Anchor for leases; occupancy is a headline KPI |
| 04 | **Leases** | `Resources/Leases`, `LeaseCreation/Renewal/Termination` services | Drives billing, percentage rent, expirations widget |
| 05 | **Invoices** | `Resources/Invoices`, `InvoicePdfService`, `MonthlyBillingService`, `RunMonthlyBilling` job/command | Money path; PDF Arabic; ETA columns |
| 06 | **Payments** | `Resources/Payments`, `RecentPayments` widget, `ArAging` page+widget | Collection metric; AR aging chart |
| 07 | **CAM** | `Resources/CamExpensePools`, `CamReconciliationService`, `CamAnnualReconciliationCommand` | Egyptian retail wedge #1 |
| 08 | **ETA e-invoicing** | `Services/Eta`, `SubmitInvoiceToEta` job, `EtaSettings`, `EtaCompliance` widget | Egyptian retail wedge #2; demo claim |
| 09 | **Maintenance / CAFM** | `Resources/MaintenanceRequests`, `MaintenanceRequestService`, `MaintenanceSettings`, `OpenMaintenanceRequests` widget | Shipped sprint feature; portal flow |
| 10 | **Owner Portal panel** | `Filament/Owner/*`, `OwnerStatementPdf` flow | Recent fix area; separate panel needs its own pass |
| 11 | **Tenant Portal panel** | `Filament/Portal/*`, `TenantStatementPdfService` | Tenant-facing flows; mobile API mirror |
| 12 | **Tenant Sales Declarations** | `Resources/TenantSalesDeclarations`, `PercentageRentCalculationService` | Egyptian retail wedge #3 |
| 13 | **Utility Meters & Energy** | `Resources/UtilityMeters`, `MeterReading`, `EnergyConsumptionTrend` widget | Demo claim (sparklines on widget) |
| 14 | **Credit Notes** | `Resources/CreditNotes`, `CreditNoteService` | AR adjustments; binds to invoices |
| 15 | **Vendors & Contracts** | `Resources/Vendors`, `VendorContact`, `VendorContract` | Operations spine for maintenance |
| 16 | **Assets (multi-property tenancy)** | `Resources/Assets`, Filament tenancy via `Asset` model, `ScopesViaProperty` / `BypassesScopingOnAll` / `BypassesFilamentTenantAutoScope` traits | Cross-cutting; check every other module respects scoping |
| 17 | **Users & Roles** | `Resources/Users`, `Resources/Roles`, `RolesPermissionsSeeder` | Permissions correctness for demo logins |
| 18 | **Reports** | `Services/Reports`, `Pages/Reports`, `Pages/ArAging` | All exports + PDFs end-to-end |
| 19 | **Mobile API `/api/v1`** | `Http/Controllers/Api/V1`, `routes/api.php`, [MOBILE-APP-BRIEF.md](../../MOBILE-APP-BRIEF.md) | Gap-analyze against the brief; design missing endpoints |
| 20 | **Cross-cutting** | i18n (`lang/en`, `lang/ar`), `Settings/*`, branding overrides, activity log, queue, storage | Last sweep; catches anything that crossed module boundaries |

## End-state gates (Module 20 closeout)

Before declaring production + demo ready:

- [ ] `php artisan test --parallel` is green
- [ ] Playwright suite (`tests/e2e/*.spec.js`) is green
- [ ] Fresh `php artisan migrate:fresh --seed` + login to each panel works in EN and AR
- [ ] Both demo scripts (DEMO.md + DEMO-ELTIZAM.md) walked end-to-end live, no surprises
- [ ] [FEATURES.md](../../FEATURES.md) reconciled — every claim either verified or removed
- [ ] `docs/gap-analysis/999-production-checklist.md` signed off: env, queue worker, scheduler, mail, storage, file permissions, backups, monitoring, error reporting, log rotation, HTTPS, CSP, rate limits, Sanctum config, ETA secrets, Paymob/InstaPay secrets
- [ ] `docs/gap-analysis/998-deferred-backlog.md` consolidated — every Yellow gap has an explicit "fix now / fix later / won't fix" call

## Cadence & rules

- **Pace:** one module per work block. No skipping ahead.
- **Commits:** one commit per module, prefixed `Audit: <module>`. Small fixes ride along; large fixes get their own PR.
- **Pause and ask** before: schema changes, removing existing features, adding new features beyond the docs, multi-day refactors, anything that touches money math, anything that changes existing PDF output.
- **Tests:** Pest, `--parallel`, real database (no DB mocks — per project standing rule).
- **Memory:** update the progress log in `docs/gap-analysis/000-progress.md` at the end of every module.

## Outputs

```
docs/gap-analysis/
├── 000-plan.md                      ← this file
├── 000-progress.md                  ← running log, updated per module
├── 01-dashboard.md
├── 02-tenants.md
├── 03-units.md
├── ...
├── 20-cross-cutting.md
├── 998-deferred-backlog.md          ← Yellow gaps awaiting decision
└── 999-production-checklist.md      ← final gate
```

## Pre-flight (Module 00, before Module 01 starts)

Before touching any module, establish baseline:

1. `php artisan test --parallel` — capture current pass/fail counts; log to `000-progress.md`.
2. `npx playwright test` (headed once for sanity; headless for the log) — same.
3. `php artisan migrate:fresh --seed` against a scratch DB — confirm seeders run clean.
4. Log into `/admin`, `/owner`, `/portal` with each role from DEMO.md table — confirm landing pages load.
5. Note current branch + commit SHA in `000-progress.md` so we can diff at the end.

Module 01 begins after pre-flight is logged.
