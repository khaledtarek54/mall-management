# Atriom — Technical Deep-Dive

> **Product:** Atriom — Egyptian Mall Operations platform
> **Audience:** Eltizam's technical evaluators (EAST-O Holdings engineering, Aldar integration architects, in-house tech leads).
> **Purpose:** What you'd want to know before signing off on this codebase as a complementary stack alongside PropEzy. Honest about trade-offs, specific about choices, transparent about what's deferred.
> **Reading time:** 30 minutes. Skim the section headers first; depth-read the sections that match your role.

---

## 1. Stack

| Layer | Choice | Why |
|---|---|---|
| Language | PHP 8.4 | Strong type system in modern PHP; mature ecosystem for tax / financial software; high availability of Egyptian PHP developers |
| Framework | Laravel 13.8 | Industry standard; conservative LTS-style release cadence; excellent migration / queue / mail / cache subsystems |
| Admin / Portal UI | Filament 4 | Server-rendered Livewire, three independent panels (admin / portal / owner). No SPA build pipeline, zero JS bundling cost, accessible by default, mature RBAC + multi-tenancy hooks |
| DB | MySQL (via DBngin in dev) | Conservative, well-understood. No exotic features used; portable to MariaDB / PlanetScale / RDS without code changes |
| PDF | mPDF | **Chosen specifically for Arabic shaping + bidi**. DomPDF (the Filament default) emits Arabic glyphs in logical order without ligature shaping — produces broken Arabic. mPDF's `autoArabic` + `autoLangToFont` handle the rendering correctly with `xbriyaz` for Arabic and `dejavusans` for Latin |
| RBAC | Spatie Permission | Mature, audited, standard in the Laravel ecosystem |
| Audit | Spatie ActivityLog | Single-table activity log with morphed subjects; granular dirty-only diffs in JSON; standard for governance + compliance |
| File uploads | Spatie MediaLibrary | Polymorphic media with conversions; pluggable filesystem driver (local / S3 / DigitalOcean Spaces / etc.) |
| Tests | PHPUnit + Playwright (chromium) | PHPUnit for unit/integration, Playwright for E2E. Session caching speeds the E2E suite from ~3 min to ~1 min |

**What we're not using and why:**
- No GraphQL — REST + Livewire is sufficient; GraphQL adds complexity without proportional value for this surface area
- No microservices — operationally premature for a single-property pilot
- No Redis (yet) — DB-backed sessions + queue work for current load; trivial to swap to Redis at deployment when traffic warrants
- No frontend SPA framework — Filament + Livewire + Alpine handles all interactivity server-side

---

## 2. Data model

16 entities across 4 logical clusters. Full schema in [database/migrations/](database/migrations/); model relationships in [app/Models/](app/Models/).

### Property graph (the core)
```
Operator ─┬─ Asset ─┬─ Unit ─┬─ Lease ─┬─ Charge
          │         │        │         ├─ Invoice ─ InvoiceItem
          │         │        │         │              │
          │         │        │         │              ▼
          │         │        │         │           Payment  (M2M via invoice_payment pivot
          │         │        │         │                     with allocated_amount column)
          │         │        │         └─ TenantSalesDeclaration
          │         │        │
          │         │        └─ MaintenanceRequest ─ MaintenanceRequestComment
          │         │        │
          │         │        └─ UtilityMeter ─ MeterReading
          │         │
          │         ├─ CamExpensePool ─ CamAllocation
          │         │
          │         └─ owners (M2M to User via asset_owner pivot)
          │
          └─ assets (1:many)
```

Each Lease also points to a Tenant (the rental tenant, distinct from Operator). Self-referential `previous_lease_id` on Lease for renewal chains.

### Why this shape vs a single flat table

Egyptian commercial leases have real polymorphism: charges differ per lease, invoices bundle multiple charges, payments allocate across invoices (one large payment can clear several invoices, partially or fully). A flat "billing row" model loses that allocation structure and makes credit-note / dispute workflows impossible. The pivot-with-payload (`allocated_amount`) is the right Egyptian-accounting fit.

### Why polymorphic `declared_by` on TenantSalesDeclaration + `author` on MaintenanceRequestComment

Both workflows allow either the Tenant or an Admin to act on behalf. A morphTo column makes that obvious in queries and reports without a nullable `tenant_id` + nullable `user_id` shape.

---

## 3. Multi-tenancy / multi-property

**Design decision: session-based scoping with a global Eloquent scope, NOT Filament's URL-tenancy.**

### Why not Filament URL tenancy

Filament 4's built-in tenancy would change `/admin/leases` to `/admin/{operator-slug}/leases`. That:
- Breaks every existing Playwright spec at the URL level
- Forces the login flow to add a "select tenant" step
- Couples permission gating to URL parameters rather than session

For a single-operator deployment today + ambition to scale to many operators, session-based scoping is the less invasive choice. URLs stay stable. The switcher is a topbar dropdown that writes to session via a single GET route ([routes/web.php](routes/web.php)).

### How scoping actually works

```php
// app/Models/Asset.php
#[ScopedBy([CurrentOperatorScope::class])]
class Asset extends Model { ... }

// app/Models/Scopes/CurrentOperatorScope.php
class CurrentOperatorScope implements Scope {
    public function apply(Builder $builder, Model $model): void {
        $operatorId = CurrentOperator::id();
        if ($operatorId === null) return;
        $builder->where($model->getTable().'.operator_id', $operatorId);
    }
}
```

Every Asset query auto-filters by current operator. Everything downstream (Unit, Lease, Tenant via lease, Invoice via lease, etc.) inherits the filter through Eloquent eager loading and `whereHas` chains.

### The Owner Portal explicitly bypasses scope

```php
// app/Filament/Owner/Resources/Properties/PropertyResource.php
public static function getEloquentQuery(): Builder {
    return parent::getEloquentQuery()
        ->withoutGlobalScopes([CurrentOperatorScope::class])
        ->whereHas('owners', fn ($q) => $q->where('user_id', Auth::id()));
}
```

Owners see across operators (their portfolio); admins see the current operator they've selected.

### Brand swap

`AdminPanelProvider` and `OwnerPanelProvider` both use closures for `brandName` / `brandLogo` / `favicon` that resolve from current-operator-on-each-request. **One known limitation**: Filament 4's `->colors()` is evaluated once at panel boot, so the primary accent color stays static. Per-operator accent color swap would require a CSS-variable-based theme override, which is on the polish backlog.

---

## 4. Security model

### Authentication

- **`User`** (Spatie HasRoles) drives admin + owner panels. Single users table, role-based panel access via `User::canAccessPanel()`.
- **`Tenant`** is a separate model extending `Authenticatable` with its own `portal` auth guard. Tenants never log into admin / owner panels.
- Passwords hashed via Laravel's default Argon2 / bcrypt (auto-detected).
- Admin generates tenant passwords via a "Setup/Reset Portal Access" action — auto-generated, shown in a persistent notification, distributed via WhatsApp by the property manager.

### Authorization

Three layers:
1. **Panel access** — `User::canAccessPanel($panel)` returns true only when role matches panel:
   - admin panel → super_admin / manager / viewer
   - owner panel → owner role
   - portal panel → uses separate auth guard, not affected
2. **Resource-level CRUD** — `RoleGatedActions` trait controls `canCreate` / `canEdit` / `canDelete` per role on each Filament resource
3. **Query scoping** — owner-panel resources additionally filter by ownership via `whereHas('owners', ...)`; portal resources filter by `auth('portal')->id()`

### Audit trail

Every governance-relevant entity uses Spatie ActivityLog with whitelisted dirty-fields:

| Entity | Logged fields |
|---|---|
| Lease | reference, status, dates, rent, service charge, tenant_id, unit_id |
| Invoice | number, status, dates, total, paid, balance, tenant_id, lease_id |
| Payment | reference, amount, method, status, tenant_id |
| MaintenanceRequest | status, priority, assigned_to, target_resolution_at |
| TenantSalesDeclaration | status, declared_sales, calculated_percentage_rent, locked_at |
| CamExpensePool | status, totals, reconciled_at |

Audit trail surfaces in two places: a global `/admin/activity-log` page, and per-record relation manager tabs on Lease / Invoice / Tenant / Payment.

### CSRF + sessions

Standard Laravel — `PreventRequestForgery` middleware on every panel. Session driver is `database` in production (works without Redis); auto-fall-back-able to Redis when traffic warrants. `AuthenticateSession` middleware on every panel destroys the session on identity change to prevent fixation.

### Soft deletes

All material entities have soft deletes. The admin Resources use `withoutGlobalScopes([SoftDeletingScope::class])` in `getRecordRouteBindingEloquentQuery()` so deleted records can be viewed / restored from the trash. Bulk Force Delete is `super_admin`-only.

---

## 5. ETA e-invoicing architecture

### Why this matters

Egypt's ETA mandate is real and mandatory for B2B. Every invoice must be submitted with a structured JSON document, signed, and persisted with the returned UUID. PropEzy doesn't publicly advertise this. We built it end-to-end.

### Three services, one job, one config

```
EtaSubmissionService (orchestrator)
        │
        ├─ EtaJsonBuilder ── Invoice + InvoiceItems  →  ETA spec v1.0 document JSON
        │       (taxpayerActivityCode 6820 for real-estate rental;
        │        per-charge EGS itemCode mapping; T1/V009 tax codes
        │        for Egyptian VAT; issuer + receiver address structures)
        │
        ├─ EtaApiClient ── isMock() ? mockResponse() : realResponse()
        │       (real: client_credentials bearer-token auth → POST
        │        to ETA preprod; auth_endpoint at id.preprod.eta.gov.eg)
        │
        └─ Invoice::update with eta_submission_id, eta_long_id, eta_status,
                               eta_submitted_at, eta_response (full JSON)
```

### Mock vs live

Config in [config/eta.php](config/eta.php) — `ETA_ENABLED` (default `true`), `ETA_MOCK` (default `true`). Mock returns a deterministic Valid response that mirrors ETA's actual JSON shape so the persisted `eta_response` looks real in audit logs. Flip `ETA_MOCK=false` once `ETA_CLIENT_ID` + `ETA_CLIENT_SECRET` are in `.env` and the integration goes live.

### Production-cert pathway

ETA's preproduction certificate is a separate, longer regulatory process (4-8 weeks typically). Mock-then-preprod-then-prod is the staged path. Each stage flips the same flag set; no schema or service changes needed.

### Bulk submission

`SubmitInvoiceToEta` is a queued job ready for bulk operation. Today only the per-invoice admin action is wired. Wiring the bulk-toolbar action is a half-day task once the demo shape is locked.

---

## 6. Billing engine

### MonthlyBillingService

[`app/Services/MonthlyBillingService.php`](app/Services/MonthlyBillingService.php) handles the monthly invoice run:

1. Query all `active` leases whose commencement is past + expiry is null or future
2. For each lease, check `(lease_id, period_start)` uniqueness — skip if already billed
3. Iterate `is_active` charges, build InvoiceItems
4. Apply EG VAT rules per charge (rent exempt, service 14%, other per-charge `vat_applicable` + `vat_rate`)
5. Compute subtotal + VAT total + total + balance
6. Each lease in its own transaction so one failure doesn't abort the run
7. Return stats: considered / created / skipped / failed / failed_lease_ids

Backed by:
- `RunMonthlyBilling` queued job
- `billing:run-monthly` Artisan command
- "Run Monthly Billing" header action on the Invoices table

### How CAM and Tenant Sales feed back into billing

Both modules generate one-off Charges on locking:
- `PercentageRentCalculationService::lock()` creates a Charge with type `percentage_rent`, frequency `one_time`, dated to the declaration period
- `CamReconciliationService::bill()` creates a Charge with type `other`, named "CAM Reconciliation — {year}"

The next monthly billing run picks these up naturally via the `is_active` filter. No special-casing in the billing engine.

### Idempotency

The unique constraint on `(lease_id, period_start)` for invoices means re-running the billing job for the same month is a no-op. The job reports skipped counts so operators can see what was already billed.

---

## 7. Internationalization

### Architecture

Custom `SetLocale` middleware ([app/Http/Middleware/SetLocale.php](app/Http/Middleware/SetLocale.php)) reads `session('locale')` and calls `app()->setLocale(...)`. Registered on the `web` group **and** each Filament panel's middleware stack (panels don't inherit `web`).

Switcher is a Blade partial rendered via Filament render hook at `PanelsRenderHook::TOPBAR_END`, scoped per-panel.

### Translation surface

[lang/en/admin.php](lang/en/admin.php) and [lang/ar/admin.php](lang/ar/admin.php) — both files mirror each other section for section. ~800 lines each. Section structure:

- `groups` (nav groups)
- `navigation` (sidebar labels)
- `resources` (singular/plural per resource)
- `widgets` (per-widget content)
- `tables` (column labels per resource)
- `filters` (filter labels)
- `actions` (action labels + modal copy)
- `notifications` (notification titles + bodies)
- `fields` (form field labels)
- `sections` (form section headings)
- `statuses` (per-entity status enum labels)
- `enums` (other enums: meter_type, maintenance_category, etc.)
- `pdf` / `statement` (PDF template strings)
- `users` / `tenants` / `occupancy` / `operators` / `activity` (page-specific)

### Numerals + dates

- Western Arabic numerals (1, 2, 3) throughout for amounts — easier reading for finance staff who switch between EN and AR
- DD/MM/YYYY everywhere (Egyptian convention) — never MM/DD/YYYY, never ISO in tenant-facing surfaces
- Month names: Carbon's `isoFormat('MMMM YYYY')` resolves to localized month names

### PDF Arabic shaping

The non-trivial bit. mPDF's `autoArabic` + `autoLangToFont` are enabled in both invoice and statement templates. Two non-fatal vendor warnings (`Undefined array key "BORDER-LEFT"` and `trim(): null` deprecation) are worked around in templates by always setting both `border-left` AND `border-right` on RTL tables.

Font choices: `xbriyaz` for Arabic, `dejavusans` for Latin. `letter-spacing` and `text-transform` are conditionally zeroed in Arabic locale to prevent kerning issues.

---

## 8. Testing strategy

### PHPUnit

[tests/](tests/) — baseline. Runs via `php artisan test`. Coverage is honestly thin (the project leans on Playwright for confidence); state-machine and service-logic unit tests are the obvious expansion area.

### Playwright E2E

[tests/e2e/](tests/e2e/) — 14 spec files, 68 specs. Configured in [playwright.config.js](playwright.config.js).

**Spec organization:**

| File | Specs | Covers |
|---|---|---|
| `01-auth.spec.js` | 5 | Login flow, bad creds, redirects for protected routes |
| `02-admin-pages.spec.js` | varies | Every admin resource page loads without 500 |
| `03-admin-crud.spec.js` | 3 | Resource list pages and form pages |
| `04-portal.spec.js` | 3 | Tenant portal happy path |
| `05-pdfs.spec.js` | 3 | PDF downloads (invoice, statement, EN + AR) |
| `06-locale.spec.js` | 5 | Locale switching, RTL, translated headers |
| `07-pdf-content.spec.js` | 4 | PDF rendering content checks (incl. Arabic shaping) |
| `08-occupancy-map.spec.js` | 3 | Occupancy map page renders + Arabic |
| `09-multi-property.spec.js` | 5 | Operator switcher, tenancy isolation, brand swap |
| `10-tenant-sales.spec.js` | 5 | Sales declaration admin + portal flows |
| `11-cam.spec.js` | 2 | CAM Reconciliation page + seeded pools |
| `12-owner-portal.spec.js` | 5 | Owner dashboard, scoped resources, admin-panel gating |
| `13-eta.spec.js` | 2 | ETA invoice list + Valid badges |
| `14-energy.spec.js` | 2 | Energy meters resource + nav link |

### Session caching

[tests/e2e/global-setup.js](tests/e2e/global-setup.js) logs into each panel once (admin + portal + owner), writes session state to `storage/playwright-state/{admin,portal,owner}.json`, then specs opt in via `test.use({ storageState: ... })` and skip the login step. Suite runtime: ~2 min for full suite.

### Known fragility under load

Filament + Livewire navigation uses `wire:navigate` which is SPA-style. Playwright's default navigation waits don't always align with Livewire's morphing. The fix pattern when a test flakes: add `{ waitUntil: 'networkidle' }` to `page.goto()`. See the brand-swap test in `09-multi-property.spec.js` for the canonical example.

---

## 9. Scaling assumptions

### Today's load (Haya Walk, single asset)

- 50 units, 33 active leases
- ~200 invoices generated per month
- ~5-15 maintenance requests per month
- ~33 tenant sales declarations per month for F&B/retail leases
- 1 admin + 3 managers + 3-5 tenant logins per day

Comfortably handled by: 1 PHP-FPM server, 1 MySQL instance, 1 queue worker, 1 cron. ~$20-50/mo VPS.

### Scale points (when does each layer warrant attention)

| Layer | Comfortable at | Becomes a concern at | Mitigation |
|---|---|---|---|
| MySQL | 100K invoices | 1M+ invoices | Add read replicas; partition activity_log by month |
| PHP-FPM | 10 properties | 50+ properties | Horizontal scale + load balancer (stateless) |
| Sessions | DB-backed | 100+ concurrent users | Swap session driver to Redis |
| Queue | Database driver | High billing-run volume | Swap queue driver to Redis or SQS |
| Filament admin | Single-operator | 50+ operators per admin | Already session-scoped; per-operator dashboards already render fast |
| Activity log | 1M rows | 100M rows | Spatie ships archival helpers; partition by month |

### Multi-property at scale

The `Operator` model + `CurrentOperatorScope` is designed to scale to N operators. Tested with 2 in the seed; the scope is a single indexed WHERE, so 50 operators with 10 assets each (500 assets) is the same cost as 1 operator. The bottleneck would be the operator-switcher dropdown rendering 50 entries — solved with a search input when needed.

---

## 10. Deployment

### Recommended baseline

- **VPS or PaaS:** DigitalOcean / Vultr / Hetzner — all have Egypt-friendly latency. Estimate ~EGP 1,000-2,000/mo for a single-property pilot.
- **PHP-FPM 8.4** behind nginx
- **MySQL 8** locally on the VPS for the pilot; migrate to RDS / DigitalOcean Managed DB when multi-property
- **Queue worker** as a systemd service running `php artisan queue:work`
- **Cron** for `php artisan schedule:run` every minute (Laravel scheduler)
- **Storage:** local for the pilot; S3 / DigitalOcean Spaces when multi-property (Spatie MediaLibrary swap is a config change)
- **Mail:** Mailgun / Postmark / SES (not yet wired)

### Zero-downtime deploys

Laravel + Filament are well-suited to standard zero-downtime patterns (atomic symlink swap with cached config + routes + views). [Laravel Envoyer](https://envoyer.io/) or simple bash deploy script both work.

### Secrets

All credentials via `.env` — Paymob, ETA, WhatsApp BSP, SMTP. The `.env` file is never committed; production secrets via VPS environment variables or a secrets manager.

### Backups

Daily MySQL dump → S3 / Spaces (or your preferred object store). MediaLibrary files: same. Egypt-residency requirements may dictate region — please specify and we'll match.

---

## 11. Data portability + exit story

A real partnership concern: what if Eltizam wants to leave in 12 months?

### How we'd hand over

1. **Full database dump** in standard MySQL dump format
2. **All MediaLibrary files** in their original filesystem layout
3. **Source code** — already version-controlled in Git; we hand over the repo + 30-day transition window
4. **Documentation** — [FEATURES.md](FEATURES.md), [TECH-DEEPDIVE.md](TECH-DEEPDIVE.md) (this file), in-code comments
5. **Onboarding session** for the receiving team

### What's portable vs proprietary

Everything is portable. There's no proprietary serialization format, no opaque binary state, no vendor-lock encryption. The schema is plain MySQL; the audit log is standard Spatie ActivityLog; the documents are PDF and JSON; the ETA submissions are stored as the raw JSON ETA returned.

### What we don't offer (transparency)

We don't multi-tenant your data into a shared instance. Each operator deployment is its own database. That's a design choice that prioritizes data isolation + portability over scale-economy. Pricing reflects this.

---

## 12. API extensibility

### Today

- Laravel routes are RESTful where appropriate; mostly Filament Livewire endpoints under the hood
- No public API yet — the platform is intentionally not exposing one until there's a defined integration partner

### Two paths to a public API

1. **Laravel Sanctum + Resource controllers** — straightforward, ~1 week per resource exposed. Token-based auth, scoped per integration partner.
2. **Filament API plugin** — auto-generates API endpoints from existing resources. Faster to ship, less control over response shape.

Both are deferred until a real integration partner exists. Building API surface speculatively wastes effort and creates maintenance debt.

### Webhook hooks for integrations

Laravel events are wired throughout (Filament emits, our services emit). When Eltizam's systems want to react to events ("a lease was signed", "an invoice was paid"), the path is:

1. Define webhook subscription model
2. Subscribe to Laravel events of interest
3. POST signed payloads to subscriber URLs

~2-3 days of work once subscription targets are known.

---

## 13. Honest gaps

The things we want you to know we don't have yet.

| Gap | Why it's not built | When it lands |
|---|---|---|
| Native mobile app | Web tenant portal + WhatsApp share covers most workflows today. Mobile is Q2 per [MOBILE-APP-BRIEF.md](MOBILE-APP-BRIEF.md). Done right > done now. | Q2 2026 |
| Paymob payment processing live | Sandbox merchant credentials in application. Architecture in place; flip `PAYMOB_ENABLED=true` on creds. | Days after Paymob approval |
| ETA preprod submission live | Same story — preprod credentials in application. Mock mode demoes the flow end-to-end. | 1-3 weeks |
| Production ETA certificate | Separate longer regulatory process. | 4-8 weeks post preprod |
| WhatsApp Business outbound | Waiting on Meta / Wati credentials. Architecture in place. | 1-2 weeks |
| Email-on-issue | Mailable class needed; SMTP / Mailgun creds. | Half-day once mail provider is chosen |
| Vendor management | Skipped intentionally — not a moat vs PropEzy; parity feature. Maintenance routes to internal users today. | Ship if Eltizam asks |
| CAM annual auto-true-up | v1 is admin-manual click. Auto-true-up scheduled job is Q2. | Q2 2026 |
| Energy optimization workflows | v1 is monitoring-only. Anomaly detection + peak-demand + IoT integration are Q3. | Q3 2026 |
| Public REST / GraphQL API | No integration partner yet; building speculatively wastes effort. | When subscription target exists |
| Bulk WhatsApp / bulk ETA toolbar actions | Backend job ready; UI toolbar wiring is half-day each. | Q1 polish |

---

## 14. Coding standards + onboarding

### Conventions

- PSR-12 + Laravel Pint defaults
- Filament 4 conventions: nested resource folders (`Resources/<Plural>/{Resource.php, Schemas/, Tables/, Pages/}`)
- Services for non-trivial business logic (LeaseRenewalService, MonthlyBillingService, EtaSubmissionService, etc.) — controllers and resources stay thin
- Spatie ActivityLog for governance-relevant entities; whitelisted dirty fields only
- Soft deletes on material entities; `withoutGlobalScopes([SoftDeletingScope::class])` in resource route bindings so trash is reachable

### Onboarding a new developer

The repo is structured to be picked up in 1-2 days:

1. Clone, `composer install`, `npm install`
2. `.env.example` → `.env`, set DB creds, `php artisan key:generate`
3. `php artisan migrate:fresh --seed` — Haya Walk demo data ready
4. `php artisan serve` (or Herd / Valet)
5. Open `/admin` with seed credentials
6. Read [FEATURES.md](FEATURES.md) for the feature inventory + implementation notes section at the bottom

The implementation-notes section in FEATURES.md captures Filament 4 quirks, locale middleware gotchas, activity log diffs, mPDF temp directory, queue worker invocation — the stuff that wastes a day if not documented.

### Code review surface

For Eltizam evaluators wanting to spot-check quality:

| File | Why it's interesting |
|---|---|
| [app/Services/PercentageRentCalculationService.php](app/Services/PercentageRentCalculationService.php) | The mall-specific moat in one file — both calculation formulas + idempotent lock + Charge creation |
| [app/Services/Eta/EtaJsonBuilder.php](app/Services/Eta/EtaJsonBuilder.php) | The ETA spec implementation — issuer/receiver/lines/tax codes/totals |
| [app/Services/MonthlyBillingService.php](app/Services/MonthlyBillingService.php) | Idempotent monthly billing run with per-lease transaction isolation |
| [app/Models/Scopes/CurrentOperatorScope.php](app/Models/Scopes/CurrentOperatorScope.php) | Multi-tenancy in 15 lines |
| [resources/views/invoices/pdf.blade.php](resources/views/invoices/pdf.blade.php) | Arabic-shaped invoice PDF template |
| [tests/e2e/09-multi-property.spec.js](tests/e2e/09-multi-property.spec.js) | E2E pattern — covers tenancy isolation + brand swap |

---

## 15. Questions you might have

**Q: Why Laravel + Filament instead of Next.js / React / Vue?**
A: Speed of iteration for a single small team. Filament's CRUD generation + role-gated actions + form-builder removes 6-12 months of boilerplate compared to a separated SPA + API. The cost is a smaller frontend customization surface — for an operations platform (not a marketing site), that's the right trade-off.

**Q: Can this scale to 50 properties / 10K tenants / millions of invoices?**
A: Yes, with standard Laravel scaling moves — read replicas, queue + cache to Redis, horizontal PHP-FPM. No code changes required for the first 10x; minor changes for the next 10x. We'd prefer to scale infrastructure when you actually need it rather than over-engineer for hypothetical volume.

**Q: Why mPDF? It's old.**
A: It correctly renders Arabic with proper bidi and ligature shaping. DomPDF (the Filament/Laravel default) does not. We chose correctness over modernity. Long-term, we may evaluate Headless Chrome PDF generation if Arabic font rendering catches up, but mPDF works today.

**Q: Why not use Filament's URL tenancy?**
A: See § 3. Session-based scoping keeps URLs stable for tests, deep links, and bookmarking. Less invasive overall.

**Q: How do you handle Egyptian VAT specifically?**
A: Per-charge `vat_applicable` (bool) + `vat_rate` (decimal). The seeder applies the Egyptian model — rent exempt, service 14%. The billing service respects whatever's on each charge; no special-casing in the engine. Changing VAT rates is a data change, not a code change.

**Q: What's the upgrade path for Laravel / Filament / PHP?**
A: Filament 4 is the current major (we're on it). Laravel 13 → 14 will be a routine update. PHP 8.4 → 8.5 is incremental. We track the LTS line conservatively, never bleeding edge for a production property platform.

**Q: Where would PropEzy integration plug in if Eltizam wanted bidirectional sync?**
A: Three obvious touchpoints:
1. **Tenant directory sync** — webhook-based, when a tenant is added on either side, the other reflects within minutes
2. **Maintenance request relay** — if Eltizam has tenants who escalate from PropEzy to mall-specialist workflows
3. **Financial reporting roll-up** — read-only API endpoint for PropEzy's reporting layer to consume mall ops data

None of this is built; all of it is straightforward to add when there's a defined PropEzy integration spec.

---

## 16. Where to dig next

If you have time:

- 30 min: Browse [FEATURES.md](FEATURES.md) for the full feature inventory + implementation notes
- 60 min: Read [MASTER-PLAN.md](MASTER-PLAN.md) § 1-4 for the strategy / competitive read
- 90 min: `git clone`, run `php artisan migrate:fresh --seed`, log into `/admin`, click through every Operations-nav resource
- Half day: Run the full Playwright suite locally: `npx playwright test --headed --reporter=line`
- Full day: Code-walkthrough the 6 files in § 14 above + read the migration history in [database/migrations/](database/migrations/) chronologically

---

> **The summary:** this is a production-grade Egyptian-mall operations platform built specifically for the retail vertical and the Egyptian regulatory environment. Honest about what's deferred. Designed for portability and partnership, not lock-in. Ready to integrate alongside PropEzy as Eltizam's specialized retail layer.
>
> Questions: contact [your team email / phone].
