# Module 18 — Reports

> Date: 2026-05-31
> Status: 🟡 Yellow — well-tested pure-math service + bilingual PDF + activity log; 3 Yellow extensibility findings.
> Surface: [Services/Reports/](../../app/Services/Reports/), [Reports page](../../app/Filament/Admin/Pages/Reports.php), [ArAging page](../../app/Filament/Admin/Pages/ArAging.php), [ActivityLog page](../../app/Filament/Admin/Pages/ActivityLog.php), 5 [Exporters](../../app/Filament/Exports/), [Settings page](../../app/Filament/Admin/Pages/Settings.php).

## 1. Inventory

### 1.1 Services

| File | LOC | Purpose |
|---|---:|---|
| [ReportService.php](../../app/Services/Reports/ReportService.php) | 264 | Pure-math AR layer. `monthlyClose(?CarbonImmutable $period)` → DTO with invoices / payments / AR aging / credit notes / revenue by type / collection rate. `arAgingBuckets(?asOf)` → 5 bucket map. `arAgingDrilldown(string $bucket, ?asOf)` → Collection of open invoices in that bucket. `topDelinquentTenants(int $limit = 10)`. All respect `TenantScope::applyTo()`. |
| [MonthlyCloseReportPdfService.php](../../app/Services/Reports/MonthlyCloseReportPdfService.php) | 58 | Wraps ReportService + mPDF. `build(CarbonImmutable $period): string` returns PDF bytes (UTF-8 A4, xbriyaz/dejavusans). `filename(CarbonImmutable $period): string` → `atriom-monthly-close-YYYY-mm.pdf`. |
| [AssetStatementPdfService](../../app/Services/AssetStatementPdfService.php) | 124 | Property-level statement (already covered Module 10). |
| [TenantStatementPdfService](../../app/Services/TenantStatementPdfService.php) | 98 | Tenant-level 12mo statement (already covered Module 11). |

### 1.2 Admin pages

| Page | LOC | Route | Notes |
|---|---:|---|---|
| [Reports.php](../../app/Filament/Admin/Pages/Reports.php) | 104 | `/admin/{tenant}/reports` | Period picker (last 12 months) + KPI cards (Invoices Issued, Payments Captured, Collection Rate %, Outstanding AR) + AR Aging bucket cards (clickable, color-coded) + Revenue by Type table + "Download Monthly Close PDF" header action. `canAccess()` gates by `Modules::enabled('reports')` **only** — no per-user perm check. See F-68. View: [filament.pages.reports](../../resources/views/filament/pages/reports.blade.php) (156 LOC). |
| [ArAging.php](../../app/Filament/Admin/Pages/ArAging.php) | 66 | `/admin/{tenant}/ar-aging` | Drilldown table for a bucket query param. `shouldRegisterNavigation = false` — reached from Reports page bucket cards. |
| [ActivityLog.php](../../app/Filament/Admin/Pages/ActivityLog.php) | 181 | `/admin/{tenant}/activity-log` | Audit trail: created_at + causer.name + log_name (badge) + subject_id (reference) + event badge + changes (HTML diff). Filters: log_name select, event select, period preset (today/yesterday/last_7d/last_30d/this_month/last_month), custom date range. Paginated [25, 50, 100]. |
| [Settings.php](../../app/Filament/Admin/Pages/Settings.php) | 315 | `/admin/{tenant}/settings` | Tabs: Modules / Billing / Maintenance / ETA / Integrations. Five settings classes drive the form. `canAccess() = settings.view`; `save() = settings.manage`. |

### 1.3 Exporters

5 Filament Exporter classes under [app/Filament/Exports/](../../app/Filament/Exports/) — TenantExporter, LeaseExporter, InvoiceExporter, PaymentExporter, UnitExporter. All `getJobConnection() = 'sync'` — **no queue, runs in-request**. See F-69.

### 1.4 Settings layer

| Settings class | Controls |
|---|---|
| [BillingSettings](../../app/Settings/BillingSettings.php) | Late fees (percent, grace_days, minimum), monthly billing day/time, CAM reconciliation month/day/time |
| [MaintenanceSettings](../../app/Settings/MaintenanceSettings.php) | SLA hours per priority — **unused** by MaintenanceRequestService per [Module 09 F-36](09-maintenance.md) |
| [IntegrationsSettings](../../app/Settings/IntegrationsSettings.php) | paymob_enabled, whatsapp_enabled toggles |
| [EtaSettings](../../app/Settings/EtaSettings.php) | enabled, mock, issuer_name, issuer_TRN |
| [ModulesSettings](../../app/Settings/ModulesSettings.php) | Per-module toggles read by `Modules::enabled(...)` everywhere |

### 1.5 Tests

| File | LOC | Coverage |
|---|---:|---|
| [ReportServiceTest.php](../../tests/Feature/ReportServiceTest.php) | 157 | `monthlyClose` invoice + payment + VAT aggregation; arAgingBuckets daysOverdue logic; topDelinquentTenants; credit-note inclusion. |
| [ActivityLogFiltersTest.php](../../tests/Feature/ActivityLogFiltersTest.php) | 129 | Period presets correctness under deterministic Carbon::setTestNow(2026-05-15). |
| [ActivityLogRenderTest.php](../../tests/Feature/ActivityLogRenderTest.php) | 125 | Column rendering matrix (causer, log_name colors, subject_id localization, event badge colors, changes HTML). |
| [tests/e2e/18-reports.spec.js](../../tests/e2e/18-reports.spec.js) | 4 cases: KPI cards load, Monthly Close PDF download (filename match), AR Aging detail loads, bucket-card click navigates to drilldown. |

## 2. Spec map

| Source | Verbatim | Verified |
|---|---|---|
| DEMO.md §10 | "Every create / update / delete on leases, invoices, payments, tenants is tracked — who, when, what changed." | ✅ ActivityLog page + LogsActivity traits across the 5 models |
| FEATURES.md | "Reports module — `/admin/reports` with downloadable Monthly Close PDF + AR Aging drilldown." | ✅ |
| FEATURES.md | "AR Aging: Receivables bucketed by days past due — green is current, gold is 1-30, orange 31-60, red after that." | ✅ — same colors as ArAging widget (Module 01) |
| DEMO.md numbers | "Invoices: ~200 total · ~10 overdue" | Seed (post-D-2 determinism) → 197 invoices · 11 overdue · close to spec |

## 3. Findings

### 🟡 F-68. Reports / AR Aging pages have no per-user permission gate

[Reports.php:48-51](../../app/Filament/Admin/Pages/Reports.php#L48) and ArAging gate only on `Modules::enabled('reports')`. Anyone who can access /admin (any non-owner role) sees the financial reports. The Spatie permission system has `reports.view` and `reports.download` defined — but no resource consults them.

Compare to Settings page which DOES gate by `Auth::user()?->can('settings.view')`.

Realistically: should `maintenance_manager` see AR aging numbers? Should `viewer` see Monthly Close PDF? Probably yes — viewers and managers commonly review reports. But the omission is unintentional, not a deliberate design choice.

**Fix scope (deferred D-53):** add `canAccess() = Modules::enabled('reports') && Auth::user()?->can('reports.view')` on Reports + ArAging. Decide whether to grant `reports.view` to `maintenance_manager` (probably yes for AR Aging if they need to chase delinquent F&B tenants).

### 🟡 F-69. Exporters all run synchronously

All 5 Exporters use `getJobConnection() = 'sync'`. For Haya Walk's 197 invoices that's instant; for an enterprise customer with 10,000 invoices, the request times out.

**Fix scope (deferred D-54):** flip each Exporter's connection to `'database'` (the queue) once the queue worker is running in production. Filament's standard Export action handles the async flow automatically (shows a "Your export will be ready in X minutes" notification, emails when complete). ~5 LOC per exporter + queue config.

### 🟡 F-70. No query caching on ReportService

Every `/reports` page load runs fresh DB queries (`monthlyClose` does 5-6 aggregation queries). For a 10K-invoice operator this is real latency. Even in-request memoization within a single page render would help — but `ReportService` isn't a singleton.

**Fix scope (deferred D-55):** add `RememberCacheable` or a manual `Cache::remember()` with a short TTL (15-30 min) keyed on `period + asset_id`. Cache invalidation on Invoice/Payment save. Low priority for the current data scale.

### 🟢 Activity log filter + render coverage is solid

Period presets all tested under `Carbon::setTestNow()`. Changes are rendered as an HTML diff (per `ActivityLogChangeRenderer`). Causer name correctly resolves whether the actor is a User or a Tenant (polymorphic).

### 🟢 PDF output is bilingual via mPDF

Same `xbriyaz` (AR) / `dejavusans` (LTR) font setup as Invoice + Tenant Statement PDFs.

### 🟢 Settings page properly permission-gated

`canAccess() = settings.view`, save handler enforces `settings.manage`. Tabs are well-organized; each settings class has a clear responsibility. F-36 (MaintenanceSettings vs. config) doesn't affect this page's correctness, just the downstream service.

## 4. Test sweep

| Filter | Result | Time |
|---|---|---|
| `php artisan test --parallel --filter='Report|ActivityLog'` | **31 passed / 0 failed** | 1.57 s |
| `npx playwright test tests/e2e/18-reports.spec.js` | **4 passed / 0 failed** | 7.7 s |
| Full Pest (no code change in this module) | 295/295 | — |

## 5. No inline fixes this module

All 3 Yellow findings are scope/policy decisions. F-68 in particular needs an explicit "who sees what" call — bundling with D-52 (User audit log) and D-53 (Reports perm gate) would let one commit close both pre-pilot.

## 6. Deferred decisions

| # | Decision | Default |
|---|---|---|
| D-53 | F-68: gate Reports + ArAging on `reports.view` | Apply — small one-liner per page; grant `reports.view` to viewer/manager/leasing_manager/maintenance_manager |
| D-54 | F-69: flip Exporters to queued | Apply once queue worker is provisioned (production checklist item) |
| D-55 | F-70: cache ReportService results | Defer until first scale event |

## 7. Verdict

**🟡 Yellow.** Reports + ActivityLog form a clean read-only analytics layer with strong test coverage and bilingual PDF output. The 3 Yellow findings are production-readiness items (permission gating, async exports, query caching), not defects in what's shipped.

Module ratings: 00 🟢 · 01 🟡 · 02 🟢 · 03 🟡 · 04 🟡 · 05 🟡 · 06 🟢 · 07 🟢 · 08 🟡 · 09 🟡 · 10 🟢 · 11 🟢 · 12 🟡 · 13 🟡 · 14 🟡 · 15 🟡 · 16 🟢 · 17 🟡 · 18 🟡.

## Next

Module 19 — Mobile API. Surface: [Http/Controllers/Api/V1/](../../app/Http/Controllers/Api/V1/), [Http/Resources/Api/V1/](../../app/Http/Resources/Api/V1/), [Http/Requests/Api/V1/](../../app/Http/Requests/Api/V1/), [routes/api.php](../../routes/api.php), Sanctum `tenant-api` guard, [Actions/Api/Auth/](../../app/Actions/Api/Auth/), and the missing-endpoint design questions deferred from Module 02 F-10.
