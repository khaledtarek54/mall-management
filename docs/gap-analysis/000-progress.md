# Gap-Analysis Progress Log

> Running log. One entry per module + pre-flight. Most recent at the bottom.
> See [000-plan.md](000-plan.md) for the plan this log tracks.

## Round 2 — modules 21–29 (2026-07-16 → 07-17)

Round 1 (2026-05-31 → 06-25) covered **modules 01–20**. Modules **21–29** — the general ledger and
everything that posts money to it — were built afterwards and had **never been audited**. That blind
spot was the single largest known risk in the project; this round closed it. **Every module now has
an analysis.**

**It found six 🔴 money bugs in modules nobody had ever looked at. All six are now fixed**
(`GapAnalysisRound2FixesTest`, `PostingDateGuardTest`, `ValuelessStockMovementTest`,
`StockFloorAndStrictestBandTest` — each verified to fail without its fix). The remaining 🟡/🟢
findings are catalogued as F-85…F-99 / D-66…D-89 per the project's "fix small, batch large" policy.

| # | Module | Status | Doc | Headline |
|---|---|---|---|---|
| 21 | General Ledger | 🟡 Yellow | [21-general-ledger.md](21-general-ledger.md) | **F-79 FIXED** — `matches()` ignored `entry_date`, so a date-only edit stranded the entry in the wrong period, undetectably (the close gate and `--deep` reconcile were blind **by construction**, since `wouldChange()` reuses `matches()`). The sibling of the MaintenancePenalty bug that survived the registry fix: not a missing *source*, but a source **field the reconciler never compared**. |
| 22 | Inventory & Stock | 🟢 Green | [22-inventory.md](22-inventory.md) | **F-83 FIXED** — a `unit_cost = 0` item consumed stock and posted **nothing** to the GL (Inventory inflates forever) *and* collapsed the approval ladder to tier_1; one guard closed both. **F-84 FIXED** a negative `adjustment` had no floor → negative on-hand + a credit balance on an asset account. Both reproduced live; the floor now keys on the SIGN, not the type. |
| 23 | Fixed Assets | 🟡 Yellow | [23-fixed-assets.md](23-fixed-assets.md) | **F-86** editing `acquisition_cost` below posted accumulated → **negative NBV, depreciation stops forever**. The clamp only protects the forward run, never a retroactive re-cost — which the model explicitly supports. |
| 24 | HR / Payroll | 🟡 Yellow | [24-hr-employees.md](24-hr-employees.md) | **F-90b** a payroll *line*'s net can go negative (the guard checks only the header) → a payslip printing **Net −1,000** on a frozen run. **F-91** a mis-keyed repayment is permanently uncorrectable. |
| 25 | Treasury / Custody | 🔴 → 🟡 | [25-treasury-custody.md](25-treasury-custody.md) | **F-93 FIXED** — a settlement dated into a closed period **silently diverged the GL from outstanding**, and back-dating across a close is a عهدة's *normal* workflow, not an edge case (the queued job swallows the throw; the operator is told it succeeded). Now refused by `App\Support\PostingDate`, which closes F-89 in module 24 too — one bug, two hats. **F-94** no correction path exists for any settlement — the only other money document in Atriom without one. |
| 26 | Facility Maintenance | 🔴 → 🟡 | [26-preventive-maintenance.md](26-preventive-maintenance.md) | **F-77 FIXED** a penalty could be charged to **another property's** vendor bill (wrong mall absorbs it; cross-property read leak in the picker). **F-78 FIXED** waiving an *applied* penalty never recomputed the bill → bills say 9,000, GL says 10,000, **AP tie-out breaks and the vendor is underpaid**. |
| 27 | Announcements | 🟡 Yellow | [27-announcements.md](27-announcements.md) | **No defect found** — every leading hypothesis checked and disproved. The finding is coverage: 8 tests, and business rule 6 (partial-blast `sent_at`) has none. Compose-is-send: a wrong blast cannot be recalled. |
| 28 | Approvals | 🟢 Green | [28-approvals.md](28-approvals.md) | **F-99 FIXED** the module whose job is to fail closed **failed open on an overlap** — it picked the *lowest* covering band, not the strictest, so a reasonable-looking ladder edit let tier_2 approve a tier_3 amount. Its blast radius doubled mid-audit when procurement became a second caller. |
| 29 | **Procurement** | 🔴 → 🟢 | [29-procurement.md](29-procurement.md) | **F-100 FIXED** the GRNI clearing shipped in `9ba4562` **had no production writer** — nothing in `app/` can set `vendor_bills.purchase_request_id`, so every stock purchase with a supplier bill still double-counts its cost. Correct journalizer; dead code. **F-101 FIXED** two bills against one purchase cleared GRNI twice — latent *only* because F-100 blocked the link, so they were fixed in one commit. **F-102/F-103 FIXED** `cancel()`/`order()` skipped the tier check `approve()`/`reject()` enforce. **F-104 FIXED**: the create page bypassed the service, so `required_permission` was NULL on 100% of rows and a request with no lines was approvable — the tier now derives in `recomputeTotal()` and FR-PROC-01 is enforced at `approve()`. **F-105 FIXED** (soft-deleted warehouse null-deref — `withTrashed()`, the pattern three sibling models already carried). All six findings closed. |

**The pattern across all eight:** every 🔴 is a *sibling of a guard that already exists elsewhere*.
The cross-property penalty is the stock-draw guard, unwritten. The 0-cost consumption is the
0-cost receipt guard, unwritten. `waive()` is `detach()`, minus the recompute. The lesson is less
"these modules are bad" than **"a guard written once tends not to get written twice"** — which is an
argument for conformance gates over vigilance.

---

## Status snapshot — round 1 (modules 01–20)

| # | Module | Status | Doc | Notes |
|---|---|---|---|---|
| 00 | Pre-flight | 🟢 Green | [00-preflight.md](00-preflight.md) | Pest 287/287 · migrate+seed clean · 3 panels respond · API JSON contract OK |
| 01 | Dashboard & Widgets | 🟡 Yellow | [01-dashboard.md](01-dashboard.md) | Code healthy (45 widget + 36 page + 15 e2e tests green); 5 findings — DEMO.md narrative drift (F-1..F-3), MRR sparkline UX (F-4), percentDelta latent bug (F-5); 4 deferred decisions D-1..D-4; one inline fix F-6 (seeder log message). |
| 02 | Tenants | 🟢 Green | [02-tenants.md](02-tenants.md) | Tenant model + auth flow + portal panel + 3-endpoint mobile API all clean. 75 tests + 4 portal e2e green. 5 Yellow extensibility findings (F-7..F-11) — none block demo or pilot. |
| 03 | Units | 🟡 Yellow | [03-units.md](03-units.md) | 2 inline fixes — F-12 importer enum drift (accepted `anchor`/`other`, rejected `storage`), F-17 nav badge tenant leak (Unit only). F-17 also affects Invoices/Maintenance/Vendors/TenantSales — carry-over to those modules. |
| 04 | Leases | 🟡 Yellow | [04-leases.md](04-leases.md) | 3 lifecycle services (Creation/Renewal/Termination) excellent; standard Create/Edit form bypasses them — no charges seeded, no unit flip, rent fields drift from Charge::amount. Demo path (wizard + modals) safe. No carryover from Module 03 F-17. |
| 05 | Invoices | 🟡 Yellow | [05-invoices.md](05-invoices.md) | F-17 carryover fix applied (overdue badge now per-property). 3 deferred — F-22 no cron schedule for billing/late-fees, F-23 InvoiceIssued mail doesn't attach PDF, F-24 ETA mock mode is the default. 63 Pest + 9 PDF/ETA e2e all green. |
| 06 | Payments | 🟢 Green | [06-payments.md](06-payments.md) | Boot hooks (`saved`/`deleted` → recompute) are codebase's gold standard for consistency — pattern Lease (D-12) should adopt. 3 Yellow extensibility: F-25 per-row allocation guard, F-26 cross-tenant pivot constraint, F-27 dedicated tests. No F-17 carryover. |
| 07 | CAM | 🟢 Green | [07-cam.md](07-cam.md) | Well-scoped MVP per FEATURES.md "v1 intentionally shallow". 4 Yellow extensibility (mid-year termination, portal visibility, auto-true-up scheduling, expense categories) all align with documented Q2 roadmap. No F-17 carryover. |
| 08 | ETA e-invoicing | 🟡 Yellow | [08-eta.md](08-eta.md) | F-32 inline fix: ETA reference block (submission_id / long_id / submitted_at) now on Invoice PDF, bilingual. 3 Yellow extensibility (F-33 Rejected tile filter, F-34 job retry/backoff, F-35 Pending tile non-clickable). D-17 cutover sequence specified. |
| 09 | Maintenance / CAFM | 🟡 Yellow | [09-maintenance.md](09-maintenance.md) | F-17 carryover fix on both badge methods. 3 Yellow: F-36 MaintenanceSettings SLA props are unused (service reads config), F-37 no notifications on status/SLA, F-38 auto_close_after_days never acted on. 17 Pest + 3 e2e green. |
| 10 | Owner Portal panel | 🟢 Green | [10-owner-portal.md](10-owner-portal.md) | Mature read-only third panel. 3 resources scoped via `asset_owner` pivot (no Filament tenancy). PortfolioStats widget + Statement PDF. 2 Yellow: F-40 nav badges, F-41 dormant `cam.view` permission. 11 Pest + 5 e2e green. |
| 11 | Tenant Portal panel | 🟢 Green | [11-tenant-portal.md](11-tenant-portal.md) | 4 resources (Invoices/Payments/Maintenance/Sales) properly scoped + bilingual. AccountBalance + OpenMaintenance widgets, TenantStatementPdfService. 4 Yellow: F-42 Pay Now is a stub, F-43 no portal CAM view, F-44 no lock notification, F-45 no archive ZIP. Cross-refs F-8/F-9. |
| 12 | Tenant Sales Declarations | 🟡 Yellow | [12-tenant-sales.md](12-tenant-sales.md) | F-17 carryover fix applied. 4 Yellow: F-48 no void-locked action, F-49 plaintext sales values, F-50 missing `cancelled`/`voided` enum states, F-51 no re-submission flow. 9 Pest + 5 e2e green. |
| 13 | Utility Meters & Energy | 🟡 Yellow | [13-utilities.md](13-utilities.md) | Meter registry clean; data model + widget good. **F-52**: no UI to add readings post-seed (operator must use tinker). F-53: no dedicated model tests. F-54: Q3 roadmap for consumption-billing. No F-17. |
| 14 | Credit Notes | 🟡 Yellow | [14-credit-notes.md](14-credit-notes.md) | F-17 carryover fix (6th instance, missed earlier). 2 Yellow: F-55 `partially_applied` dead filter on Tenant::outstandingBalance, F-56 no portal/owner credit visibility. 8 Pest + 6 e2e green. |
| 15 | Vendors & Contracts | 🟡 Yellow | [15-vendors.md](15-vendors.md) | **F-17 carryover #6 complete — all done.** 3 Yellow: F-58 no contract auto-expire, F-59 no tax_id format validation, F-60 no VendorTest. 5 Pest + 6 e2e green. |
| 16 | Assets (tenancy) | 🟢 Green | [16-assets-tenancy.md](16-assets-tenancy.md) | 80-test coverage on the most thoughtfully-designed code in the repo. 3-trait split (Scopes/Bypasses/CustomScope) + ALL-as-real-row pattern + per-property branding. 2 Yellow: F-61 no AssetTest, F-62 no multi-property growth UX. |
| 17 | Users & Roles | 🟡 Yellow | [17-users-roles.md](17-users-roles.md) | RBAC production-ready: 6 roles · 81 perms · 18 modules · Spatie cache w/ explicit forget after role mutations. 5 Yellow operational-readiness gaps: F-63 default `password`, F-64 no self-service, F-65 no MFA, F-66 free-form pivot role, F-67 no User LogsActivity. |
| 18 | Reports | 🟡 Yellow | [18-reports.md](18-reports.md) | ReportService 264 LOC pure math + bilingual PDF + filtered ActivityLog. 3 Yellow: F-68 reports pages gate on module flag only (no `reports.view` check), F-69 exporters all sync (queue for prod), F-70 no query caching. |
| 19 | Mobile API `/api/v1` | 🟢 Green | [19-mobile-api.md](19-mobile-api.md) | **Implemented 2026-06-01.** Full `/me/*` parity + auth completion + device registration (~22 endpoints) shipped via single-action classes; 52 Api/V1 Pest cases, full suite 408/408. Dev docs: [docs/api/MOBILE-API.md](../api/MOBILE-API.md). F-71/F-72 resolved; F-73 partial (registration done, push delivery post-pilot). Pay Now excluded (D-33). |
| 20 | Cross-cutting | 🟢 Green | [20-cross-cutting.md](20-cross-cutting.md) | i18n comprehensive (1277 lines × 2 langs · TranslationCoverageTest); queue infra in place; branding correct. 3 Yellow runbook items: F-74 queue worker docs, F-75 activity-log retention, F-76 storage:link. **Sweep complete.** |

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

### 2026-05-31 — Module 06 Payments 🟢

- Payment model (138 LOC): `creating` race-safe ref-gen, `saved` + `deleted` both fire `recomputeAllocatedInvoices()`. THE gold-standard side-effect pattern in this codebase.
- Pivot `invoice_payment` has `allocated_amount`; `Invoice::recomputeTotals()` reads only `captured` payments.
- Admin: PaymentForm has live allocation repeater — picks tenant's `balance>0` invoices oldest-first, auto-fills `min(balance, remaining)`, color-coded "Allocated/Unallocated" summary. Best UX in the codebase.
- EditPayment recomputes BOTH previously- and newly-attached invoices (covers re-allocation).
- Portal: read-only payments list (tenant_id scope).
- No F-17 carryover.
- **3 Yellow findings (no inline fixes)**:
  - **F-25**: form lacks per-row `allocated_amount ≤ invoice.balance` validation. Admin can over-allocate → invoice gets paid_amount > total. UI auto-suggest prevents in normal use; rule needed.
  - **F-26**: no DB/model guard preventing cross-tenant allocation in pivot. UI filters by tenant; raw inserts can bypass.
  - **F-27**: no dedicated PaymentTest.php / allocation-recompute test. 11 indirect tests pass (scoping, widgets, reports).
- **Cross-link**: D-12 (Module 04 Lease observer) recommendation = "do what Payment does in boot()".

**Next:** Module 07 — CAM.

### 2026-05-31 — Module 07 CAM 🟢

- CamExpensePool (per asset+period_year, status `draft → reconciling → reconciled → closed`).
- CamAllocation (per pool+lease, status `pending → billed → disputed → closed`, supports negative true_up for credits).
- CamReconciliationService 171 LOC: `generateAllocations` is idempotent (updateOrCreate on pool+lease key); `bill` creates one-off Charge `vat_applicable=false`; `autoTrueUpForYear` walks all pools, optionally auto-bills.
- CLI `cam:reconcile --year=YYYY [--auto-bill]`. **Not scheduled** — explicit Q2 per FEATURES.md L411.
- No F-17 carryover. No portal/owner CAM surface.
- **4 Yellow extensibility findings** (none are bugs — all match documented Q2 roadmap):
  - **F-28**: mid-year lease termination leaks CAM accounting (terminated lease excluded from year-end; no proration/credit). Product decision.
  - **F-29**: no portal CAM visibility — tenant sees the charge with no breakdown.
  - **F-30**: auto-true-up not scheduled (matches F-22 / F-30 batching for Module 20 cron commit).
  - **F-31**: single `total_actual_expense` field — no per-category breakdown (security/cleaning/HVAC/etc.).
- Tests: 12 Pest `CamAutoTrueUpTest` cases green · 2 e2e green · full Pest 287/287.

**Next:** Module 08 — ETA e-invoicing.

### 2026-05-31 — Module 08 ETA 🟡

- Services: EtaJsonBuilder (147 LOC, EGS code map, 5-decimal round, tax-id guard); EtaSubmissionService (71 LOC, idempotent on valid); EtaApiClient (83 LOC, mock vs OAuth real).
- EtaSettings exposes 4 fields (`enabled, mock, issuer_name, issuer_TRN`) at `/admin/settings → ETA`.
- Invoice surface: 5 ETA columns; 6-state enum; submit/bulk-submit actions visible only when `status ∈ ['issued','partially_paid','paid','overdue'] && eta_status !== 'valid'`.
- **Inline fix — F-32** 🟡: Invoice PDF had no ETA reference block. Now shows submission_id + long_id + submitted_at in a teal-bordered block above the footer; bilingual via 4 new `admin.pdf.eta_*` keys.
- **3 Yellow findings (deferred)**:
  - **F-33**: EtaCompliance Rejected tile counts `invalid+rejected` but URL filter is `invalid` only.
  - **F-34**: SubmitInvoiceToEta uses default Laravel retries (3) with no backoff — could rate-limit auth on production.
  - **F-35**: EtaCompliance Pending tile not clickable (DEMO.md says all tiles are).
- **D-17 production-cutover spec added** (env vars + Settings fields + pre-cutover validation steps + rollback plan).
- Tests: 18 ETA Pest green · 9 PDF/ETA e2e green · full Pest 287/287 after fix.

**Next:** Module 09 — Maintenance / CAFM.

### 2026-05-31 — Module 09 Maintenance 🟡

- MaintenanceRequest (130 LOC): 18 fillable, 7-state status, 4 priorities, 7 categories, 6 channels, polymorphic comments, Spatie media, LogsActivity.
- State machine in service `TRANSITIONS` constant (explicit, testable).
- Service methods: `create`, `transition` (validates), `assign` (auto-transitions submitted→acknowledged), `comment` (uses `getMorphClass()`), `defaultTargetResolution` (reads config).
- 3 panels: Admin (full CRUD + Change Status modal + Assign modal + Vendor select), Portal (tenant submits + comments back), Owner (read-only via `unit.asset.owners`).
- OpenMaintenanceRequests widget correctly tenant-scoped via `TenantScope::applyTo(..., 'unit')`.
- **Inline fix — F-17 (Maintenance carryover)** 🔴: BOTH `getNavigationBadge()` and `getNavigationBadgeColor()` did raw `MaintenanceRequest::whereIn(...)` queries — leaked across properties. Now use `static::getEloquentQuery()`.
- **3 Yellow findings (deferred)**:
  - **F-36**: MaintenanceSettings has 4 SLA props that are NEVER read by anything — service reads `config('maintenance.sla.*')` instead. Numbers diverge (Settings 4h urgent vs config 24h urgent). Either delete props or wire service to Settings (recommend latter for operator tunability).
  - **F-37**: No notifications on status change, SLA breach, or vendor assignment.
  - **F-38**: `config('maintenance.auto_close_after_days')` is read but no job/command acts on it. `resolved` is effectively terminal in practice.
- Tests: 17 Pest green · 3 functional e2e green · full Pest 287/287 after fix.
- **Cross-cutting F-17 progress**: ✅ Units · ✅ Invoices · ✅ Maintenance · ⏳ TenantSales (Module 12) · ⏳ Vendors (Module 15).

**Paused here per user's "go through Module 9 then pause" choice.** Modules 10-20 ahead. Recommend triage of D-1..D-30 before continuing.

### 2026-05-31 — D-1, D-2, D-12 applied; D-15 retracted

Per the user's "do recommended" instruction after triage.

- **D-12 applied**: `LeaseObserver` + `LeaseCreationService::seedStandardCharges` static helper + `CreateLease::afterCreate` hook. Form path now seeds charges and the observer flips unit status across all paths. 8 new Pest tests; full suite 295/295.
- **D-15 retracted**: pre-flight grep miss; routes/console.php already schedules the 3 jobs via `BillingSettings`. Findings F-22 / F-30 marked superseded in their module docs.
- **D-1 + D-2 applied**: `HayaWalkSeeder::DEMO_RNG_SEED = 4242` + `mt_srand` at run() top + 3 `->inRandomOrder()` queries replaced with `orderByRaw('(id * 17) % 101)` (SQLite RANDOM() can't be seeded). Two consecutive seeds now produce identical `EGP 656,516.19` AR. DEMO.md §2 has new "Step 0: switch to Haya Walk" pre-step + locked KPI numbers (Collected ~170K, AR ~657K, 11 overdue, ~588K past due). Plaza Annex kept with explainer in §4.

### 2026-05-31 — Module 10 Owner Portal 🟢

- Panel: `Atriom · Owner Portal` at `/owner`, no Filament tenancy.
- Resources: Properties + Invoices + Maintenance, all read-only, scoped via `whereHas('...owners', fn => where('user_id', auth()->id()))` chains.
- PortfolioStats widget: 4 stats (Assets count + leasable area, Occupancy %, MRR, Outstanding AR), aggregated across owner's owned assets.
- Statement PDF action on ViewProperty → `AssetStatementPdfService` (12-month trailing); HTTP+Livewire test added in commit 7a10690.
- Role gating: owner ↔ admin panel mutually exclusive in `User::canAccessPanel`.
- **2 Yellow** (deferred):
  - **F-40**: no nav badges on Owner Resources — useful for multi-asset owners (overdue count, open maintenance).
  - **F-41**: `'cam.view'` permission granted to owner role but no Owner CAM resource exists. Dormant.
- No F-17 carryover. No PII leakage.
- Tests: 11 Pest · 5 e2e green.

**Next:** Module 11 — Tenant Portal.

### 2026-05-31 — Module 11 Tenant Portal 🟢

- Panel: `Atriom · Tenant Portal` at `/portal`, dedicated `portal` guard, SetLocale middleware (RTL-ready).
- 4 Resources: Invoices (read-only + Download PDF + Download Statement + Pay Now stub), Payments (read-only), MaintenanceRequests (submit + view + public-comments-only RelationManager), TenantSalesDeclarations (submit + view; admin-driven `locked|disputed` state machine).
- 2 Widgets: AccountBalance (4 stats via `Tenant::outstandingBalance` netting credit notes), OpenMaintenance (table widget).
- TenantStatementPdfService 12-month trailing; mPDF; invoked from BOTH admin and portal.
- No F-17 carryover. PII isolation correct (`tenant_id` filter on all 4 resources). Internal comments hidden via `is_internal=false` filter.
- **4 Yellow** (all forward-looking, none block demo):
  - **F-42**: Pay Now action is a stub (notification only) gated by `integrations.paymob.enabled`. Production needs real PSP integration.
  - **F-43**: No portal CAM allocation view (cross-ref M07 F-29, M10 F-41 — bundle decision).
  - **F-44**: No notification to tenant on declaration lock — tenant must refresh.
  - **F-45**: No "Download 12-month archive ZIP" convenience.
- Cross-refs M02 F-8 (no password reset) + F-9 (no profile self-update) re-flagged for Module 20 cross-cutting.
- Tests: 4 portal Pest + 4 e2e + 2 portal-flow tests in 17-functional-actions all green.

**Next:** Module 12 — Tenant Sales Declarations + Percentage Rent.

### 2026-05-31 — Module 12 Tenant Sales 🟡

- TenantSalesDeclaration model (81 LOC, polymorphic `declared_by` (User|Tenant), 3-state status `submitted|locked|disputed`).
- Unique constraint `(lease_id, period_start)` prevents duplicates.
- PercentageRentCalculationService (102 LOC): both `artificial` and `natural_breakpoint` formulas; idempotent `lock()` creates a one-off Charge.
- Admin resource: Lock + Dispute record actions; lock auto-creates `percentage_rent` Charge for next monthly run.
- **Inline fix — F-17 carryover** 🔴: `TenantSalesDeclaration::where(...)` → `static::getEloquentQuery()->where(...)` so nav badge respects tenant scope.
- **4 Yellow** (deferred):
  - **F-48**: No mechanism to void a locked declaration (Charge stays active even if dispute discovered later).
  - **F-49**: `declared_sales` stored plaintext (legal review may require encryption).
  - **F-50**: Status enum missing `cancelled`/`voided` states.
  - **F-51**: No re-submission workflow (unique constraint forces hard/soft delete-and-retry).
- Cross-cutting F-17 progress: ✅ Units · ✅ Invoices · ✅ Maintenance · ✅ TenantSales · ⏳ Vendors (M15).
- Tests: 9 Pest + 5 e2e green. Full Pest 295/295.

**Next:** Module 13 — Utility Meters & Energy.

### 2026-05-31 — Module 13 Utilities 🟡

- UtilityMeter (53 LOC) + MeterReading (33 LOC). 3 types (electric/water/gas), 3 statuses. unit_id nullable for common-area. Unique (utility_meter_id, reading_date) prevents duplicates.
- Admin UtilityMeterResource with 7-col form + 7-col table; type/status colour-coded; `readings_count` badge.
- EnergyConsumptionTrend widget (already covered M01) — driver-aware month grouping, common-area + per-unit aggregation, locale-aware labels.
- **F-52** 🟡: no admin UI for MeterReadings — no Resource, no RelationManager. Operator must `php artisan tinker` to add readings post-seed. By-design per FEATURES.md L411 "v1 monitoring-only"; D-38 catalogs the RelationManager work.
- F-53 🟡: no dedicated model tests (bundle with post-sweep test pass).
- F-54 🟡: consumption → billing is Q3 roadmap.
- No F-17 carryover; no owner/portal exposure (admin-only).
- Tests: 4 Pest + 2 e2e green.

**Next:** Module 14 — Credit Notes.

### 2026-05-31 — Module 14 Credit Notes 🟡

- CreditNote (118 LOC) + CreditNoteItem (30 LOC). Status enum (`draft, issued, applied, void`). Boot auto-numbers CN-HW-YYYYMM-####.
- CreditNoteService (108 LOC): `issue`, `applyToInvoice` (atomic both sides; caps at min of 3 balances), `void` (throws if applied_amount > 0). All idempotent + DB-transactional.
- Admin: custom `getEloquentQuery()` scopes via `lease.unit.asset_id` OR allows standalone (lease_id IS NULL) notes. Form has live recompute. 4 record actions (Issue/Apply/Void/Delete).
- **Inline fix — F-17 (CreditNotes, 6th carryover)** 🔴: nav badge was `static::getModel()::query()`; now uses `static::getEloquentQuery()`. I missed this in Module 03's audit (listed 5 resources, actually 6). Updated cross-cutting status.
- **2 Yellow** (deferred):
  - **F-55**: `Tenant::outstandingBalance()` filters credit notes for `whereIn('status', ['issued', 'partially_applied'])` — but the enum has no `partially_applied` value. Harmless dead filter; clean up by removing or extending the enum.
  - **F-56**: No portal/owner visibility of credit notes (tenant sees balance shrink but no line-item).
- Positive: void-applied refusal forces offsetting note (preserves AR audit trail). Standalone notes supported via nullable FKs + scoped query.
- Tests: 8 Pest + 6 e2e green. Full Pest 295/295.
- **Cross-cutting F-17 corrected**: 5 of 6 (was thinking 4 of 5). Vendors (M15) is the last.

**Next:** Module 15 — Vendors.

### 2026-05-31 — Module 15 Vendors 🟡

- Vendor + VendorContact + VendorContract — single migration creates all 3 + modifies maintenance_requests to add `assigned_to_vendor_id`.
- VendorResource is **global** (`$isScopedToTenant = false`); vendors serve cross-property. Contracts can be asset-scoped (nullable asset_id).
- Two RelationManagers on VendorResource: Contacts (primary-first sort), Contracts.
- **Inline fix — F-17 (Vendors, final carryover, #6)** 🔴: nav badge queries `VendorContract` (different from resource's `Vendor` model). Used `TenantScope::currentAssetId()` directly rather than `static::getEloquentQuery()` because the badge's underlying model differs. Now Haya Walk view shows only Haya Walk's contracts expiring; ALL view shows portfolio-wide.
- **Cross-cutting F-17 COMPLETE**: ✅ Units · ✅ Invoices · ✅ Maintenance · ✅ TenantSales · ✅ CreditNotes · ✅ Vendors.
- **3 Yellow** (deferred):
  - **F-58**: no scheduled auto-expire for vendor contracts; `active` stays `active` past `end_date`.
  - **F-59**: no `tax_id` format validation (would catch bad data pre-ETA submission).
  - **F-60**: no dedicated VendorTest.
- Tests: 5 Pest + 6 e2e (1 flake on slow run, passed on retry) all green. Full Pest 295/295.

**Next:** Module 16 — Assets / Tenancy.

### 2026-05-31 — Module 16 Assets & Tenancy 🟢

- Asset (150 LOC, ALL_PROPERTIES_CODE constant + isAllProperties() check).
- Operator concept retired by `2026_05_25_215041`; per-property branding moves onto Asset (logo, favicon, primary_color).
- TenantScope (99 LOC): `currentAssetId`, `applyTo($q, ?$relation)`, `visibleAssetIds()`.
- 3 scoping traits with sharp responsibility split (ScopesViaProperty for indirect-FK · BypassesScopingOnAll for direct-FK · BypassesFilamentTenantAutoScope for custom).
- AssetResource hides the ALL row from management list; canCreate blocked inside a property (force ALL view to add).
- RegisterProperty tenancy page handles fresh-install onboarding (zero-property case).
- User::getTenants prepends ALL only for multi-property users.
- **2 Yellow** (deferred):
  - **F-61**: no dedicated AssetTest for the model's computed methods.
  - **F-62**: no multi-property growth path UX (single-property managers can't add a sibling without a super_admin).
- Recent commit `4a96a67` fixed a CSS injection bug in per-property branding; that work is consistent with the design.
- Tests: **80** Pest cases across Tenancy/Asset/Branding all green (most heavily tested area of the repo). Full Pest 295/295.

**Next:** Module 17 — Users + Roles.

### 2026-05-31 — Module 17 Users & Roles 🟡

- User (119 LOC): `Authenticatable + FilamentUser + HasTenants`. `HasRoles, Notifiable` traits. **No LogsActivity** — F-67.
- 6 built-in roles + 81 permissions across 18 modules. Naming: `{module}.{action}` (e.g. `invoices.run_monthly_billing`, `leases.terminate`).
- Spatie cache TTL 24h **with explicit `forgetCachedPermissions()` after Create + Edit role** — fresh perms within request.
- Admin/Users hardcoded to super_admin only. Users form has Properties multi-select (defaults to all real assets on create, then deselect to restrict).
- Admin/Roles uses `RoleGatedActions` trait; form has one CheckboxList per module; built-in role names locked.
- **5 Yellow** (deferred — all pre-pilot ops gaps):
  - **F-63**: seeded users all use literal `'password'`. Need env-driven rotation.
  - **F-64**: no user self-service (profile, password reset).
  - **F-65**: no 2FA / MFA.
  - **F-66**: `asset_user.role` is free-form string; barely used. Enum, remove, or document.
  - **F-67**: User CRUD has no activity log. Add LogsActivity pre-pilot.
- Tests: 31 Pest --filter='User|Role|Permission|PanelAccess' green. Full Pest 295/295.

**Next:** Module 18 — Reports.

### 2026-05-31 — Module 18 Reports 🟡

- ReportService (264 LOC): `monthlyClose`, `arAgingBuckets`, `arAgingDrilldown(bucket)`, `topDelinquentTenants`. Pure math, TenantScope-aware.
- MonthlyCloseReportPdfService bilingual via mPDF (xbriyaz/dejavusans), filename `atriom-monthly-close-YYYY-mm.pdf`.
- Reports page: period picker + KPI cards + AR Aging bucket cards (color-coded clickable) + Revenue by Type + Download PDF action.
- ActivityLog page: 6 period presets + custom range + log_name + event filter. Polymorphic causer (User or Tenant).
- 5 Exporters (Tenant/Lease/Invoice/Payment/Unit) all `sync` connection.
- Settings page: 5 settings classes across Modules/Billing/Maintenance/ETA/Integrations tabs. `settings.view` + `settings.manage` gates.
- **3 Yellow** (deferred):
  - **F-68**: Reports + ArAging gate on `Modules::enabled('reports')` only — no `reports.view` perm check (Spatie permission exists but isn't consulted).
  - **F-69**: All 5 exporters sync. For larger datasets need queued.
  - **F-70**: No query caching on ReportService — each page load runs fresh aggregations.
- Tests: 31 Pest `Report|ActivityLog` green · 4 e2e green · Full Pest 295/295.

**Next:** Module 19 — Mobile API.

### 2026-05-31 — Module 19 Mobile API 🟡

- Current state: 3 auth endpoints (`login`, `me`, `logout`) all tested via LoginTest (8 cases) + AuthenticatedRoutesTest. Quality is the reference bar for the rest.
- Designed shortlist organized into 7 groups (auth completion / profile + balance / invoices / payments / maintenance / sales / devices) — ~21 endpoints matching MOBILE-APP-BRIEF.md parity.
- **3 Yellow** (deferred — design module, not implementation):
  - **F-71**: only auth endpoints shipped; rest of the surface unimplemented.
  - **F-72**: no password reset flow (cross-ref M02 F-8 + portal panel) — bundle.
  - **F-73**: no device-tokens table or push pipeline (depends on D-29 notification design).
- D-56: approve the endpoint shortlist + green-light implementation.
- D-57: combine password-reset across web portal (M02 F-8) and mobile API in one flow.
- Tests: 20 Pest `Api/V1` cases green; full Pest 295/295.

**Next:** Module 20 — Cross-cutting + production checklist (final).

### 2026-05-31 — Module 20 Cross-cutting 🟢 — **SWEEP CLOSEOUT**

- i18n: 1,277 lines per language; TranslationCoverageTest enforces 76+ canonical keys across enums + roles + activity log subjects.
- Queue: db driver, `jobs` + `job_batches` + `failed_jobs` tables provisioned.
- Storage: Spatie MediaLibrary (logos / favicons on Asset; documents on Lease + MaintenanceRequest; KYC on Tenant).
- Logging: stack → single channel; no Sentry/Pulse/Telescope/Nightwatch wired.
- Security: SetLocale append; CSRF excluded from `api/*`; login throttle 5/min; sessions db driver.
- Branding: per-property logo + favicon + primary_color; recent commit `4a96a67` correctly fixed an invalid-CSS injection bug.
- CI: PHPUnit + Playwright on every PR.
- **3 Yellow runbook items**:
  - **F-74**: no documented queue-worker deployment path.
  - **F-75**: no activity-log retention (Spatie ships `activitylog:clean`).
  - **F-76**: production deploy must run `storage:link`.
- All 3 are on the production checklist.

### 2026-05-31 — SWEEP COMPLETE

- **21 modules audited** (pre-flight + 1-20). **18 commits**. **8 inline fixes**. Pest **287 → 295**.
- F-17 cross-cutting nav-badge fix: **complete** (6/6 resources).
- D-1, D-2, D-12 recommendations applied; D-15 retracted; remaining 57 decisions catalogued in [998-deferred-backlog.md](998-deferred-backlog.md).
- Production checklist drafted in [999-production-checklist.md](999-production-checklist.md) — needs operator sign-off.

**Final module ratings:** 00 🟢 · 01 🟡 · 02 🟢 · 03 🟡 · 04 🟡 · 05 🟡 · 06 🟢 · 07 🟢 · 08 🟡 · 09 🟡 · 10 🟢 · 11 🟢 · 12 🟡 · 13 🟡 · 14 🟡 · 15 🟡 · 16 🟢 · 17 🟡 · 18 🟡 · 19 🟡 · 20 🟢.

### 2026-06-01 — Module 19 Mobile API: built (🟡 → 🟢)

Per the user's go-ahead on D-56, implemented the full mobile API surface designed in the audit.

- **~22 endpoints** across 7 groups (profile/account, invoices, payments, maintenance, sales declarations, auth completion, device tokens), all under `auth:tenant-api` except login + password-reset request/apply.
- **Architecture**: thin invokable controllers on a shared `ApiController` base (envelope/pagination/PDF helpers); **one single-action class per write** in `app/Actions/Api/V1/` (matching the praised `LoginTenantAction` pattern), delegating to existing domain services (`MaintenanceRequestService`, `PercentageRentCalculationService`, `InvoicePdfService`, `TenantStatementPdfService`) so mobile + portal share one code path (DRY); FormRequests for validation; JSON Resources for output.
- **New infra**: `SetApiLocale` middleware (Accept-Language → locale, registered on the `api` group); `tenants` password-reset broker + `tenant_password_reset_tokens` table + `TenantResetPasswordNotification`; `device_tokens` table + model; `Tenant` now implements `CanResetPassword` and gains `deviceTokens()` + `salesDeclarations()` (hasManyThrough) relations.
- **Security**: every `/me/*` route scoped server-side via `$request->user()` — cross-tenant ids return 404; anti-enumeration on login + forgot-password; reset revokes all tokens, change revokes all but current; `i18n` EN/AR with `lang/{en,ar}/api.php`.
- **Excluded**: Pay Now / payment initiation (D-33); push *delivery* fan-out (post-pilot, D-29) — token registration shipped.
- **Tests**: 52 new Api/V1 Pest cases (happy paths, cross-tenant 404 isolation, validation 422, unauthenticated 401, pagination, upsert, anti-enumeration). **Full suite 408/408 green.** Two additive migrations applied to dev DB.
- **Docs**: [docs/api/MOBILE-API.md](../api/MOBILE-API.md) — full developer reference (business domain, auth, every endpoint with request/response, error catalog, screen→endpoint map, out-of-scope list).

### 2026-06-01 — Module 19 Mobile API: reconciled with the Flutter contract

The Flutter dev sent an API contract that diverged from the build. Reconciled per the user's decisions:

- **Conventions adapted backend-wide to the FE contract**: all `/api/v1` JSON is now **camelCase** (out) and accepts camelCase (in) via `SnakeCaseRequestKeys` + `CamelCaseResponseKeys` middleware (`App\Support\KeyCase`); every error renders as `{message, statusCode}` (+ `errors` for validation) via the `render` callback in `bootstrap/app.php`.
- **Login reshaped**: request is `{email, password}` (`deviceName` now optional, defaults to User-Agent); `data` is the **leases array** (flat `LoginLeaseResource`: id/name/shop/mall/unitNumber/startDate/endDate/isActive, ISO-8601 Z dates) with the token at top-level `accessToken`/`tokenType`. Error codes **400** (bad body) / **401** (wrong creds) / **403** (blocked → app's Blocked screen), replacing the prior generic 422.
- **Security calls**: the contract's *tokenless* password reset (email + newPassword, no verification) was an account-takeover hole — **rejected**; kept the secure email-link + token flow (FE Forgot-Password screen must become two steps). The 401-vs-403 login distinction trades a little enumeration resistance for the Blocked-screen UX (accepted product tradeoff).
- **Flagged for FE confirmation**: the `name`/`shop` lease field mapping, and that `accessToken` sits at top level (the contract was self-contradictory — `data` can't be both a leases array and hold the token).
- **Tests**: LoginTest rewritten for the new shape/codes; all `/api/v1` assertions updated to camelCase. **Full suite 409/409 green (`--parallel`).**

**8 Green** modules; **13 Yellow** (no Red). Every Yellow is documented with a deferred decision; none are demo blockers; the dozen pre-pilot items are concentrated in M17 (Users) + M11 (Pay Now stub) + M08 (ETA cutover).
