# Atriom — Features & Roadmap

Status snapshot of the build. See bottom of this file for what's still ahead, and the Demo Day section just below for the live-demo playbook.

---

## Demo Day playbook

### 10-minute flow

| # | Screen | Click | Say |
|---|---|---|---|
| 1 | Dashboard | (already there) | "Monday morning — your whole mall at a glance." Point at the widget grid, draw the eye to red Expiring Leases rows and the AR-aging bars. |
| 2 | Occupancy Map | Sidebar → Occupancy Map | "Every unit on every floor, colored by status. Click any tile to jump to the unit." |
| 3 | Tenant Directory | Sidebar → Tenant Directory | "The same data as a list — every unit, every lease, every status." |
| 4 | One unit | Click Café Crema's A-01 | "Full picture — lease, history, payment status." |
| 5 | New Lease | Leases header → Quick New Lease | "Sign a new tenant in under 2 minutes." Walk through the 2-step wizard. |
| 6 | Invoices | Sidebar → Invoices | "Last month's billing — 192 invoices, EGP 340k AR." |
| 7 | Run Billing | Header → Run Monthly Billing → Confirm | "And next month — one click." |
| 8 | PDF download | PDF on any invoice row | "Tax-invoice PDF in EGP, Arabic-aware." |
| 9 | Tenant Portal | Switch to phone, /portal | "Same data, tenant-side. They see their balance and pay." |
| 10 | Arabic toggle | Click عربي in topbar | "And it works in Arabic." (30 sec) |
| 11 | Back to dashboard | Sidebar → Dashboard | Close strong. |

### Pre-flight checklist

- [ ] Charge laptop fully + bring charger
- [ ] Mobile hotspot tested
- [ ] Phone has `/portal` loaded and logged in (so step 9 doesn't need to type passwords on stage)
- [ ] Browser at clean state — no devtools open, no random tabs
- [ ] One private window per role pre-logged-in (so role-switching is one alt-tab away if asked)
- [ ] [FEATURES.md](FEATURES.md) printed or open in another tab — when they ask "can it do X", you can answer from a real list rather than improvising
- [ ] Record the 5-min screencast as fallback if wifi dies on stage
- [ ] Demo accounts seed has run (`admin@mall.test`, `manager@mall.test`, `viewer@mall.test`, `tenant1/2/3@haya.test`, all `password`)

### Talking points for the 2 known stubs

When the client clicks **Pay Now** or **Send WhatsApp** and gets a notification rather than real action:

- **Pay Now** — "That's wired to Paymob — sandbox creds enable card / InstaPay / wallet. Drop in your test merchant ID and we go live."
- **Send WhatsApp** — "WhatsApp Business API integration is ready — once you've got Meta approval or a BSP like Wati, we wire it up. Today it generates the message, just doesn't deliver."

Alternative: hide both buttons before the demo by setting `PAYMOB_ENABLED=false` and `WHATSAPP_ENABLED=false` in `.env` (default — so they're hidden out of the box). Set to `true` once you have credentials.

---

## Stack

- Laravel 13.8 · PHP 8.4 · Filament 4
- MySQL via DBngin · Herd-served at `http://mall-management.test`
- Spatie Permission (RBAC) + Spatie ActivityLog (audit trail) + Spatie MediaLibrary (document attachments)
- **mpdf/mpdf** for PDF generation — handles Arabic shaping + bidi natively
- Queue connection: `database`
- E2E tests: Playwright (chromium) — config at [playwright.config.js](playwright.config.js), tests in [tests/e2e/](tests/e2e/)

## Logins

| URL | Email | Password | Role |
|---|---|---|---|
| `/admin` | `admin@mall.test`   | `password` | `super_admin` |
| `/admin` | `manager@mall.test` | `password` | `manager`     |
| `/admin` | `viewer@mall.test`  | `password` | `viewer`      |
| `/owner` | `owner@jawad.test`  | `password` | `owner` (owns Haya Walk) |
| `/portal` | `tenant1@haya.test` | `password` | Café Crema (tenant) |
| `/portal` | `tenant2@haya.test` | `password` | Optix Eyewear (tenant) |
| `/portal` | `tenant3@haya.test` | `password` | The Burger Joint (tenant) |

All accounts persist through `migrate:fresh --seed`.

---

## Built ✓

### Data model (22 entities)
- **Core property graph:** `Operator` · `Asset` · `Unit` · `Tenant` · `Lease` · `Charge` · `Invoice` · `InvoiceItem` · `Payment`
- **AR adjustments:** `CreditNote` · `CreditNoteItem` (issued against an invoice or as standalone tenant credit; applied via service idempotently)
- **Maintenance:** `MaintenanceRequest` · `MaintenanceRequestComment`
- **Vendor management:** `Vendor` · `VendorContact` · `VendorContract` (FK from `maintenance_requests.assigned_to_vendor_id` for external assignment)
- **Mall-specific (vs PropEzy):** `TenantSalesDeclaration` · `CamExpensePool` · `CamAllocation`
- **Energy:** `UtilityMeter` · `MeterReading`
- **Communications:** `Note` (polymorphic noteable)
- All business entities with soft deletes (where applicable), MySQL enum status fields, FK constraints.
- Tenant model extends `Authenticatable` for portal login + implements `FilamentUser`.
- Lease has self-referential `previous_lease_id` for renewal chain.
- Invoice ↔ Payment is many-to-many via a pivot with `allocated_amount` (allocation tracking, not single-FK).
- Lease, Tenant, and MaintenanceRequest implement `HasMedia` (Spatie MediaLibrary) for contract / ID / photo attachments.
- LogsActivity trait on Lease, Tenant, Invoice, Payment, Charge, MaintenanceRequest, MaintenanceRequestComment, TenantSalesDeclaration, CamExpensePool.
- MaintenanceRequestComment uses a polymorphic `author` (User or Tenant) + `is_internal` flag to hide admin-only notes from tenants.
- TenantSalesDeclaration uses a polymorphic `declared_by` (User or Tenant) so admins can submit on a tenant's behalf for paper declarations.
- Asset has a `belongsToMany` to User via `asset_owner` pivot (`ownership_percentage`, `started_at`, `ended_at`) — drives the Owner Portal.

### Seed data ([HayaWalkSeeder](database/seeders/HayaWalkSeeder.php))
- Haya Walk (Jawad Developments) — 50 units across 3 zones (A/B/C), 33 leased + 17 vacant.
- Two seeded operators — Jawad Developments (gold #C9A961) + **Eltizam Egypt** (real Eltizam Group logo at [public/images/eltizam-logo.png](public/images/eltizam-logo.png) + brand-accurate gold #F0B010 sampled from the logo). White-label demo lands with Eltizam's actual identity.
- Historical invoices generated from each lease's commencement → today, with realistic paid/partial/overdue mix.
- Matching `Payment` rows for the paid portion.
- ~5 maintenance requests across statuses (submitted/in_progress/awaiting_tenant/resolved/closed) and priorities so the triage queue, dashboard widget, and SLA-breach flagging all have realistic data on first load.
- **72 tenant sales declarations** across 3 historic months × 24 F&B/retail leases with mixed statuses (43 locked → 43 percentage-rent charges auto-generated; some submitted-awaiting-review; some disputed).
- **2 CAM expense pools** — last year reconciled with 33 allocations all billed; current year draft awaiting Generate-Allocations click.
- **~65 ETA submissions** on recent invoices (55 Valid + 10 Rejected) via the same mock pipeline the admin clicks.
- **48 utility meters** (3 common-area + per-unit electric/water across 20 occupied units + gas on F&B) with 576 monthly readings (12 months × 48 meters).
- 4 demo admin/owner users + 3 portal-login tenants (see Logins).
- First 4 leases expire within the next 90 days (15/35/65/80) — the ExpiringLeases widget always exercises all 3 color tiers.

### Admin panel (Filament)

**Dashboard widgets** ([app/Filament/Admin/Widgets/](app/Filament/Admin/Widgets/))
- `ActionRequired` — high-priority counters (overdue invoices, expiring leases) needing attention.
- `MallStats` — Occupancy · Monthly Revenue · Collected This Month · Outstanding AR (4 stat cards with descriptions and deltas).
- `ArAging` — 5-bucket bar chart (current / 1-30 / 31-60 / 61-90 / 90+).
- `TenantMix` — active leases grouped by unit category.
- `MonthlyRevenueTrend` — full-width grouped bar chart, last 12 months, Billed vs Collected.
- `ExpiringLeases` — table of active leases expiring in next 90 days, color-coded urgency.
- `TopTenants` — table of highest-rent active leases.
- `RecentPayments` — latest payments captured.
- `OpenMaintenanceRequests` — open request count grouped by priority, with SLA-breach flag.
- `EnergyConsumptionTrend` — full-width 12-month stacked bar chart, 3 series (electric / water / gas) with type-keyed palette.

**Top-level pages** ([app/Filament/Admin/Pages/](app/Filament/Admin/Pages/))
- `OccupancyMap` (Operations) — floor-grouped color-coded unit grid for any property. Asset selector when there's more than one. Each tile links to the unit edit page.
- `ActivityLog` (Reports) — system-wide audit timeline with subject/event filters.

**Resources** ([app/Filament/Admin/Resources/](app/Filament/Admin/Resources/))
- Properties (Asset) · Tenant Directory (Unit) · Tenants · Leases · Maintenance Requests · **Tenant Sales** · **CAM Reconciliation** · **Energy & Utilities** — under Operations.
- Invoices · Payments — under Billing.
- Users — under Settings (super_admin only). Full CRUD + multi-role assignment.

**Relation managers** (data graph navigation) — under [app/Filament/Admin/RelationManagers/](app/Filament/Admin/RelationManagers/)
- Lease → Invoices · Activity Log
- Tenant → Leases · Payments · Maintenance · Activity Log
- Invoice → Activity Log
- Payment → Activity Log
- MaintenanceRequest → Comments (admin can toggle `is_internal` per comment)
- CamExpensePool → Allocations (per-row Bill action that creates a one-off `percentage_rent`/`other` Charge)

**CSV export** (Filament native exporters) — bulk + header actions
- Tenants · Units · Leases · Invoices · Payments

**Actions implemented**
- **Run Monthly Billing** ([InvoicesTable](app/Filament/Admin/Resources/Invoices/Tables/InvoicesTable.php) header) — iterates active leases, generates Invoice+InvoiceItems from each lease's Charges with EG VAT rules (rent exempt, service 14%). Idempotent per `(lease_id, period_start)`. Backed by [MonthlyBillingService](app/Services/MonthlyBillingService.php) + [RunMonthlyBilling](app/Jobs/RunMonthlyBilling.php) job + `billing:run-monthly` CLI command.
- **Generate single invoice** (per-lease) — header action on the Lease edit page to bill one specific lease for an arbitrary period without running the mall-wide job.
- **Quick New Lease wizard** ([LeasesTable](app/Filament/Admin/Resources/Leases/Tables/LeasesTable.php) header) — 2-step (Tenant → Lease), filters to vacant units, picks existing or creates new tenant, auto-seeds charges, flips unit to occupied. Backed by [LeaseCreationService](app/Services/LeaseCreationService.php).
- **Renew Lease** (per-row action) — creates a new linked lease, copies charges with new amounts, flips old lease to `renewed`. Backed by [LeaseRenewalService](app/Services/LeaseRenewalService.php).
- **Terminate Lease** (per-row action) — marks lease `terminated`, frees unit, deactivates charges, optionally cancels open invoices. Backed by [LeaseTerminationService](app/Services/LeaseTerminationService.php).
- **Download PDF** on every invoice (admin + portal). [InvoicePdfService](app/Services/InvoicePdfService.php) + [invoices/pdf.blade.php](resources/views/invoices/pdf.blade.php). mPDF backend with full Arabic shaping + bidi; the same template renders correctly in both EN and AR with conditional RTL borders/alignments and direction-aware totals.
- **Statement of Account** on every tenant (admin + portal). [TenantStatementPdfService](app/Services/TenantStatementPdfService.php) + [tenants/statement.blade.php](resources/views/tenants/statement.blade.php).
- **Setup/Reset Portal Access** on Tenant edit page — auto-generates a password and shows it in a persistent notification.
- **Send WhatsApp** — STUB on Invoices (flashes a notification). Gated by `WHATSAPP_ENABLED` so it's invisible by default. Needs WhatsApp Business API creds.
- **Submit to ETA** (per-invoice + the bulk-action path is wired but not yet exposed in the toolbar) — runs the full ETA submission pipeline (JSON build → sign → POST → response persist). Gated by `ETA_ENABLED` (default on); falls back to mock client when `ETA_MOCK=true` (default). See "ETA e-Invoicing" subsection below.
- **Lock Sales Declaration** + **Dispute Sales Declaration** — admin review queue actions on the Tenant Sales resource. Lock auto-generates a `percentage_rent` Charge on the lease for next-month billing. See "Mall-specific workflows" below.
- **Generate Allocations** + **Bill Allocation** + **Mark Reconciled** — CAM Reconciliation lifecycle actions on the CamExpensePool resource + relation manager. See "Mall-specific workflows" below.
- **Bulk Download PDFs** (zip) — Invoices toolbar action: select rows, get a single zip of all selected invoice PDFs. Available on admin + Owner Portal.
- **Bulk Submit to ETA** — Invoices toolbar action: pushes all selected invoices through the ETA submission pipeline. Gated by `eta.enabled`. Reports submitted vs skipped (already Valid).
- **Log Communication** — header action on the Tenant → Communications relation manager. Records phone calls, WhatsApp threads, meetings, site visits, emails with channel + subject + body + contacted_at.

### Tenant portal (Filament)
- `AccountBalance` dashboard widget (outstanding / overdue / open invoice count).
- `OpenMaintenance` dashboard widget (open / awaiting-tenant counts + link).
- Read-only Invoices + Payments resources scoped to `auth('portal')->id()`.
- Maintenance Requests resource — list own requests, submit a new one (title / category / priority / description / photo uploads), view status timeline + public comment thread, add a comment, cancel while still `submitted`/`acknowledged`. Backed by [PortalMaintenanceCommentsRelationManager](app/Filament/Portal/RelationManagers/PortalMaintenanceCommentsRelationManager.php) — internal admin notes are hidden.
- Download PDF on each invoice.
- Statement of Account (header action on /portal/invoices).
- **Pay Now** — STUB on portal invoice rows + view page (flashes a notification). Gated by `PAYMOB_ENABLED`. Needs Paymob sandbox.

### Maintenance & facilities
- `MaintenanceRequest` model — statuses (`submitted` → `acknowledged` → `in_progress` → `resolved` → `closed`, plus `awaiting_tenant` and `cancelled`), priorities (`low`/`medium`/`high`/`urgent`), categories (`electrical`/`plumbing`/`hvac`/`structural`/`cleaning`/`safety`/`other`), assignment to a `User`, `target_resolution_at` SLA stamp computed on create, resolution notes, soft deletes.
- `MaintenanceRequestComment` model — polymorphic author (User or Tenant), `is_internal` flag so admins can keep private notes off the tenant view.
- SLA targets live in [`config/maintenance.php`](config/maintenance.php) (`urgent` 24h, `high` 72h, `medium` 7d, `low` 14d) + `auto_close_after_days` window — tunable per deployment without a migration.
- Admin resource (Operations nav) — triage list with status / priority / category / assignee / SLA-breach filters, status-transition actions, comment thread, attachment uploads via Spatie MediaLibrary.
- Tenant portal resource (see above) — self-service submission + status visibility.
- Dashboard widgets: `OpenMaintenanceRequests` (admin), `OpenMaintenance` (portal).
- Audit trail via Spatie ActivityLog on both `MaintenanceRequest` and `MaintenanceRequestComment`; surfaces in the global Activity Log page.
- Reference numbers: `MR-{AssetCode}-{Year}-{Seq}`.
- Backed by [MaintenanceRequestService](app/Services/MaintenanceRequestService.php).

### Multi-property tenancy (session-based operator switcher)
- `Operator` model with branding fields: `name`, `slug`, `logo_path`, `favicon_path`, `primary_color`, `contact_email`, `metadata`, soft deletes.
- `operator_id` FK on `assets` (Unit → Asset → Operator chain provides scoping for everything downstream).
- [`CurrentOperator`](app/Support/CurrentOperator.php) session helper (`id()` / `get()` / `set()` / `clear()` / `isAll()`).
- [`CurrentOperatorScope`](app/Models/Scopes/CurrentOperatorScope.php) — auto-applied to `Asset` via `#[ScopedBy]`; resolves to `WHERE operator_id = X` when an operator is selected, no-op otherwise.
- **Operator switcher in admin topbar** — dropdown with colored dot indicator, "All Operators" reset, only renders when 2+ operators exist. Registered as a render hook (admin panel only, not portal).
- **Dynamic brand swap** — [`AdminPanelProvider`](app/Providers/Filament/AdminPanelProvider.php) `brandName` / `brandLogo` / `favicon` all resolve per-request from current operator via closures.
- Route: `GET /operator/switch/{operator?}` (web + auth middleware) — sets session, redirects back with `/admin` fallback.
- Seeded with **Jawad Developments** + **Eltizam Egypt** (the latter wired to the real Eltizam Group logo + brand gold `#F0B010` sampled from the public mark — the white-label demo lands on Eltizam's actual identity).
- Note: Filament 4's `->colors()` is evaluated once at panel boot, so the primary accent color stays static (gold). Brand logo / name / favicon are fully dynamic.

### Mall-specific workflows (the moat vs PropEzy)

**Tenant Sales Declaration → Percentage Rent**
- `TenantSalesDeclaration` model — `lease_id`, `period_start`/`period_end`, `declared_sales`, `calculated_percentage_rent`, polymorphic `declared_by` (User or Tenant), status enum (`submitted` / `locked` / `disputed`), `locked_by_user_id`, audit notes, soft deletes.
- `percentage_rent_calculation_type` enum added to `leases` table (`natural_breakpoint` / `artificial`) — completes the existing inline percentage-rent terms (`has_percentage_rent`, `percentage_rent_threshold`, `percentage_rent_rate`).
- [`PercentageRentCalculationService`](app/Services/PercentageRentCalculationService.php) — supports both formulas:
  - **Artificial:** `max(0, (sales - threshold) × rate%)`
  - **Natural breakpoint:** `max(0, sales × rate% - base_rent_monthly)`
- **Admin review queue** (Operations nav) — filter by status / period, per-row **Lock** + **Dispute** actions. Lock auto-creates a one-off `percentage_rent` Charge on the lease for the next monthly billing run.
- **Tenant portal submission form** — gated to leases with `has_percentage_rent = true`; minimal numeric form, last-month default period, status visibility after submission.
- Nav badge on admin shows pending submission count.

**CAM (Common Area Maintenance) Reconciliation**
- `CamExpensePool` model — one per (asset, period_year) with `total_actual_expense`, `total_estimated_collected`, status (`draft` → `reconciling` → `reconciled` → `closed`), reconciled timestamp + user.
- `CamAllocation` model — one per (pool, lease) with `pro_rata_share_pct`, `allocated_amount`, `estimated_paid`, **`true_up_amount`** (positive = tenant owes more, negative = credit due), optional `cap_amount`, `exclusions` JSON, status (`pending` / `billed` / `disputed` / `closed`), `billed_charge_id` link.
- [`CamReconciliationService`](app/Services/CamReconciliationService.php) — `generateAllocations(pool)` distributes pro-rata by leased sqm across active leases (idempotent); `bill(allocation)` creates a one-off Charge type `other` ("CAM Reconciliation — {year}") on the lease.
- Admin resource + per-pool allocations relation manager with **Generate Allocations**, **Mark Reconciled**, per-row **Bill** actions.
- Variance column on the pool list highlights under-collection (warning) / over-collection (success).
- v1 intentionally shallow: admin manually clicks Generate + Bill; annual auto-true-up scheduled job is Q2.

### ETA e-Invoicing (mock-by-default, swap to live when creds land)
- `eta_status` enum column on `invoices` (`pending` / `submitted` / `valid` / `invalid` / `rejected` / `cancelled`) + `eta_long_id` UUID column. Existing `eta_submission_id` / `eta_submitted_at` / `eta_response` (JSON) columns wire up to the full pipeline.
- [`EtaJsonBuilder`](app/Services/Eta/EtaJsonBuilder.php) — converts Invoice + InvoiceItems to ETA document spec v1.0 (issuer + receiver + invoice lines + tax breakdown via T1/V009 codes + totals; per-charge `itemCode` mapping).
- [`EtaApiClient`](app/Services/Eta/EtaApiClient.php) — **mock mode** (default) returns deterministic Valid response; **real mode** does client-credentials bearer-token auth + POST to ETA preprod endpoint.
- [`EtaSubmissionService`](app/Services/Eta/EtaSubmissionService.php) — idempotent orchestrator. Persists submission_id / long_id / status / full response. Marks `rejected` on transport failure.
- [`SubmitInvoiceToEta`](app/Jobs/SubmitInvoiceToEta.php) — queued job wrapper for async bulk submission.
- **Admin UI** — ETA status badge column on Invoices table (Valid green / Submitted info / Rejected/Invalid red / Cancelled gray) + per-row **Submit to ETA** action. Modal copy adapts to mock vs live mode.
- Config: [`config/eta.php`](config/eta.php) — `ETA_ENABLED` (default `true`), `ETA_MOCK` (default `true`), preprod endpoint URL, client_id/secret env vars, issuer identity (TRN, name, address). Flip `ETA_MOCK=false` once credentials are wired.

### Owner Portal (3rd Filament panel, read-only)
- New panel at `/owner` with own login, dynamic brand resolved from the owner's owned-asset operators.
- `asset_owner` M2M pivot (`user_id`, `asset_id`, `ownership_percentage`, `started_at`, `ended_at`) — drives `User::ownedAssets()` and `Asset::owners()`.
- New `owner` role added to [`RolesPermissionsSeeder`](database/seeders/RolesPermissionsSeeder.php).
- [`User::canAccessPanel()`](app/Models/User.php) gates: owner panel → `owner` role only, admin panel → `super_admin` / `manager` / `viewer` roles only.
- **`PortfolioStats` widget** — 4 stat cards: Properties count + leasable area, portfolio occupancy %, MRR, outstanding AR — aggregated across owned assets, bypasses `CurrentOperatorScope`.
- **Read-only resources:**
  - Properties — list of owned assets with per-asset KPIs (occupancy badge, units count, leasable area); view page shows performance breakdown
  - Invoices — scoped via lease → unit → asset → owner; with ETA status badge
  - Maintenance Requests — monitoring view across the portfolio
- Seeded owner login: `owner@jawad.test / password` with 100% ownership of Haya Walk.

### Credit Notes & Refunds (AR completeness)
- `CreditNote` + `CreditNoteItem` models with auto-generated `CN-{asset}-{YYYYMM}-{seq}` numbers, soft deletes, LogsActivity.
- **Lifecycle**: `draft` → `issued` → `applied` (when applied_amount = total) → `void`. Reason enum: return · dispute · adjustment · discount · refund · other.
- [`CreditNoteService`](app/Services/CreditNoteService.php) covers the three real operations: `issue()`, `applyToInvoice(invoice, ?amount)` (caps at min of note balance + invoice balance + requested amount; updates both sides atomically and flips invoice status to partially_paid/paid; idempotent on void), `void(reason)` (refuses if already applied — caller must issue an offsetting note instead).
- Filament 4 resource at `/admin/credit-notes` — issue/apply/void as record-level header actions with confirmation modals, full repeater for line items with live VAT recalc, applies the credit's tenant filter when picking an invoice (so you can't apply Tenant A's credit to Tenant B's invoice).
- 6 PHPUnit tests in [`tests/Feature/CreditNoteServiceTest.php`](tests/Feature/CreditNoteServiceTest.php) locking the math: issue, apply, cap-at-minimum, void-when-applied throws, fully-applied status flip, no-op on voided notes.

### Vendor Management (parity with PropEzy / Yardi)
- `Vendor` + `VendorContact` + `VendorContract` models. Vendor types: contractor · supplier · service_provider · consultant · other. Status: active · inactive · blacklisted.
- Filament resource at `/admin/vendors` (Operations nav) — type/status badges + filters, active-contracts count column, deep search across name/legal_name/tax_id/email/phone.
- Two relation managers on the vendor edit page: **Contacts** (`is_primary` toggle, default-sort primary first) and **Contracts** (asset linkage, value, currency, scope, status enum).
- Wired into [`MaintenanceRequest`](app/Models/MaintenanceRequest.php) via `assigned_to_vendor_id` FK + `assignedVendor()` relation. The maintenance form now has both internal `assigned_to` (User) and external `assigned_to_vendor_id` (Vendor) so requests can route to staff, vendors, or both. Maintenance table gains an External Vendor column (toggleable).
- Seeded 8 realistic Egyptian-mall vendors (Cool-Air HVAC, BrightSpark Electrical, PureWater Plumbing, CleanFleet Janitorial, SecureGuard Security, GreenLeaf Landscaping, PestStop, FireSafe Consultants) each with a primary contact + most with an annual service contract against Haya Walk.

### Tenant Communications log
- `Note` model with polymorphic `noteable` (Tenant today; extensible to Lease/Invoice) + `author` (User) + channel enum (`call` / `whatsapp` / `email` / `meeting` / `site_visit` / `other`) + subject + body + `contacted_at`.
- Admin **Communications** relation manager on the Tenant edit page — collections-style timeline view, "Log Communication" header action, channel-colored badges.
- Seeded with 15 demo notes across the portal-login tenants so the timeline isn't empty on first login.
- LogsActivity wired so note edits surface in the global Activity Log.

### Branded surfaces
- **Landing page** (`/`) — branded intro with three CTAs into the panels (Admin / Owner / Portal). Replaces the default Laravel welcome. See [resources/views/welcome.blade.php](resources/views/welcome.blade.php).
- **Custom error pages** — 404 / 403 / 500 each render with the Atriom palette (deep teal + amber accent), localizable via [lang/en/errors.php](lang/en/errors.php) + [lang/ar/errors.php](lang/ar/errors.php).
- **Helper text on financial forms** — Lease form now explains `escalation_rate`, `escalation_type`, `percentage_rent_calculation_type` (natural breakpoint vs artificial), threshold/rate semantics with typical Egyptian-mall rates. CAM form explains actual vs estimated. All min/max validation rules added.

### Energy & Utilities (intentionally shallow — Q3 work continues here)
- `UtilityMeter` model — `asset_id`, optional `unit_id` (null = common-area), `meter_number` (unique), type enum (`electric` / `water` / `gas`), `provider`, status (`active` / `inactive` / `faulty`), `unit_of_measurement`.
- `MeterReading` model — `utility_meter_id`, `reading_date` (unique per meter+date), `reading_value`, `consumption`, `cost`.
- Admin resource (Operations nav) — type badges, status filters, readings count.
- `EnergyConsumptionTrend` dashboard widget — full-width 12-month bar chart, 3 series stacked by meter type, type-colored palette.
- v1 deliberately monitoring-only. Optimization workflows (anomaly detection, peak-demand alerts, IoT integration) are Q3.

### RBAC (Spatie Permission)
- 4 roles: `super_admin`, `manager`, `viewer`, `owner`. Seeded via [RolesPermissionsSeeder](database/seeders/RolesPermissionsSeeder.php).
- [RoleGatedActions](app/Filament/Admin/Resources/Concerns/RoleGatedActions.php) trait applied to admin resources — controls `canCreate`/`canEdit`/`canDelete`.
- Panel-level gating in `User::canAccessPanel()` — admin panel requires admin-side role; owner panel requires `owner` role.
- UserResource (Settings nav) restricted to `super_admin`.

### Audit trail (Spatie ActivityLog)
- `LogsActivity` trait on Lease, Invoice, Payment, Tenant, Charge, MaintenanceRequest, MaintenanceRequestComment, TenantSalesDeclaration, CamExpensePool.
- Tracks only whitelisted fields, dirty-only, no empty changes.
- Global Activity Log page (Reports nav).
- Per-record Activity tab (relation manager) on Lease, Invoice, Tenant, Payment.

### Document attachments (Spatie MediaLibrary)
- `Lease`, `Tenant`, and `MaintenanceRequest` implement `HasMedia`. Lease/Tenant expose a `documents` collection; MaintenanceRequest exposes `attachments` for photos / short videos.
- `SpatieMediaLibraryFileUpload` form field on the relevant edit/create forms — drag-drop contract scans, IDs, registration docs, fault photos.
- Storage handled by Spatie defaults (filesystem of choice).

### Branding & i18n
- **Atriom brand identity** — Monochrome-first design with teal + amber as **accent only**. Filament panel chrome uses `Color::Zinc` for primary (clean black/grey buttons + focus rings in both modes).
- **Three logo SVGs** — [`atriom-logo.svg`](public/images/atriom-logo.svg) auto-adapts via `prefers-color-scheme` (ink wordmark + teal mark in light; cream wordmark + bright teal in dark); plus dedicated [`atriom-logo-light.svg`](public/images/atriom-logo-light.svg) and [`atriom-logo-dark.svg`](public/images/atriom-logo-dark.svg) for contexts that need explicit mode selection.
- **Auto-adapt favicon** — [`atriom-favicon.svg`](public/atriom-favicon.svg) — teal square in light mode, ink square in dark mode.
- **Atriom palette** — Light mode: bg `#FFFFFF` · surface `#FAFAFA` · ink `#18181B` · muted `#52525B`. Dark mode: bg `#09090B` · surface `#18181B` · ink `#FAFAFA` · muted `#A1A1AA`. Brand accents — teal `#0F766E` (light) / `#14B8A6` (dark), amber `#D97706` (light) / `#F59E0B` (dark). Defined in [resources/css/filament/admin/theme.css](resources/css/filament/admin/theme.css) with full `:root` + `.dark` parity.
- **Full light + dark mode parity** — Filament's `darkMode(true)` enabled on all three panels (admin, owner, portal). Landing page, all error pages (404/403/500), operator switcher, language switcher all respond to `.dark` class on `<html>` or `prefers-color-scheme`. PDF templates stay light-mode (for printing).
- **Customer operators bring their own brand** — Jawad Developments (gold `#C9A961` + their real logo), Eltizam Egypt (gold `#F0B010` + their real public mark). Per-operator dynamic brand swap on admin + owner panels (logo, name, favicon read from current operator); platform Atriom branding is the fallback.
- **EN ↔ AR language switch** — segmented pill on every page (topbar + login). Full RTL flip via Filament's built-in `dir` attribute.
- Translation files: [lang/en/admin.php](lang/en/admin.php) + [lang/ar/admin.php](lang/ar/admin.php) — ~800+ lines each, covering nav/groups/resources/widgets/tables/filters/actions/fields/sections/statuses/enums (incl. operators, tenant_sales, cam_pool, cam_allocation, eta, meter_type, meter status) / pdf/statement/activity/users/tenants/occupancy/maintenance/energy/portfolio.
- DD/MM/YYYY date format everywhere; locale-aware month names via Carbon's `isoFormat('MMM YYYY')`.
- EGP currency consistent across every `->money()` call.
- Arabic PDF rendering uses mPDF's `autoArabic` + `autoLangToFont` so letters connect correctly; `xbriyaz` font for Arabic, `dejavusans` for Latin, with conditional zeroing of `letter-spacing` / `text-transform` per locale.

### Automated tests
- **PHPUnit** ([tests/Feature/BillingMathTest.php](tests/Feature/BillingMathTest.php), [tests/Feature/EtaJsonBuilderTest.php](tests/Feature/EtaJsonBuilderTest.php), [tests/Feature/CreditNoteServiceTest.php](tests/Feature/CreditNoteServiceTest.php)) — locks the AR + billing math: percentage rent (artificial + natural breakpoint + below-threshold), monthly billing idempotency, late-fee application + grace-period respect + once-per-invoice idempotency, CAM allocation distribution by sqm, CAM billing idempotency, ETA JSON shape + tenant-type mapping + EGS line codes + V009 VAT sub-type, credit-note issue + apply + cap-at-minimum + void-when-applied refusal + status flip. 16 service tests + 59 assertions. SQLite in-memory, runs in <500ms. `php artisan test`.
- **Playwright E2E** ([tests/e2e/](tests/e2e/)) — 16 spec files covering auth, every admin page, CRUD navigation, portal flows, PDF downloads in EN + AR, locale switching, occupancy map, **multi-property tenancy isolation + brand swap, tenant sales admin + portal flow, CAM reconciliation page + seeded pools, ETA invoice index + Valid badges, Owner Portal dashboard + scoped resources + admin-panel gating, Energy meters + nav, CSV import action visibility, Credit Notes + Vendors admin pages + maintenance/vendor wiring**. Run via `npx playwright test`. HTML report lands in [storage/playwright-report/](storage/playwright-report/). Session caching: global setup logs into each panel once (admin + portal + owner) and writes `storage/playwright-state/{admin,portal,owner}.json`; tests opt in via `test.use({ storageState: ... })` to skip the login step.
- **GitHub Actions CI** ([.github/workflows/ci.yml](.github/workflows/ci.yml)) — runs on every push + PR to `main` / `develop`. Two jobs: (1) **PHPUnit** with sqlite in-memory in ~30s, (2) **Playwright** boots MySQL 8 service, migrates+seeds, builds assets, starts `php artisan serve` on 127.0.0.1:8000, runs the full e2e suite headless. Failed runs upload HTML report as a GH artifact.

### Scheduled jobs & automation
- **Monthly billing** — `php artisan billing:run-monthly` runs synchronously or queued. Auto-scheduled monthly via `Schedule::job(new RunMonthlyBilling)->monthlyOn(...)` in [routes/console.php](routes/console.php). Day/time configurable in [config/billing.php](config/billing.php).
- **Late-fee automation** ([app/Services/LateFeeService.php](app/Services/LateFeeService.php) + [app/Jobs/ApplyLateFees.php](app/Jobs/ApplyLateFees.php) + `billing:apply-late-fees`) — scans all `issued|partially_paid|overdue` invoices whose `due_date + grace_days` has passed; appends a `late_fee` InvoiceItem at `max(min, balance * percent)` and flips status to `overdue`. Idempotent (one fee max per invoice). Scheduled daily at 04:00.
- **CAM annual reconciliation** — `php artisan cam:reconcile [--year=YYYY] [--auto-bill]` walks every CAM pool for the year, runs `CamReconciliationService::generateAllocations()`, optionally bills each allocation. Scheduled yearly on Jan 15 at 03:00 (review-only by default).
- **Invoice issued email** — `App\Mail\InvoiceIssued` Mailable fires queued every time `MonthlyBillingService` creates an invoice (both single-lease and batch paths). Blade template at [resources/views/emails/invoice-issued.blade.php](resources/views/emails/invoice-issued.blade.php), bilingual EN/AR, RTL-aware.

### CSV import (bootstrapping)
- **Tenant / Unit / Lease importers** ([app/Filament/Imports/](app/Filament/Imports/)) — Filament `ImportAction` wired into each `List*` page header. Idempotent re-imports via `resolveRecord()`: Tenants match on email, Units on `(asset_code, code)`, Leases resolve tenant by email + unit by `(asset_code, unit_code)` and match on `reference`. Sample templates under [resources/sample-imports/](resources/sample-imports/).

---

## Blocked on external credentials

Each needs a sandbox account or sandbox-API key before live integration can start. Architecture is in place; flip the env flag once creds arrive.

- [ ] **Pay Now via Paymob** — admin/portal button is a stub today. Gated by `PAYMOB_ENABLED` in [`config/integrations.php`](config/integrations.php) (default `false`). Needs Paymob test merchant. Scope: `PaymentInitiationController`, `PaymobWebhookController` with HMAC signature verification, payment row created on init, balance recalc on webhook. Egyptian-relevant methods: card, InstaPay, wallets.
- [ ] **Send WhatsApp** — both an admin action ("send invoice reminder") and a "Pay Now" link via WhatsApp from the portal. Gated by `WHATSAPP_ENABLED` (default `false`). Needs WhatsApp Business API access (Meta) or a BSP like Wati/360dialog. Scope: outbound template messages for invoice issue + payment received + due-date reminder.
- [ ] **ETA (Egyptian Tax Authority) production submission** — **mock mode is fully wired today** (see "ETA e-Invoicing" above). Needs ETA preprod credentials + production certificate. Flip `ETA_MOCK=false` once ETA_CLIENT_ID + ETA_CLIENT_SECRET are in `.env`. Production certificate procurement is a separate 4-8 week regulatory process.
- [ ] **Email delivery in production** — currently sends nothing. Needs SMTP or Mailgun/Postmark credentials.

## Polish wins still available

Small-scope, no external dependencies. Each is a single session to deliver.

- [x] ~~Vendor management module~~ — shipped (see "Vendor Management" section above).
- [ ] **Asset → Units relation manager** — currently you can navigate down from a Lease/Tenant but not directly from a Property edit page. (Note: the Occupancy Map page covers visual browsing already, but a sortable/filterable table view would still be useful from inside the Asset record.)
- [ ] **Notes / communications log on Tenant** — admin records phone calls / WhatsApp / meetings against a tenant. Real-world collections-team feature; could double as a polymorphic `Note` model attachable to Lease/Invoice too.
- [ ] **Bulk PDF download** on Invoices — bulk action that returns a zip of selected invoice PDFs.
- [ ] **Bulk Submit to ETA** on Invoices — `SubmitInvoiceToEta` queued job already exists; just wire the bulk action.
- [ ] **Bulk WhatsApp send** on Invoices — bulk action when WhatsApp is unblocked.
- [x] ~~Credit notes / refunds~~ — shipped (see "Credit Notes & Refunds" section above).
- [x] ~~Late-fee automation~~ — shipped (see "Scheduled jobs & automation" above).
- [x] ~~Email invoice on issue~~ — shipped (`App\Mail\InvoiceIssued`, RTL-aware blade view).
- [x] ~~CSV import for bootstrapping~~ — shipped (`app/Filament/Imports/` + sample templates).
- [ ] **Statement of Account PDF on Owner Portal** — service already exists for the tenant portal; lift into owner panel for portfolio-level statements.
- [ ] **Operator switcher color-coded primary accent** — Filament 4's `->colors()` evaluates once at panel boot. To swap the primary accent dynamically would need a CSS-variable-based theme override applied per-request. Brand logo/name/favicon already swap correctly.

## Larger items / future considerations

Bigger installs or scope decisions that deserve their own session.

- [x] **Multi-property tenancy** — shipped (see "Multi-property tenancy" above).
- [x] **Maintenance / CAFM module** — shipped.
- [x] **Owner portal** — shipped.
- [ ] **CAM annual auto-true-up service** — v1 is admin-manual click. Auto-true-up at year-end (scheduled job that runs Generate Allocations + Bill across all leases) is Q2 work.
- [ ] **Maintenance module v2** — vendor management as a first-class entity (currently assignee is just a `User`), recurring/scheduled maintenance (quarterly HVAC, monthly fire-alarm test) with a `MaintenancePlan` recurrence model, chargebacks that integrate maintenance costs with Charge/Invoice based on lease landlord-vs-tenant responsibility rules, and parts/inventory tracking.
- [ ] **Mobile app for tenants** — Laravel API + React Native or Flutter. Portal data model is already API-ready. See [MOBILE-APP-BRIEF.md](MOBILE-APP-BRIEF.md) for the business briefing — the Egyptian-mall-tenant-specialist angle.
- [ ] **Energy optimization workflows** — v1 is monitoring-only (data model + consumption chart). Q3 expansion: anomaly detection, peak-demand alerts, IoT sensor integration via OrionTEK-style hooks, cost-allocation to leases via CAM.
- [ ] **Deeper analytics** — beyond the current widget set: per-category occupancy trends, churn analysis, percentage-rent contribution analysis, lease renewal funnel, anchor tenant performance.
- [ ] **Accounting export** — Excel/SAP-friendly export for the accounting team's monthly close (invoices + payments + journal entries). CSV export already exists for raw data.
- [ ] **Tenant ratings / scorecard** — track on-time payment %, lease-renewal history, contract compliance, declared sales accuracy vs POS audit.

---

## Implementation notes for whoever picks this up later

- **Filament 4 quirks**:
  - Resources live in nested folders: `Resources/<Plural>/{Resource.php, Schemas/<Resource>Form.php, Tables/<Plural>Table.php, Pages/}`.
  - Actions are in `Filament\Actions\*` (not `Filament\Tables\Actions\*`). Tables use `->recordActions()` / `->toolbarActions()` / `->headerActions()`.
  - Icons via `Filament\Support\Icons\Heroicon` enum. Note the casing: `OutlinedSquares2x2` (lowercase x), not `OutlinedSquares2X2`.
  - `TableWidget` heading comes from `getTableHeading()`, not `getHeading()`. `ChartWidget` uses `getHeading()`.
  - Schemas use `Filament\Schemas\Schema` with `->components([...])`. Top-level `->columns(1)` stacks sections vertically.
  - Nav groups via `NavigationGroup::make('key')->label(fn () => __(...))` so labels are resolved per-request (locale-aware).
  - Custom Blade in render hooks won't get Tailwind utility classes compiled (the view path isn't in Filament's content scan). Use inline styles for one-off views like the language switch and the occupancy grid.
  - Filament uses Livewire `wire:navigate` for SPA-like nav; in Playwright tests, prefer `page.goto(href)` to a click + `waitForURL` because `wire:navigate` doesn't emit the `load` event Playwright waits for by default.

- **Locale middleware** ([SetLocale.php](app/Http/Middleware/SetLocale.php)) — reads `session('locale')` and calls `app()->setLocale(...)`. Registered on the `web` group AND each Filament panel's middleware stack (panels don't inherit `web`).

- **Activity log gotcha** — Spatie 5+ stores diffs in `attribute_changes` column, not `properties`. The package's auto-`activitiesAsSubject()` morphMany is what relation managers should target.

- **Date formats** — DD/MM/YYYY chosen as Egyptian convention. Localized month names use Carbon's `isoFormat('MMM YYYY')` (NOT `format('M Y')`, which is locale-independent).

- **Egypt VAT model** — base rent is VAT-exempt; service charge is 14% VAT. Encoded on each `Charge` row via `vat_applicable` + `vat_rate`. The billing service respects these per-charge rather than hardcoding.

- **Run a queue worker** to process queued jobs (currently only `RunMonthlyBilling` if dispatched via `billing:run-monthly --queue`):
  ```
  php artisan queue:work
  ```
  All other "queued" actions in the admin run synchronously inside the request for immediate-feedback demo UX.

- **PDF rendering** uses mPDF (not DomPDF). mPDF was chosen specifically for Arabic support — DomPDF emits Arabic characters in logical order without ligature shaping, producing disconnected glyphs in reverse order. mPDF handles `autoArabic` + `autoLangToFont` + RTL bidi natively. Two non-fatal mPDF vendor warnings (`Undefined array key "BORDER-LEFT"` and `trim(): null` deprecation) are worked around in the templates by always setting both `border-left` AND `border-right` on RTL-direction tables — see [`pdf.blade.php`](resources/views/invoices/pdf.blade.php) and [`statement.blade.php`](resources/views/tenants/statement.blade.php).

- **mPDF temp directory** — both PDF services create `storage/app/mpdf` for font cache / temp work. Don't gitignore the parent `storage/app/`; the global gitignore already excludes everything except `private/` and `public/`.

- **Demo data quirk** — running `migrate:fresh --seed` resets everything including the lease expiry-soon adjustments (now baked in) and the user accounts (also baked in).

- **Playwright session caching** — global setup logs once into each panel and writes `storage/playwright-state/{admin,portal}.json`. Test files use `test.use({ storageState: ... })` to skip the login step. Speeds the suite from ~3 min to ~1 min.
