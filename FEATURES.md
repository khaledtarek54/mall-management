# Mall Management — Features & Roadmap

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
| `/portal` | `tenant1@haya.test` | `password` | Café Crema (tenant) |
| `/portal` | `tenant2@haya.test` | `password` | Optix Eyewear (tenant) |
| `/portal` | `tenant3@haya.test` | `password` | The Burger Joint (tenant) |

All accounts persist through `migrate:fresh --seed`.

---

## Built ✓

### Data model (10 entities)
- `Asset` · `Unit` · `Tenant` · `Lease` · `Charge` · `Invoice` · `InvoiceItem` · `Payment` · `MaintenanceRequest` · `MaintenanceRequestComment`
- All business entities with soft deletes, MySQL enum status fields, FK constraints.
- Tenant model extends `Authenticatable` for portal login + implements `FilamentUser`.
- Lease has self-referential `previous_lease_id` for renewal chain.
- Invoice ↔ Payment is many-to-many via a pivot with `allocated_amount` (allocation tracking, not single-FK).
- Lease, Tenant, and MaintenanceRequest implement `HasMedia` (Spatie MediaLibrary) for contract / ID / photo attachments.
- LogsActivity trait on Lease, Tenant, Invoice, Payment, Charge, MaintenanceRequest, MaintenanceRequestComment.
- MaintenanceRequestComment uses a polymorphic `author` (User or Tenant) + `is_internal` flag to hide admin-only notes from tenants.

### Seed data ([HayaWalkSeeder](database/seeders/HayaWalkSeeder.php))
- Haya Walk (Jawad Developments) — 50 units across 3 zones (A/B/C), 33 leased + 17 vacant.
- Historical invoices generated from each lease's commencement → today, with realistic paid/partial/overdue mix.
- Matching `Payment` rows for the paid portion.
- Seeded maintenance requests across statuses (submitted/in_progress/resolved) and priorities so the triage queue, dashboard widget, and SLA-breach flagging all have realistic data on first load.
- 3 demo users + 3 portal-login tenants (see Logins).
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

**Top-level pages** ([app/Filament/Admin/Pages/](app/Filament/Admin/Pages/))
- `OccupancyMap` (Operations) — floor-grouped color-coded unit grid for any property. Asset selector when there's more than one. Each tile links to the unit edit page.
- `ActivityLog` (Reports) — system-wide audit timeline with subject/event filters.

**Resources** ([app/Filament/Admin/Resources/](app/Filament/Admin/Resources/))
- Properties (Asset) · Tenant Directory (Unit) · Tenants · Leases · Maintenance Requests — under Operations.
- Invoices · Payments — under Billing.
- Users — under Settings (super_admin only). Full CRUD + multi-role assignment.

**Relation managers** (data graph navigation) — under [app/Filament/Admin/RelationManagers/](app/Filament/Admin/RelationManagers/)
- Lease → Invoices · Activity Log
- Tenant → Leases · Payments · Maintenance · Activity Log
- Invoice → Activity Log
- Payment → Activity Log
- MaintenanceRequest → Comments (admin can toggle `is_internal` per comment)

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

### RBAC (Spatie Permission)
- 3 roles: `super_admin`, `manager`, `viewer`. Seeded via [RolesPermissionsSeeder](database/seeders/RolesPermissionsSeeder.php).
- [RoleGatedActions](app/Filament/Admin/Resources/Concerns/RoleGatedActions.php) trait applied to admin resources — controls `canCreate`/`canEdit`/`canDelete`.
- UserResource (Settings nav) restricted to `super_admin`.

### Audit trail (Spatie ActivityLog)
- `LogsActivity` trait on Lease, Invoice, Payment, Tenant, Charge, MaintenanceRequest, MaintenanceRequestComment.
- Tracks only whitelisted fields, dirty-only, no empty changes.
- Global Activity Log page (Reports nav).
- Per-record Activity tab (relation manager) on Lease, Invoice, Tenant, Payment.

### Document attachments (Spatie MediaLibrary)
- `Lease`, `Tenant`, and `MaintenanceRequest` implement `HasMedia`. Lease/Tenant expose a `documents` collection; MaintenanceRequest exposes `attachments` for photos / short videos.
- `SpatieMediaLibraryFileUpload` form field on the relevant edit/create forms — drag-drop contract scans, IDs, registration docs, fault photos.
- Storage handled by Spatie defaults (filesystem of choice).

### Branding & i18n
- Real Jawad Developments logo + favicon (sourced from their homepage).
- Jawad palette CSS theme — charcoal `#1A1A1A` + cream `#F5F1EA` + gold `#C9A961`.
- **EN ↔ AR language switch** — segmented pill on every page (topbar + login). Full RTL flip via Filament's built-in `dir` attribute.
- Translation files: [lang/en/admin.php](lang/en/admin.php) + [lang/ar/admin.php](lang/ar/admin.php) — ~685 lines each, covering nav/groups/resources/widgets/tables/filters/actions/fields/sections/statuses/enums/pdf/statement/activity/users/tenants/occupancy/maintenance (status + priority + category enums, action buttons, portal-side strings).
- DD/MM/YYYY date format everywhere; locale-aware month names via Carbon's `isoFormat('MMM YYYY')`.
- EGP currency consistent across every `->money()` call.
- Arabic PDF rendering uses mPDF's `autoArabic` + `autoLangToFont` so letters connect correctly; `xbriyaz` font for Arabic, `dejavusans` for Latin, with conditional zeroing of `letter-spacing` / `text-transform` per locale.

### Automated tests
- **PHPUnit** ([tests/](tests/)) — baseline, runs via `php artisan test`.
- **Playwright E2E** ([tests/e2e/](tests/e2e/)) — 8 spec files / 24 specs covering auth, every admin page, CRUD navigation, portal flows, PDF downloads in EN + AR, locale switching, and the occupancy map. Run via `npx playwright test`. HTML report lands in [storage/playwright-report/](storage/playwright-report/). Session caching: global setup logs into each panel once and writes `storage/playwright-state/{admin,portal}.json`; tests opt in via `test.use({ storageState: ... })` to skip the login step.

---

## Polish wins still available

Small-scope, no external dependencies. Each is a single session to deliver.

- [ ] **Asset → Units relation manager** — currently you can navigate down from a Lease/Tenant but not directly from a Property edit page. (Note: the Occupancy Map page covers visual browsing already, but a sortable/filterable table view would still be useful from inside the Asset record.)
- [ ] **Notes / communications log on Tenant** — admin records phone calls / WhatsApp / meetings against a tenant. Real-world collections-team feature; could double as a polymorphic `Note` model attachable to Lease/Invoice too.
- [ ] **Bulk PDF download** on Invoices — bulk action that returns a zip of selected invoice PDFs.
- [ ] **Bulk WhatsApp send** on Invoices — bulk action when WhatsApp is unblocked.
- [ ] **Credit notes / refunds** — issue a credit note against an invoice (adjusts balance, optionally records refund).
- [ ] **Late-fee automation** — scheduled job that auto-generates a late-fee charge once an invoice passes due_date by N days.
- [ ] **Email invoice on issue** — Mailable that attaches the invoice PDF and sends to tenant email on creation.
- [ ] **CSV import** for bootstrapping (Tenants / Units / Leases) — Filament's `ImportAction` + matching importers; `imports`/`failed_import_rows` tables already migrated.

## Blocked on external credentials

Each needs a sandbox account or sandbox-API key before integration can start. Both Pay Now and Send WhatsApp are gated by env flags in [`config/integrations.php`](config/integrations.php) — set in `.env`:

```
PAYMOB_ENABLED=false   # hides Pay Now buttons
WHATSAPP_ENABLED=false # hides Send WhatsApp button
```

When either is `false` (the default), the action is invisible — no stub click, no awkward "this just shows a notification" moment in the demo. Flip to `true` once the integration is wired.

- [ ] **Pay Now via Paymob** — current button is a stub. Needs Paymob test merchant. Scope: `PaymentInitiationController`, `PaymobWebhookController` with HMAC signature verification, payment row created on init, balance recalc on webhook. Egyptian-relevant methods: card, InstaPay, wallets.
- [ ] **Send WhatsApp** — both an admin action ("send invoice reminder") and a "Pay Now" link via WhatsApp from the portal. Needs WhatsApp Business API access (Meta) or a BSP like Wati/360dialog. Scope: outbound template messages for invoice issue + payment received + due-date reminder.
- [ ] **ETA (Egyptian Tax Authority) submission** — Invoice model already has `eta_submission_id` / `eta_submitted_at` / `eta_response` columns. Needs ETA portal test credentials + e-invoice JSON template. Scope: build the JSON payload from Invoice + Items, sign, POST to ETA test endpoint, store response.
- [ ] **Email delivery in production** — currently sends nothing. Needs SMTP or Mailgun/Postmark credentials.

## Larger items / future considerations

Bigger installs or scope decisions that deserve their own session.

- [ ] **Multi-property tenancy** — if Jawad scales to multiple malls, Filament has a Tenancy concept that scopes everything by Asset. Schema is already keyed on `asset_id` via Unit → Asset, so the migration is mostly a Panel config change. (Occupancy Map already supports an asset switcher when more than one exists.)
- [ ] **Maintenance module v2** — vendor management as a first-class entity (currently assignee is just a `User`), recurring/scheduled maintenance (quarterly HVAC, monthly fire-alarm test) with a `MaintenancePlan` recurrence model, chargebacks that integrate maintenance costs with Charge/Invoice based on lease landlord-vs-tenant responsibility rules, and parts/inventory tracking.
- [ ] **Mobile app for tenants** — Laravel API + React Native or Flutter. Portal data model is already API-ready.
- [ ] **Deeper analytics** — beyond the current widget set: per-category occupancy trends, churn analysis, percentage-rent reconciliation, lease renewal funnel.
- [ ] **Accounting export** — Excel/SAP-friendly export for the accounting team's monthly close (invoices + payments + journal entries). CSV export already exists for raw data.
- [ ] **Tenant ratings / scorecard** — track on-time payment %, lease-renewal history, contract compliance.

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
