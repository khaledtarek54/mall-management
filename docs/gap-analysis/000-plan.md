# Atriom Module-by-Module Gap Analysis & Production-Readiness Plan

> Goal: every module audited end-to-end, every test green, demo scripts runnable live, production checklist signed off.
> Started: 2026-05-31 · **Round 2 opened 2026-07-16** for modules 21–28.
> Owner: khaled + Claude

> ### ⚠️ Round 1 covered modules 01–20 only (2026-05-31 → 06-25)
> Modules **21–28** — the general ledger and everything that posts money to it — were built
> afterwards and were never audited by this lens. Round 2 covers them. Findings continue the
> shared numbering: round 1 ended at **F-76** / **D-65**, so round 2 starts at **F-77** / **D-66**.
>
> **The four truth sources named below no longer exist.** `FEATURES.md`, `DEMO.md`,
> `DEMO-ELTIZAM.md` and `MASTER-PLAN.md` were all deleted after round 1. Use the current ones:

| Truth source (2026-07-16) | What it settles |
|---|---|
| [docs/modules/NN-*.md](../modules/) | The module's own spec — business rules, extension points, gotchas |
| [docs/BUSINESS-RULES.md](../BUSINESS-RULES.md) | Every encoded financial rule, for operator/accountant sign-off |
| [docs/FUNCTIONAL-REQUIREMENTS.md](../FUNCTIONAL-REQUIREMENTS.md) | The FRD ↔ build-status map |
| [docs/discovery/consolidated-notes-FRD.md](../discovery/consolidated-notes-FRD.md) + the Eltizam FRD | Requirements for modules 26/28 and the FM expansion |
| [docs/PROJECT-MAP.md](../PROJECT-MAP.md) | The generated census — what exists, and what each gate covers |

## Decisions locked in at kickoff

| Decision | Choice | Implication |
|---|---|---|
| Module order | Round 1: **demo-critical first**. Round 2: **money-first** — GL (21) and everything posting to it (22–26) before the rest | The blind spot is defined by "does it move money into the ledger" |
| Mobile API `/api/v1` | **In scope — audit + design missing endpoints** | Treated as Module 19. Implementation depth decided per-endpoint; default is design-only unless trivial |
| Fix policy | **Fix small, batch large** | Bugs ≤ ~30 min and missing test coverage fixed inline. Schema changes, feature gaps, design questions cataloged and flagged for explicit approval |
| Truth source | **The table above** | Anything a module doc claims but that doesn't work = P0 |

## ⚠️ Round-2 methodology change — read before auditing anything

Round 1 predates a lesson that cost two false priorities on 2026-07-16. Four "this is
missing/unprotected" findings from a multi-agent audit were verified one by one; **two were
false**, and both had the same shape: *"I grepped for mechanism M in file F, didn't find it,
therefore it's missing."* Both times the codebase did it correctly in another layer, under
another name (login throttling lives in Filament's Livewire component, not route middleware;
role auditing lives in `App\Support\AccessControlAudit`, not on the model). The two findings
that were **true** had the opposite shape — *"here is code doing the wrong thing"* — and both
reproduced on the first try.

So, for round 2:

- **An absence claim is a hypothesis, never a finding.** Before writing "X is missing", search
  the whole repo for the *capability*, not the spelling you expect, and say where you looked.
  Check `composer.lock`, not just `composer.json` — transitive deps don't appear there.
- **A finding needs a failure scenario**: concrete inputs/state → the wrong output. If you
  can't construct one, you don't have a finding.
- **Prove it by exploiting it, then prove the fix by reverting it** and watching the test fail.
- **A GL test that calls `LedgerPoster::post()`/`sync()` directly proves only the journalizer's
  arithmetic** — not that production ever posts. An applied SLA penalty shipped green that way
  while cutting a vendor bill and posting nothing. Money paths must be driven through the real
  service + `accounting:sync-ledger`.
- **…and driving the real service is necessary but NOT sufficient — the INPUTS must also be
  reachable from the product.** `GrniClearingTest` dodged the trap above perfectly (real services,
  real sweep, no `LedgerPoster`) and was still green over a bug: its helper set
  `vendor_bills.purchase_request_id`, **a column no UI, service, seeder or route can write**. Nine
  passing tests over dead code, while every real bill double-counted its cost (F-100). So ask of
  every fixture: *could the product actually produce this state?* If the answer needs a `create()`
  with a column no form offers, the test is proving the wrong thing. A Livewire test driving the
  form would have failed instantly.
- **When a finding is latent only because another bug blocks it, say so and fix them together.**
  F-101 (two bills clear GRNI twice) is unreachable *because* F-100 blocks the link — so fixing
  F-100 alone would ship the double-clear as its first act.
- When a claim turns out false, **retire it with the reason** (see [ROADMAP §6](../ROADMAP.md))
  rather than deleting it, or the next audit re-derives it.

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
| 09 | **Maintenance / CAFM** | `Resources/MaintenanceRequests`, `MaintenanceRequestService`, `SlaSettings`, `OpenMaintenanceRequests` widget | Shipped sprint feature; portal flow |
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
