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

### Data model (8 entities)
- `Asset` · `Unit` · `Tenant` · `Lease` · `Charge` · `Invoice` · `InvoiceItem` · `Payment`
- All business entities with soft deletes, MySQL enum status fields, FK constraints.
- Tenant model extends `Authenticatable` for portal login + implements `FilamentUser`.
- Lease has self-referential `previous_lease_id` for renewal chain.
- Lease and Tenant implement `HasMedia` (Spatie MediaLibrary) for contract / document attachments.
- LogsActivity trait on Lease, Tenant, Invoice, Payment, Charge.

### Seed data ([HayaWalkSeeder](database/seeders/HayaWalkSeeder.php))
- Haya Walk (Jawad Developments) — 50 units across 3 zones (A/B/C), 33 leased + 17 vacant.
- Historical invoices generated from each lease's commencement → today, with realistic paid/partial/overdue mix.
- Matching `Payment` rows for the paid portion.
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

**Top-level pages** ([app/Filament/Admin/Pages/](app/Filament/Admin/Pages/))
- `OccupancyMap` (Operations) — floor-grouped color-coded unit grid for any property. Asset selector when there's more than one. Each tile links to the unit edit page.
- `ActivityLog` (Reports) — system-wide audit timeline with subject/event filters.

**Resources** ([app/Filament/Admin/Resources/](app/Filament/Admin/Resources/))
- Properties (Asset) · Tenant Directory (Unit) · Tenants · Leases — under Operations.
- Invoices · Payments — under Billing.
- Users — under Settings (super_admin only). Full CRUD + multi-role assignment.

**Relation managers** (data graph navigation) — under [app/Filament/Admin/RelationManagers/](app/Filament/Admin/RelationManagers/)
- Lease → Invoices · Activity Log
- Tenant → Leases · Payments · Activity Log
- Invoice → Activity Log
- Payment → Activity Log

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
- Read-only Invoices + Payments resources scoped to `auth('portal')->id()`.
- Download PDF on each invoice.
- Statement of Account (header action on /portal/invoices).
- **Pay Now** — STUB on portal invoice rows + view page (flashes a notification). Gated by `PAYMOB_ENABLED`. Needs Paymob sandbox.

### RBAC (Spatie Permission)
- 3 roles: `super_admin`, `manager`, `viewer`. Seeded via [RolesPermissionsSeeder](database/seeders/RolesPermissionsSeeder.php).
- [RoleGatedActions](app/Filament/Admin/Resources/Concerns/RoleGatedActions.php) trait applied to admin resources — controls `canCreate`/`canEdit`/`canDelete`.
- UserResource (Settings nav) restricted to `super_admin`.

### Audit trail (Spatie ActivityLog)
- `LogsActivity` trait on Lease, Invoice, Payment, Tenant, Charge.
- Tracks only whitelisted fields, dirty-only, no empty changes.
- Global Activity Log page (Reports nav).
- Per-record Activity tab (relation manager) on Lease, Invoice, Tenant, Payment.

### Document attachments (Spatie MediaLibrary)
- `Lease` and `Tenant` implement `HasMedia` and expose a `documents` collection.
- `SpatieMediaLibraryFileUpload` form field on both Lease and Tenant edit forms — drag-drop contract scans, IDs, registration docs.
- Storage handled by Spatie defaults (filesystem of choice).

### Branding & i18n
- Real Jawad Developments logo + favicon (sourced from their homepage).
- Jawad palette CSS theme — charcoal `#1A1A1A` + cream `#F5F1EA` + gold `#C9A961`.
- **EN ↔ AR language switch** — segmented pill on every page (topbar + login). Full RTL flip via Filament's built-in `dir` attribute.
- Translation files: [lang/en/admin.php](lang/en/admin.php) + [lang/ar/admin.php](lang/ar/admin.php) — ~290 keys covering nav/groups/resources/widgets/tables/filters/actions/fields/sections/statuses/enums/pdf/statement/activity/users/tenants/occupancy.
- DD/MM/YYYY date format everywhere; locale-aware month names via Carbon's `isoFormat('MMM YYYY')`.
- EGP currency consistent across every `->money()` call.
- Arabic PDF rendering uses mPDF's `autoArabic` + `autoLangToFont` so letters connect correctly; `xbriyaz` font for Arabic, `dejavusans` for Latin, with conditional zeroing of `letter-spacing` / `text-transform` per locale.

### Automated tests
- **PHPUnit** ([tests/](tests/)) — baseline, runs via `php artisan test`.
- **Playwright E2E** ([tests/e2e/](tests/e2e/)) — 47 specs across auth, every admin page, CRUD navigation, portal flows, PDF downloads in EN + AR, locale switching, occupancy map. Run via `npx playwright test`. HTML report lands in [storage/playwright-report/](storage/playwright-report/).

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
- [ ] **Maintenance / facilities module** — tenant-submitted work orders + admin triage. See spec below.
- [ ] **Mobile app for tenants** — Laravel API + React Native or Flutter. Portal data model is already API-ready.
- [ ] **Deeper analytics** — beyond the current widget set: per-category occupancy trends, churn analysis, percentage-rent reconciliation, lease renewal funnel.
- [ ] **Accounting export** — Excel/SAP-friendly export for the accounting team's monthly close (invoices + payments + journal entries). CSV export already exists for raw data.
- [ ] **Tenant ratings / scorecard** — track on-time payment %, lease-renewal history, contract compliance.

---

## Feature request — Tenant-facing maintenance requests

**Status:** Proposed — not started. Belongs to the broader Maintenance / facilities module bullet above; this is the tenant-portal-first slice of that work.

### Why

Tenants today have no in-app way to flag a broken AC, a leaking pipe, or a faulty fire alarm. They WhatsApp or phone the property manager, who scribbles it on paper and forgets. The mall has no audit trail of how long requests took to resolve, which units are the most failure-prone, or which categories drive the most calls. This is the missing piece between "leased the space" and "tenant is happy enough to renew."

### User stories

- **As a tenant** I want to submit a maintenance request from `/portal` with a description, category, priority, and photos, so my issue is logged without me having to chase someone.
- **As a tenant** I want to see the status of all my requests (open / in progress / resolved) and the history of resolved ones, so I know what's been done.
- **As a tenant** I want to add follow-up comments to an open request and receive notifications when staff respond or change the status.
- **As a property manager** I want a single triage queue across the whole mall, filterable by status / priority / category / unit, so I can plan my day.
- **As a property manager** I want to assign a request to an internal staffer or external vendor, set a target resolution date, log work notes, and mark it resolved with a closing summary.
- **As a super-admin** I want to see SLA breach counts and average resolution time per category on the dashboard, so I can spot vendors and asset categories that are underperforming.

### Scope — in

**Data model** (one new entity + one supporting table)
- `MaintenanceRequest` — `id`, `tenant_id` (FK), `unit_id` (FK), `lease_id` (FK, nullable for ex-tenants), `category` (enum: `electrical` / `plumbing` / `hvac` / `structural` / `cleaning` / `safety` / `other`), `priority` (enum: `low` / `medium` / `high` / `urgent`), `status` (enum: `submitted` / `acknowledged` / `in_progress` / `awaiting_tenant` / `resolved` / `closed` / `cancelled`), `title`, `description`, `submitted_at`, `acknowledged_at`, `resolved_at`, `closed_at`, `assigned_to` (FK → users, nullable), `target_resolution_at` (nullable), `resolution_notes` (nullable), soft deletes, timestamps.
- `MaintenanceRequestComment` — `id`, `maintenance_request_id`, `author_type` + `author_id` (polymorphic — tenant or user), `body`, `is_internal` (bool — hides admin-only notes from the tenant), timestamps.
- Photos / videos via Spatie MediaLibrary on `MaintenanceRequest` (collection: `attachments`) — re-uses the same pattern as Lease/Tenant documents.

**Status flow**
```
submitted → acknowledged → in_progress → resolved → closed
                  │             │            ↑
                  │             └─→ awaiting_tenant ┘
                  └─→ cancelled (tenant can cancel before in_progress)
```
- Tenant transitions: `submitted` (create), `cancelled` (only from `submitted` / `acknowledged`), can reply to `awaiting_tenant`.
- Admin transitions: everything else. `resolved` requires `resolution_notes`. `closed` is auto after N days at `resolved` with no tenant reopen.

**Tenant portal** (`/portal`)
- New resource: "Maintenance" in sidebar.
- List page — own requests only (`auth('portal')->id()` scope), default sort newest first, status badges color-coded matching the admin urgency palette.
- Create page — title, category (radio with icons), priority (defaults to `medium`; `urgent` shows a "this is for genuine emergencies" warning), description (markdown-supported textarea), file upload (multi, max 5, photos + short videos).
- View page — full thread of public comments, ability to add a comment, ability to cancel if still `submitted`/`acknowledged`. Status timeline at the top.
- Dashboard widget — "Open maintenance requests" count + link.

**Admin panel** (`/admin`)
- New resource: "Maintenance Requests" under Operations nav, between Leases and Invoices.
- List page — filters for status / priority / category / asset / unit / assigned user / SLA-breached. Bulk assign, bulk status change. Default view: status ≠ closed, sorted by priority desc then submitted_at asc.
- View/Edit page — same comment thread (with `is_internal` toggle), assignment, status changes via action buttons (`Acknowledge`, `Start Work`, `Mark Resolved`, `Close`, `Cancel`, `Request Tenant Input`). Each transition logged via Spatie ActivityLog.
- Relation managers: Unit → Maintenance, Tenant → Maintenance, Lease → Maintenance.
- Dashboard widgets — `OpenMaintenanceRequests` (count by priority, like ActionRequired's pattern), `MaintenanceSlaBreaches` (count of open + past target_resolution_at).

**RBAC**
- `viewer` — read-only.
- `manager` — full CRUD + assign + status transitions.
- `super_admin` — same as manager + delete.
- Existing [RoleGatedActions](app/Filament/Admin/Resources/Concerns/RoleGatedActions.php) trait applies cleanly.

**Audit trail** — `LogsActivity` on `MaintenanceRequest` and `MaintenanceRequestComment`, whitelisted fields (status, priority, assigned_to, target_resolution_at). Surfaces in the existing global Activity Log page.

**Notifications** — in-app Filament notifications on every status change, scoped to the right side:
- Tenant gets notified on acknowledged / in_progress / awaiting_tenant / resolved / new admin comment (public only).
- Admin gets notified on new submission (all managers + super_admins) / tenant comment / tenant cancellation.
- Email + WhatsApp delivery deferred — both gated by the existing `WHATSAPP_ENABLED` flag and the new SMTP work in "Blocked on external credentials." Templates ready, channel disabled.

**SLA defaults** — per priority, set in `config/maintenance.php`:
- `urgent` — 4h to acknowledge, 24h to resolve
- `high` — 1 business day to acknowledge, 3 business days to resolve
- `medium` — 2 business days / 7 business days
- `low` — 5 business days / 14 business days

Stored as config, not in the database, so it's tunable per deployment without a migration.

**i18n** — keys added to both [lang/en/admin.php](lang/en/admin.php) and [lang/ar/admin.php](lang/ar/admin.php) for the resource label, status enums, priority enums, category enums, action buttons, and the tenant-portal-specific strings. Follows the existing `enums.status.*` / `actions.*` / `resources.*` structure.

**Tests**
- PHPUnit — model factories + a `MaintenanceRequestStateMachineTest` covering legal transitions.
- Playwright — `tenant-maintenance.spec.js` (submit → view status), `admin-maintenance.spec.js` (triage → assign → resolve), `maintenance-rtl.spec.js` (Arabic locale renders correctly).

### Scope — out (deliberately, for v1)

- **Vendor management as a first-class entity** — v1 just stores the assignee as a `User`. Recording external vendors as their own model, with contact info / contracts / SLAs / 1099-equivalent reporting, is the v2 expansion.
- **Recurring / scheduled maintenance** (quarterly HVAC service, monthly fire-alarm test) — needs a `MaintenancePlan` model with cron-like recurrence. Big enough to be its own spec.
- **Charging maintenance costs back to tenants** — needs to integrate with Charge / Invoice. The lease typically specifies who pays for what (landlord vs tenant), and that ruleset isn't modeled yet. Defer until the v1 workflow has run for a quarter and we know the real shape of chargebacks.
- **Inventory / parts tracking** — way out of scope.
- **Mobile push notifications** — covered when the tenant mobile app gets built (see "Mobile app for tenants" above).

### Rollout plan

1. **Phase 1 (1 session)** — migration + model + factory + state machine + PHPUnit. No UI yet. Lets us seed realistic data into HayaWalkSeeder so dashboards have something to show.
2. **Phase 2 (1 session)** — admin resource (list / view / actions / relation managers) + dashboard widgets + ActivityLog wiring + RBAC.
3. **Phase 3 (1 session)** — tenant portal resource (list / create / view / cancel) + tenant dashboard widget + media uploads.
4. **Phase 4 (1 session)** — i18n + Playwright specs + SLA breach widget polish + seed-data tuning so the demo flows.

Each phase is independently shippable. After phase 2 the admin can already manage requests entered by phone/WhatsApp on their behalf; phase 3 is what unlocks the self-service portal story.

### Demo Day fit

A 60-second add to the existing 10-minute flow, slotted between steps 9 (Tenant Portal) and 10 (Arabic toggle):
> "And when something breaks — leaky AC, broken fire alarm — the tenant logs it here. Property manager sees it in the triage queue, assigns it, marks it done. Full audit trail, no more WhatsApp screenshots."

Click: tenant submits a sample request → switch to admin window → request appears in queue → acknowledge + assign → done.

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
