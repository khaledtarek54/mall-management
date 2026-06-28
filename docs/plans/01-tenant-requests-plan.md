# Plan 1 — Generalize "Maintenance Request" → "Tenant Request"

> **Goal.** Turn the maintenance-request feature into a general **Tenant Request** system so a tenant can raise *any* kind of request (maintenance, complaint, inquiry, access/parking, billing query, document request, amenity booking, …) from the admin dashboard, the tenant portal, and the mobile app — built the way real property-management systems do it.
>
> **Status:** IN PROGRESS — Phase 1 (additive) + most of Phase 2 shipped. See the progress log below. Drafted 2026-06-28.
>
> **Guiding principle:** the maintenance feature is *already* a generic "request" with a state machine, comments, attachments, SLA, routing, and notifications. We are **renaming + typing** it, not rebuilding it. Lowest-risk, highest-reuse path. Keep the suite green at every phase.

---

## ✅ Progress log (live)

Built additive-first (no risky internal rename yet); suite green at every step (1197 tests).

- **[done] Type foundation** — `App\Enums\TenantRequestType` (7 types, each with sub-categories / has-SLA + per-priority hours / scheduling / default-department slug / reference prefix). Migration adds `request_type` (default `maintenance`, indexed) + `csat_rating`/`csat_comment`; `category` enum → free-form nullable string. Model cast + activity-log. *(commits `4cd17fb`, `246a1ee`)*
- **[done] Type-aware service** — per-type reference prefix, SLA only for SLA-bearing types, auto-routing to the type's default department. *(commit `246a1ee`)*
- **[done] Admin** — form has a live Request Type select + dynamic Sub-category; create page sets SLA/routing per type; table has a type column + filter. *(commit `18850ac`)*
- **[done] Tenant portal + mobile API** — portal form type picker + dynamic sub-category; `/me/maintenance-requests` accepts `request_type`, validates sub-category per type, defaults to maintenance for back-compat; API transformer exposes `request_type`. 5 API tests. *(commit `72a55a6`)*
- **[done] Relabel** — admin + portal nav/resource/section labels → "Requests" (en/ar); module doc updated.
- **[done] Per-type notification copy** — status/comment/submitted/SLA notifications now say the request's type (Complaint, Inquiry, …) via a `:type` placeholder fed by `MaintenanceRequest::typeLabel()`; regression test pins it. *(commit `c2542d7`)*
- **[next — start here tomorrow]** in priority order:
  1. **CSAT** — rating capture on resolved/closed (portal "rate" action + mobile `POST /me/requests/{id}/rate` + admin column); columns `csat_rating`/`csat_comment` already exist. A small "avg satisfaction / SLA compliance by type" report widget.
  2. **Owner-panel** resource relabel (trivial; the owner Filament resource still reads the shared keys but verify).
  3. **The internal rename** (`MaintenanceRequest`→`TenantRequest`, tables, RBAC `maintenance.*`→`requests.*`, routes) — the big, risky one: handle the morph-map hazard (`activity_log.subject_type` + Spatie `media.model_type` store the FQCN) per §4. Do it as its own dedicated, well-tested pass.
  4. **DB `request_types` table** (Phase 2 §3.2) only if operators need self-service type/SLA/routing config.

---

## 1. What property-management systems actually do (the target)

Mature PMS (Yardi, MRI, AppFolio, Buildium, RealPage/Entrata, and PropEzy on the Eltizam side) all converge on the same shape: a single **Service Request / Case** entity with a **configurable type taxonomy**, where each type carries its own workflow, SLA, routing, and intake form. The tenant picks a category, the system routes it to the right team, both sides converse on a thread, and it closes with a satisfaction rating.

### 1.1 The request-type taxonomy (what a mall tenant actually needs)

| Type | Examples | Routes to (department) | SLA? | Attachments | Scheduling |
|---|---|---|---|---|---|
| **Maintenance** (existing) | AC, plumbing, electrical, structural, cleaning, safety | Operations | Yes (priority-based) | Yes | Yes (work window) |
| **Complaint** | Noise, neighbour, cleanliness, staff conduct | Manager / Operations | Yes (soft) | Yes | No |
| **Inquiry / General** | "How do I…", opening hours, policy questions | Manager | No | Optional | No |
| **Access / Security** | Extra keys/cards, parking permit, after-hours access, visitor pass, delivery access | Operations / Security | Yes | Optional | Yes (date/time) |
| **Billing / Account query** | "Explain this charge", payment-plan request, statement request | Accounting | No | Optional | No |
| **Document request** | Lease copy, renewal request, termination notice, NOC / certificate | Leasing | Soft | Optional | No |
| **Fit-out / Alteration** | Shopfront change, signage, AC modification, drilling permit | Operations / Leasing (approval) | Yes | Yes (drawings) | Yes |
| **Amenity / Facility booking** | Loading bay, common-area event space, promo kiosk | Marketing / Operations | No | Optional | Yes (date range) |
| **Other** | Anything uncategorised | Manager (triage) | No | Optional | No |

> Start with **Maintenance + Complaint + Inquiry + Access + Billing-query + Document-request + Other** (7 types). Fit-out and Amenity are richer (approval chains / booking calendars) → defer to a later phase.

### 1.2 The features every PMS request system has (and our gap)

| Feature | Have today (maintenance) | Generalize |
|---|---|---|
| Typed category + sub-category | category only (maintenance-specific) | **request type → sub-category** |
| Per-type workflow (status machine) | one machine | **per-type workflow (default = current)** |
| Priority + SLA | yes | **per-type SLA (some types: none)** |
| Routing to a team/department | `department_id` + manual redirect | **per-type default department, auto-routed** |
| Assignment (staff + vendor) | yes | reuse |
| Conversation thread (tenant ↔ staff, internal notes) | yes (`is_internal`) | reuse (rename) |
| Attachments (photos/PDF) | yes | reuse |
| Multi-channel intake (portal/phone/walk-in/…) | yes (`channel`) | reuse |
| Status notifications (mail + bell + push) | yes | reuse (generalize copy) |
| **Satisfaction rating (CSAT) on close** | ❌ | **ADD (best practice)** |
| **Reopen window** | partial (resolved→in_progress) | keep |
| **Cost tracking** (parts/labour) | ❌ | ADD (optional, later) |
| **Knowledge-base deflection** | ❌ | OUT OF SCOPE v1 |
| **Recurring / preventive requests** | ❌ | OUT OF SCOPE v1 (separate "work order" concept) |

---

## 2. Current state (the seam we cut along)

From the code investigation — what is **generic** (reuse as-is) vs **maintenance-specific** (must become type-configurable):

**Generic (keep, rename):** the status state machine + terminal immutability (`isTerminal`), priorities, `assigned_to` (User) + `assigned_to_vendor_id` (Vendor), `department_id` routing, **polymorphic comments** (`MaintenanceRequestComment.author` morph + `is_internal`), Spatie media `attachments` collection, the notification routing (`AssetStaffRecipients` + `notifyPortal`), RBAC `RoleGatedActions` pattern, the Filament resource shape, the API single-action pattern, portal tenant-admin-only submission, activity log, soft-delete.

**Maintenance-specific (make type-configurable):** `CATEGORIES` enum (electrical/plumbing/…), `target_resolution_at` + SLA calc (`MaintenanceSettings`, `defaultTargetResolution`), `scheduled_from/to`, `sla_breach_notified_at` + the SLA scan command, the auto-close command, the `MR-` reference prefix, `resolution_notes`, `MaintenanceSlaBreachedNotification`.

**Key files** (full inventory in the investigation): `app/Models/MaintenanceRequest.php`, `MaintenanceRequestComment.php`; `app/Services/MaintenanceRequestService.php` (TRANSITIONS state machine); `app/Filament/Admin/Resources/MaintenanceRequests/**`; `app/Filament/Portal/Resources/MaintenanceRequests/**`; `app/Http/Controllers/Api/V1/Maintenance/**` + `app/Actions/Api/V1/Maintenance/**` + the API resources; `app/Notifications/{PortalMaintenanceSubmitted,MaintenanceStatusChanged,MaintenanceCommentAdded,MaintenanceSlaBreached}Notification.php`; `app/Console/Commands/{ScanMaintenanceSlaBreaches,AutoCloseMaintenanceRequests}Command.php`; `app/Settings/MaintenanceSettings.php`; `database/seeders/RolesPermissionsSeeder.php` (`maintenance.*` perms); `lang/{en,ar}/admin.php`.

> **Decision: keep Owner Requests and Sales Declarations as SEPARATE models.** They are "request-like" but financially/structurally distinct (sales declarations create billing charges; owner requests are owner↔operator with different recipients). Folding them into one table buys complexity, not value. The generalization is **maintenance → tenant request**; the others stay as siblings.

---

## 3. Target architecture

### 3.1 Data model — single typed table

Rename `maintenance_requests` → **`tenant_requests`**, `MaintenanceRequest` → **`TenantRequest`**, `MaintenanceRequestComment` → **`TenantRequestComment`**. Add:

```
tenant_requests
  request_type_id     FK → request_types   (NOT NULL)        -- the top-level type
  subcategory         string, nullable                       -- e.g. 'electrical' under Maintenance
  ...keep all existing columns (status, priority, channel, title, description,
     assigned_to, assigned_to_vendor_id, department_id, *_at timestamps,
     target_resolution_at, scheduled_from/to, sla_breach_notified_at,
     resolution_notes, reference, tenant_id, unit_id, lease_id)...
  csat_rating         tinyint, nullable                      -- NEW: 1–5 on close
  csat_comment        text, nullable                         -- NEW
```

Backfill: every existing row → `request_type_id` = the seeded "Maintenance" type, `subcategory` = the old `category`. Drop/retire the old `category` column after backfill (or keep it as `subcategory`'s source — rename `category` → `subcategory` in a migration).

### 3.2 Request types — config table (operator-tunable, the PMS way)

A **`request_types`** table so operators can tune types/SLA/routing without a deploy (what real PMS do). Seed the 7 types; gate the editing UI to super_admin.

```
request_types
  id, slug (unique, e.g. 'maintenance'), name, description, icon, color
  default_department_id   FK → departments, nullable     -- auto-routing
  workflow                json/string                    -- which state machine (default 'standard')
  has_sla                 bool        -- maintenance/access/fit-out = true
  sla_hours               json        -- per-priority {urgent,high,medium,low} (null if !has_sla)
  requires_attachments    bool
  allows_scheduling       bool
  subcategories           json        -- e.g. ['electrical','plumbing',...] (null = none)
  is_active               bool
  sort_order
```

> **Phase-in tip:** Phase 2 can ship with a **`TenantRequestType` PHP enum** (mirror `app/Enums/InvoiceItemType.php`) + per-type config in code, then promote to the DB table in a later phase if operators ask for self-service. Plan the columns above so the enum→table move is a drop-in.

### 3.3 Workflow / state machine

Keep the existing maintenance machine as the **`standard` workflow** (it's general): `submitted → acknowledged → in_progress → awaiting_tenant → resolved → closed | cancelled`, terminal = closed/cancelled. Most request types use it as-is. Allow a `request_type.workflow` discriminator for future per-type machines (e.g. a lighter `inquiry` flow: `submitted → answered → closed`). **Move `TRANSITIONS` into a `RequestWorkflows` registry keyed by workflow slug** so the service looks up the machine by the request's type.

### 3.4 Routing

On create, auto-set `department_id = request_type.default_department_id` (operator can still redirect). This replaces the "everything lands unassigned" behaviour for non-maintenance types. Extend `AssetStaffRecipients::for()` to accept the type's department/roles so notifications fan out to the right team per type.

### 3.5 Reference

Generic prefix **`REQ-{asset}-{year}-{seq}`**, OR per-type prefix from `request_types` (e.g. `MR-` maintenance, `CR-` complaint). Recommend the **per-type prefix** (operators recognise it) — store a `reference_prefix` on `request_types`. Keep the existing `generateReference` logic, parameterised by prefix.

### 3.6 Comments, attachments, notifications, RBAC — reuse

- **Comments:** rename model/table → `TenantRequestComment` / `tenant_request_comments`. Keep the polymorphic author + `is_internal`. No behaviour change.
- **Attachments:** keep the Spatie `attachments` collection on `TenantRequest`.
- **Notifications:** generalize the four maintenance notifications into **request notifications** that read the type's copy: `RequestSubmittedNotification`, `RequestStatusChangedNotification`, `RequestCommentAddedNotification`, `RequestSlaBreachedNotification`. Copy keys become type-aware (`admin.requests.{type}.notifications.*` with a generic fallback). Keep mail+database+push channels and `notifyPortal`.
- **SLA scan + auto-close commands:** rename to `requests:scan-sla-breaches` / `requests:auto-close`; they only act on types where `has_sla` / where auto-close applies. Keep the lock-safe idempotency we already have.
- **RBAC:** rename the permission module `maintenance.*` → **`requests.*`** (`requests.view/create/edit/delete/assign/change_status`). **Backward-compat:** in `RolesPermissionsSeeder`, grant the new `requests.*` to every role that had `maintenance.*`; keep a one-release alias if needed. Per-type gating can come later (`requests.{type}.*`) — start with one module.

### 3.7 Admin Filament resource (type-aware)

Rename `MaintenanceRequestResource` → **`TenantRequestResource`** (nav: "Requests"). The form/table become type-aware:
- Form: a **request-type Select** first; `subcategory`, SLA fields, scheduling, attachments **show conditionally** on the type's config (`->visible(fn ($get) => …)`).
- Table: add a **type column + type filter**; keep status/priority/department columns. Keep the `canEdit` terminal guard, the read-only status (we just fixed that — status changes go through the Change-Status action only).
- Add a CSAT column (read-only).

### 3.8 Portal (tenant) surface

Rename the portal resource → **"My Requests"**. The create form leads with the **type Select** (only `is_active` types), then renders the type-appropriate fields (subcategory for maintenance, date for access, free text for inquiry). Tenant-admin-only create (unchanged). View shows the thread + attachments + status timeline + a **"Rate this" prompt** once resolved (CSAT).

### 3.9 Mobile API

- **New generic endpoints** under `/api/v1/me/requests` (list/create/show/comment/cancel + `GET /me/request-types` for the picker + `POST /me/requests/{id}/rate` for CSAT). Mirror the existing maintenance controllers/actions/resources, type-aware.
- **Backward-compat:** keep `/api/v1/me/maintenance-requests` working — alias them to the generic controllers with `request_type=maintenance` forced — so the current mobile build and `docs/api/MOBILE-API.md` don't break. Deprecate in the doc, remove in a future major.
- Update `docs/api/MOBILE-API.md` + `MOBILE-APP-BRIEF.md` with the new request model.

### 3.10 New best-practice features (phased)

- **CSAT rating** on close (1–5 + comment) — `csat_rating`/`csat_comment`, a portal/mobile "rate" action, an admin column + a "CSAT" report widget. (Phase 4.)
- **Cost tracking** (optional `estimated_cost`/`actual_cost` for maintenance/fit-out) — Phase 5.
- Out of scope v1: knowledge-base deflection, recurring/preventive work orders, approval chains for fit-out (design later).

---

## 4. Migration & backward-compatibility strategy (do this carefully)

This is a **rename of a live, data-bearing module** — treat it like a money migration.

1. **Additive migrations first, rename last.** Create `request_types` + seed; add `request_type_id` (nullable initially) + `subcategory` + `csat_*` to `maintenance_requests`; backfill `request_type_id` = Maintenance, `subcategory` = `category`; then make `request_type_id` NOT NULL.
2. **Rename tables in a dedicated migration** (`maintenance_requests` → `tenant_requests`, `maintenance_request_comments` → `tenant_request_comments`) — Laravel `Schema::rename`. Preserve FKs/indexes.
3. **Model rename** with a temporary class alias if anything references `MaintenanceRequest` by string (morph map! — the comment `author_type`/`subject` morphs and the activity-log `subject_type` store the FQCN; add a **morph map** so old `App\Models\MaintenanceRequest` rows still resolve, or run an update on `notifications`/`activity_log`/`*_type` columns).
4. **RBAC:** seed `requests.*`, grant to the roles that had `maintenance.*`, migrate existing role→permission assignments (or alias). Run on a copy first.
5. **Translations:** add `admin.requests.*` keys (nav, statuses, type names, notification copy) in en + ar; keep old keys until references are gone (the `TranslationCoverageTest` enforces parity).
6. **API aliases** as in 3.9.
7. **Reseed `DemoSeeder`** to create a few non-maintenance requests so the demo shows the new types.
8. **Keep the suite green after every step** — rename ripples through ~15 test files (see investigation); update them in lockstep.

> ⚠️ **Highest-risk items:** the polymorphic `author_type` / activity-log `subject_type` / database-notification `subject` columns hold the FQCN `App\Models\MaintenanceRequest`. Use a **Relation morph map** (`Relation::enforceMorphMap([...])`) so both old and new class names resolve, OR write a data migration to rewrite those columns. Decide this in Phase 1.

---

## 5. Phased rollout (each phase ships green + reversible)

**Phase 0 — Decide (½ day).** Confirm the type taxonomy (§1.1), enum-first vs DB-table (§3.2), reference prefix scheme, the morph-map vs data-migration call. Write the seed list of `request_types`.

**Phase 1 — Pure rename, no new behaviour (de-risk).** Rename model/table/comment/service/resource/API/notifications/commands/permissions/translations `maintenance` → `request`/`tenant_request`; backfill every row to `request_type='maintenance'`; morph-map for old FQCNs; keep all existing maintenance behaviour identical. Update the ~15 test files + the 11-maintenance doc. **Acceptance:** full suite green, demo reseeds, a maintenance request behaves exactly as before, the mobile `/me/maintenance-requests` alias still works.

**Phase 2 — Introduce types + type-aware UI.** Ship `request_types` (enum or table), the type Select + conditional fields in admin + portal forms, the type column/filter, auto-routing by `default_department_id`, per-type SLA lookup, the generic `/me/requests` API + `/me/request-types`. **Acceptance:** can create a Maintenance request (unchanged) AND a second type end-to-end (admin + portal + API), each routed correctly, with type-appropriate fields + notifications.

**Phase 3 — Roll out the real types.** Seed + wire Complaint, Inquiry, Access, Billing-query, Document-request, Other: their workflows (most = `standard`), routing matrix (§1.1), SLA on/off, notification copy. Add per-type tests.

**Phase 4 — CSAT + reporting.** Rating on close (portal/mobile/admin), CSAT column + a small report/widget ("avg satisfaction, open by type, SLA compliance by type").

**Phase 5 (optional) — Cost tracking, fit-out approval chain, amenity booking.** Design separately.

---

## 6. Testing requirements (ties into Plan 2)

Every phase adds tests in lockstep (CLAUDE.md convention): the state machine per workflow, terminal immutability, **routing-by-type**, per-type SLA, the type-conditional form fields (Livewire render + save), portal create per type, the new + aliased API endpoints (incl. cross-tenant 404), notification routing per type, CSAT flow, and a **migration/backfill regression** (old maintenance rows resolve + behave post-rename). Add a scenario test per new request type.

---

## 7. Risks, gotchas, open decisions

- **Morph/FQCN columns** (author_type, activity-log subject_type, notification data) — the #1 rename hazard. Morph-map or data-migrate.
- **RBAC rename** could lock roles out mid-migration — seed new perms + grant before flipping resource gating.
- **Mobile app break** — the `/me/maintenance-requests` alias is mandatory until the Flutter app adopts `/me/requests`.
- **Don't over-engineer workflows** — one `standard` machine covers almost everything; add per-type machines only when a type genuinely differs.
- **Open decisions:** enum vs DB `request_types` for v1 (recommend enum-first, table later); per-type vs single RBAC module (recommend single `requests.*` first); reference prefix (recommend per-type).

---

## 8. Concrete file-change checklist (Phase 1 rename)

- Models: `MaintenanceRequest`→`TenantRequest`, `MaintenanceRequestComment`→`TenantRequestComment` (+ `Tenant::maintenanceRequests()`→`requests()` with a back-compat alias).
- Migrations: rename tables + add `request_type_id`/`subcategory`/`csat_*`; morph-map or data-migrate `*_type` columns.
- Service: `MaintenanceRequestService`→`TenantRequestService` (+ `RequestWorkflows` registry for TRANSITIONS).
- Filament: `Admin/Resources/MaintenanceRequests/**`→`…/TenantRequests/**`; `Portal/Resources/MaintenanceRequests/**`→`…/Requests/**`.
- API: `Controllers/Api/V1/Maintenance/**` + `Actions/Api/V1/Maintenance/**` + resources → generic `Requests/**`; add aliases.
- Notifications: the four `Maintenance*Notification` → `Request*Notification`.
- Commands: `maintenance:scan-sla-breaches`/`maintenance:auto-close` → `requests:*` (update `routes/console.php` + the runbook + `CLAUDE.md`).
- Settings: `MaintenanceSettings` → `RequestSettings` (per-type SLA).
- Seeders: `RolesPermissionsSeeder` (`maintenance.*`→`requests.*`), `DemoSeeder` (seed types + sample requests).
- Translations: `lang/{en,ar}/admin.php` (`admin.requests.*`, type names, statuses, notification copy).
- Docs: rename `docs/modules/11-maintenance.md`→`11-tenant-requests.md`; update `docs/OVERVIEW.md`, `docs/api/MOBILE-API.md`, `MOBILE-APP-BRIEF.md`, `CLAUDE.md` (commands list).
- Tests: update the ~15 maintenance test files + add the migration/backfill regression.
