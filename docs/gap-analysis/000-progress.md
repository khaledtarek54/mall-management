# Gap-Analysis Progress Log

> Running log. One entry per module + pre-flight. Most recent at the bottom.
> See [000-plan.md](000-plan.md) for the plan this log tracks.

## Status snapshot

| # | Module | Status | Doc | Notes |
|---|---|---|---|---|
| 00 | Pre-flight | 🟢 Green | [00-preflight.md](00-preflight.md) | Pest 287/287 · migrate+seed clean · 3 panels respond · API JSON contract OK |
| 01 | Dashboard & Widgets | 🟡 Yellow | [01-dashboard.md](01-dashboard.md) | Code healthy (45 widget + 36 page + 15 e2e tests green); 5 findings — DEMO.md narrative drift (F-1..F-3), MRR sparkline UX (F-4), percentDelta latent bug (F-5); 4 deferred decisions D-1..D-4; one inline fix F-6 (seeder log message). |
| 02 | Tenants | 🟢 Green | [02-tenants.md](02-tenants.md) | Tenant model + auth flow + portal panel + 3-endpoint mobile API all clean. 75 tests + 4 portal e2e green. 5 Yellow extensibility findings (F-7..F-11) — none block demo or pilot. |
| 03 | Units | 🟡 Yellow | [03-units.md](03-units.md) | 2 inline fixes — F-12 importer enum drift (accepted `anchor`/`other`, rejected `storage`), F-17 nav badge tenant leak (Unit only). F-17 also affects Invoices/Maintenance/Vendors/TenantSales — carry-over to those modules. |
| 04 | Leases | 🟡 Yellow | [04-leases.md](04-leases.md) | 3 lifecycle services (Creation/Renewal/Termination) excellent; standard Create/Edit form bypasses them — no charges seeded, no unit flip, rent fields drift from Charge::amount. Demo path (wizard + modals) safe. No carryover from Module 03 F-17. |
| 05 | Invoices | 🟡 Yellow | [05-invoices.md](05-invoices.md) | F-17 carryover fix applied (overdue badge now per-property). 3 deferred — F-22 no cron schedule for billing/late-fees, F-23 InvoiceIssued mail doesn't attach PDF, F-24 ETA mock mode is the default. 63 Pest + 9 PDF/ETA e2e all green. |
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

### 2026-05-31 — Module 02 Tenants 🟢

- Tenant model (137 LOC): `Authenticatable` + `FilamentUser` + `HasApiTokens` + `HasMedia` + `LogsActivity` + `SoftDeletes`. Auth correctly gated by `status === 'active'` in both `canAccessPanel` and `LoginTenantAction`.
- Admin: TenantResource with `RoleGatedActions` + `ScopesViaProperty` (`leases.unit`), 5 relation managers, statement PDF action, 5 filters incl. created-range + trashed.
- Portal: dedicated `portal` auth guard, 4 resources, 2 widgets (AccountBalance uses `outstandingBalance()`).
- Mobile API: 3 endpoints (login/me/logout); LoginTenantAction is the single-action class per user preference; thoroughly tested.
- Tests: **75 Pest tests + 4 portal e2e** all green. LoginTest covers wrong-pw / unknown-email / inactive / blacklisted / missing-fields / same-device-revoke / cross-device-isolation.
- **5 Yellow findings** (no inline fixes — all are extensibility/policy):
  - **F-7**: API `TenantResource` exposes `tax_id` despite model `$hidden` — confirm intentional + add comment.
  - **F-8**: No password reset flow (portal or API) — production-readiness gap, not demo blocker.
  - **F-9**: No tenant self-service profile update — Q2 mobile-app territory.
  - **F-10**: Only `/auth/*` endpoints on mobile API — explicit Q2 roadmap per DEMO-ELTIZAM; design list cross-referenced for Module 19.
  - **F-11**: `Tenant::isDelinquent()` implemented + tested but never surfaced in UI. Add badge/filter or remove.
- 5 deferred decisions (D-5..D-9) carried to end-of-sweep walk-through.

**Next:** Module 03 — Units.

### 2026-05-31 — Module 03 Units 🟡

- Unit model (69 LOC, no observers/policies/scopes); HasOne `activeLease` filtered by status + latest.
- Migration: 4-state status enum, 7-state category enum, soft delete, indexes on `(asset_id,status)` and `category`.
- Admin: UnitResource with `BypassesScopingOnAll`, ImportAction + ExportAction, table has 6 filters incl. expiring-soon/critical.
- OccupancyMap page: floor-grouped grid Blade view with status colour map (82 LOC, healthy).
- Owner panel: no dedicated UnitResource — read-only via PropertyResource.
- **2 inline fixes**:
  - **F-12** 🔴: UnitImporter category rule diverged from DB enum (accepted `anchor`/`other`, rejected `storage`). Aligned to migration enum exactly.
  - **F-17** 🔴: Nav badge `Unit::where('status','vacant')->count()` ignored Filament tenant scope. Now uses `static::getEloquentQuery()` (correct via `BypassesScopingOnAll`).
- **F-17 is cross-cutting** — same pattern present in 4 other resources:
  - InvoiceResource (Module 05) · MaintenanceRequestResource (Module 09) · VendorResource (Module 15) · TenantSalesDeclarationResource (Module 12)
  - Each will get the same fix in its own module commit.
- Tests: 16 Pest matched · 3 import e2e green · full Pest 287/287 after each fix.
- **2 deferred** (D-10 add UnitTest.php · D-11 reorder importer status enum for grep-readability).
- 1 explicit non-finding: no `app/Policies/` dir exists for ANY resource — Spatie roles + `RoleGatedActions`/`RoleScopedWidget` traits are the deliberate permission stack. Documented so it doesn't get re-flagged.

**Next:** Module 04 — Leases.

### 2026-05-31 — Module 04 Leases 🟡

- Lease model (155 LOC, 24 fillable cols, 4 date casts, 6 decimal casts, `previous_lease_id` self-ref for renewal chain).
- 3 services (Creation 106 LOC, Renewal 93 LOC, Termination 79 LOC) — all DB-transactional, all single-method, all well-tested.
- LeasesTable (422 LOC): 8 cols, 7 filters, **Quick New Lease wizard** (header) + **Renew** + **Terminate** record actions, all calling services.
- EditLease has Generate Invoice header action with 4 outcome notifications.
- Termination policy correctly preserves paid_amount ledger (does NOT auto-cancel partially-paid invoices) — excellent docstring at LeaseTerminationService:57-62.
- LeaseResource has **no** `getNavigationBadge()` — no carryover from Module 03 F-17.
- **3 findings, all the same root cause** (standard form bypasses services):
  - **F-19** 🟡: `CreateLease.php` is just `extends CreateRecord` — operators using "Create" instead of the wizard get a lease with no charges, no unit flip, no auto-reference. Demo path uses the wizard, so DEMO works; production rollout doesn't.
  - **F-20** 🟡: editing `base_rent_monthly` / `service_charge_monthly` on the Edit form doesn't propagate to `Charge::amount`. Tables/MRR/percentage-rent see the new value; billing keeps using the old.
  - **F-21** 🟡: editing `status` on the Edit form doesn't sync unit status (only the Terminate modal does that).
- Tests: 36 Pest --filter='Lease' green · 17 functional e2e (Renew/Generate-Invoice/Monthly-Billing/ETA/bulk/locale-switch) green · full Pest 287/287 still green.
- **3 deferred decisions** (D-12 fix approach for F-19/F-21 — recommend B `LeaseObserver`; D-13 fix approach for F-20 — recommend "Change rent" action + read-only display; D-14 test coverage gate).

**Next:** Module 05 — Invoices.

### 2026-05-31 — Module 05 Invoices 🟡

- Invoice (193 LOC, 19 fillable, 8-state status, 6-state eta_status, boot-time number gen, LogsActivity on 9 cols).
- InvoiceItem (50 LOC, saving-hook auto-computes vat_amount + total).
- 3 panels: Admin (full CRUD + 10 cols, 7 filters, PDF/WhatsApp/ETA/bulk-ZIP), Owner (read-only, asset.owners scope), Portal (read-only, tenant_id scope).
- Services: MonthlyBillingService 244 LOC (idempotent, chunked, prorate-aware), InvoicePdfService 56 LOC (mPDF + xbriyaz/dejavusans), LateFeeService 92 LOC (config-driven grace + percent + min), Eta/{JsonBuilder, SubmissionService, ApiClient}.
- Jobs: RunMonthlyBilling, ApplyLateFees, SubmitInvoiceToEta. **No scheduling**.
- Mail: InvoiceIssued (Markdown view, no PDF attachment).
- PDF view: 265 LOC bilingual Blade with `dir="rtl"` switch and ETA reference block.
- **Inline fix — F-17 carryover** 🔴: InvoiceResource navigation badge (overdue count) was global; now per-property via `getEloquentQuery()`.
- **3 deferred findings**:
  - **F-22** 🟡: Neither `billing:run-monthly` nor `billing:apply-late-fees` are scheduled. Production must add `->withSchedule()` + server cron. Code sketch in 05-invoices.md §F-22.
  - **F-23** 🟡: `InvoiceIssued` mail has no `attachments()`. Tenant gets email but no PDF. Fix is ~10 LOC.
  - **F-24** 🟡: ETA defaults to mock mode (correct for demo, must flip for production). Goes on production checklist.
- Positive: ETA tenant-tax-id guard throws RuntimeException pre-submission (catches the issue early); LateFeeService double-application guard; MonthlyBillingService idempotency. All tested.
- Tests: 63 Invoice/Billing/ETA/LateFee Pest green · 9 PDF/ETA e2e green · full Pest 287/287 after fix.
- Cross-cutting F-17 progress: ✅ Units · ✅ Invoices · ⏳ Maintenance · ⏳ TenantSales · ⏳ Vendors.

**Next:** Module 06 — Payments.
