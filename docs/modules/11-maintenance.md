# Tenant Requests (incl. Maintenance)

> System for managing **tenant requests** — maintenance *and* every other thing a tenant may ask for (complaint, inquiry, access/security, billing query, document request, …) — routing to the right department, tracking SLA compliance, and achieving resolution within scheduled work windows and target deadlines.

> **Generalisation status (Plan 1) — COMPLETE as of 2026-08-15.** This module began life as
> "Maintenance Requests" and is now a typed **Tenant Request** system throughout — see
> [docs/plans/01-tenant-requests-plan.md](../plans/01-tenant-requests-plan.md). The rename landed in
> three passes: the `request_type` discriminator and type-aware create paths first
> (`App\Enums\TenantRequestType` — 8 types, each with its own sub-categories, SLA, routing,
> scheduling and reference prefix); then the model, table and service on `2026_06_29_000001`; and
> finally, on `2026_08_15_140000`, the four identifiers that had been left behind:
>
> | was | is |
> |---|---|
> | `maintenance.*` permissions | **`requests.*`** (`TenantRequestResource::permissionModule()`) |
> | `Modules::enabled('maintenance')` | **`Modules::enabled('requests')`** |
> | `MaintenanceSettings` (group `maintenance`) | **`SlaSettings`** (group `sla`) — shared with module 26, which is why it is named for neither |
> | `config/maintenance.php` | **`config/sla.php`** + **`config/requests.php`** — it held two unrelated things |
>
> Also renamed: the `maintenanceRequests()` relation on Tenant/Lease/Unit/Vendor is now
> `tenantRequests()`, `PortalMaintenanceSubmittedNotification` is `PortalRequestSubmittedNotification`,
> and the demo login `maintenance@mall.test` is `operations@mall.test`.
>
> ⚠️ **Reading this doc:** wherever the text below says `MaintenanceRequest`, read `TenantRequest`.
> No class, permission, setting or config key in this module carries the word "maintenance" any
> more. It survives in exactly one place here and correctly so — `TenantRequestType::Maintenance`,
> the request *type*, because a maintenance request is a kind of tenant request.


### Evidence before resolution (FR-USR-06)

A request cannot be marked **resolved** without evidence the work happened — either **an uploaded
image** (the `attachments` media collection) **or a linked work order** (the module 11 → 26 link).
Both are proof; either satisfies.

- Enforced in `TenantRequestService::transition()`, the single gate for **admin + portal + mobile
  API** — a rule enforced in one UI is a rule the other channels skip.
- On **resolving**, not closing: resolving is the act of saying "done"; closing is the
  administrative follow-up, and a resolved request already cleared the gate.
- The linked work order's *status* is irrelevant — that facility work exists on record is the
  evidence, whether the job is open or done.

> **The work-order side of FR-USR-06 is deferred, on purpose.** The FR reads "a request/work order",
> but a work order already gates completion on its **checklist** (FR-PPM-07), and "a linked work
> order" cannot evidence a work order completing itself. So requiring a photo on *every* work-order
> completion — including routine PPM sweeps — is a real operator decision, not an obvious one. It is
> **question E.4** in [OPEN-QUESTIONS.md](../OPEN-QUESTIONS.md); the request side, which is
> unambiguous and commercially meaningful, ships now.


## 1. Purpose & business context

The module handles the full lifecycle of a tenant request. Tenants (via the portal or the mobile app) or admin staff create requests across the mall's units. The system **types** each request (maintenance, complaint, inquiry, access, billing, document, other), auto-routes it to that type's default department, tracks progress through a state machine (submitted → acknowledged → in_progress → resolved → closed), enforces SLA targets by priority **for the types that have an SLA** (maintenance/complaint/access; inquiry/billing/document carry none), scans for breaches daily, and prevents changes to terminal (closed/cancelled) requests. A scheduled work window (scheduled_from/to) is independent of the SLA deadline (target_resolution_at) — the work may happen weeks after the deadline is set, e.g. for planned preventative maintenance.

### 1a. Request types (`App\Enums\TenantRequestType`)

Each type carries its own intake config (model-level, not a DB enum — `request_type` is a string, so adding a type needs no migration). Phase 2 promotes this enum to an operator-tunable `request_types` table; the accessors mirror the planned columns.

| Type | Sub-categories | SLA | Routes to | Ref prefix |
|------|----------------|-----|-----------|-----------|
| maintenance | electrical, plumbing, hvac, structural, cleaning, safety, other | yes (operator-tunable via MaintenanceSettings) | Operations | `MR` |
| complaint | noise, cleanliness, conduct, other | yes (code map) | Operations | `CR` |
| access | keys_cards, parking, after_hours, visitor, delivery | yes (code map) | Operations | `AR` |
| document | lease_copy, renewal, termination_notice, noc_certificate | no | Leasing | `DR` |
| permit | fit_out, temporary_installation, signage, other | no | Operations | `PM` |
| billing | — | no | Accounting | `BQ` |
| inquiry | — | no | (unassigned — triage) | `IQ` |
| other | — | no | (unassigned — triage) | `REQ` |

**Permits (FR-REQ-13 / FR-REQ-14).** A permit is simply a request of the `permit` type — for fit-out
work or a temporary installation — that carries a **validity window** (`valid_from`/`valid_to`). It
captures the FRD's four fields with columns the module already had: tenant name = `tenant_id`/caller,
description of the item/work = `description`, request date = `submitted_at`, plus the new validity
window. There is **NO approval / grant / reject step** — the FRD asks only for a form that captures
these fields, so a permit is a typed request with a validity window and nothing more.

## 2. Domain model

**MaintenanceRequest** (primary model)

| Column | Type | Constraints | Meaning |
|--------|------|-----------|---------|
| id | bigint | PK | Auto-increment ID |
| reference | string | UNIQUE | Human-readable code: `{prefix}-{asset_code}-{year}-{seq}`, e.g. `MR-AW-2026-0001`. Prefix is per request type (MR/CR/IQ/AR/BQ/DR/REQ). |
| tenant_id | bigint | FK→Tenant, **nullable** | Who submitted or is affected. Null **only** for a staff-channel intake from an unregistered caller (see `caller_*`) — enforced in `TenantRequest::booted`. |
| caller_name | string | nullable | FR-REQ intake — who reported it when they are not a registered tenant. **Required when `tenant_id` is null.** |
| caller_phone | string | nullable, indexed | The caller's contact number (for a walk-in / phone report). |
| caller_notes | text | nullable | Free-text intake notes about the caller / report. |
| unit_id | bigint | FK→Unit | Which unit (storefront/space) the request concerns |
| lease_id | bigint | FK→Lease, nullable | The lease in effect (may be null if no active lease) |
| request_type | string | NOT NULL, default `maintenance`, indexed | The request type (`TenantRequestType`). Backfilled to `maintenance` for all legacy rows. |
| assigned_to | bigint | FK→User, nullable | Internal staff member responsible (staff role) |
| assigned_to_vendor_id | bigint | FK→Vendor, nullable | External vendor contractor assigned (coexists with assigned_to) |
| department_id | bigint | FK→Department, nullable | Which operator department owns triage/execution. Auto-set from the type's default department on intake; still re-routable. |
| area_id | bigint | FK→Area, nullable, `nullOnDelete` | Which facility **zone** (module 30) the request sits in. **Inherited from the unit** on intake (`unit.area_id`) unless set explicitly — derived in `TenantRequest::creating`, so admin + portal + API all inherit it. Drives the supervisor fan-out (see §7) **and FR-REQ-08 auto-assignment**: if the zone has EXACTLY ONE supervisor (the unambiguous "designated supervisor"), the request is auto-assigned to them in `creating`; a multi-supervisor zone stays unassigned (all notified; a coordinator assigns — FR-REQ-07), and an explicit `assigned_to` is never overridden. Retiring a zone nulls the link, never strands the request. |
| status | enum | One of STATUSES | Current lifecycle state (see §4) |
| priority | enum | low, medium, high, urgent | SLA tier; drives target_resolution_at calculation (for types with an SLA) |
| category | string | nullable | The type's **sub-category** (electrical, parking, lease_copy, …). Was a maintenance-only DB enum; now a free-form string whose valid values come from `TenantRequestType::subcategories()`. Null for types with none. |
| channel | enum | portal, whatsapp, phone, email, walk_in, admin | Intake method; defaults to portal if tenant-submitted |
| title | string | Max 150 chars | Brief description of issue |
| description | text | Max 2000 chars (portal) | Full problem statement |
| resolution_notes | text | nullable | How the issue was fixed (filled on→resolved or closed) |
| submitted_at | timestamp | NOT NULL | When the request was created |
| acknowledged_at | timestamp | nullable | When staff first acknowledged receipt (→acknowledged) |
| resolved_at | timestamp | nullable | When staff declared the work complete (→resolved) |
| closed_at | timestamp | nullable | When automatically closed by auto-close job (→closed) |
| target_resolution_at | timestamp | nullable | SLA deadline calculated on create; passed to scan-sla-breaches |
| scheduled_from | timestamp | nullable | Planned start of actual work window (decoupled from SLA target) |
| scheduled_to | timestamp | nullable | Planned end of work window (must be ≥ scheduled_from if both set) |
| valid_from | date | nullable | Permit validity window start (FR-REQ-14). Null for non-permit requests. |
| valid_to | date | nullable | Permit validity window end (FR-REQ-14). Must be ≥ valid_from if both set (enforced in `TenantRequest::booted`). Null for non-permit requests. |
| sla_breach_notified_at | timestamp | nullable | Stamped by scan-sla-breaches after firing alert (idempotency guard) |
| csat_rating | tinyint | nullable | Close-out satisfaction score (1–5). Captured from the tenant once the request is resolved/closed — via the portal "Rate" action or `POST /me/requests/{id}/rate`. Recorded by `MaintenanceRequestService::rate()` (resolved/closed guard, clamps 1–5, overwritable). Shown as a toggleable admin column. |
| csat_comment | text | nullable | Optional free-text feedback accompanying the CSAT score |
| created_at | timestamp | - | Record creation |
| updated_at | timestamp | - | Last update |
| deleted_at | timestamp | nullable | Soft-delete timestamp |

**Relationships**
- `tenant()`: BelongsTo Tenant
- `unit()`: BelongsTo Unit
- `lease()`: BelongsTo Lease (nullable)
- `assignee()`: BelongsTo User (assigned_to)
- `assignedVendor()`: BelongsTo Vendor (assigned_to_vendor_id, nullable)
- `department()`: BelongsTo Department (nullable)
- `comments()`: HasMany MaintenanceRequestComment, ordered by created_at

**MaintenanceRequestComment** (audit trail)

| Column | Type | Meaning |
|--------|------|---------|
| id | bigint | PK |
| maintenance_request_id | bigint | FK→MaintenanceRequest |
| author_type | string | Morph type: "App\Models\Tenant" or "App\Models\User" |
| author_id | bigint | FK to author (tenant_id or user_id) |
| body | text | Comment text |
| is_internal | boolean | If true, hidden from tenant (staff-only note) |
| created_at | timestamp | - |
| updated_at | timestamp | - |

## 3. Business rules & invariants

**Caller intake (FR-REQ, Phase 9a).** A request usually carries its `tenant_id`. A **staff channel**
(anything but `portal`) may instead log a call from an **unregistered caller**: `tenant_id` is left
null and `caller_name` (plus optional `caller_phone`/`caller_notes`) records who reported it. The
invariant is enforced in `TenantRequest::booted` — so admin, portal and API all inherit it:
- `tenant_id` null **requires** a `caller_name` (a request must say who reported it), and
- a `portal` request can **never** be tenant-less (the portal always acts as a known, authenticated
  tenant — `SELF_SERVICE_CHANNEL`).

`unit_id` stays required: a request is still about a unit. Unit-less common-area work is a **work
order** (module 26), which carries its own `asset_id`.

**SLA Targets** (config/maintenance.php + MaintenanceSettings):
- urgent: 4 hours (default config: 24h; Settings SLA: 4h)
- high: 24 hours (default config: 72h; Settings SLA: 24h)
- medium: 72 hours (default config: 168h = 7d; Settings SLA: 72h)
- low: 168 hours (default config: 336h = 14d; Settings SLA: 168h)

On `create()`, the service calls `defaultTargetResolution($priority)` to compute the target: reads from MaintenanceSettings first (via app()), then falls back to config/maintenance.php. If Settings fails to load, uses config only (guards against missing rows in minimal test envs).

**Formulas (verbatim from code)**:
- `target_resolution_at = now() + (priority_hours from settings or config)`
- `isOpen()` returns true if `status in ['submitted', 'acknowledged', 'in_progress', 'awaiting_tenant']`
- `isOverdue()` returns true if `isOpen() && target_resolution_at && target_resolution_at.isPast()`
- `isTerminal()` returns true if `status in ['closed', 'cancelled']`

**Immutability of terminal requests** (FR REQ-3):
- A request with status='closed' or 'cancelled' cannot transition to any other state (no successors in TRANSITIONS matrix).
- `MaintenanceRequestResource.canEdit()` returns false for terminal records, hiding Edit, Assign, and Redirect actions in the UI.
- `MaintenanceRequestService.assign()` and `redirectToDepartment()` both guard with `isTerminal()` — if true, they return the record unchanged without modifying it.
- No API or service path can re-route a terminal work-order.

**Department redirect** (FR MNT-2/3):
- A request can sit unassigned (department_id = null) until triaged.
- Redirecting to a different department updates department_id only; the activity log records the from→to change.
- A work-order is not "reassigned" via a full status change — only the department column changes.

**Scheduled work window** (FR REQ-1):
- `scheduled_from` and `scheduled_to` are independent of `target_resolution_at`.
- A request may have a SLA deadline in 2 days but scheduled work in 3 weeks (e.g. preventative maintenance).
- Both are nullable; neither is required.
- Form validation ensures `scheduled_from ≤ scheduled_to` if both are set (not hardcoded in model, but form rule).

**Permit validity window** (FR-REQ-13 / FR-REQ-14):
- A **permit** is a request of the `permit` type carrying a validity window: `valid_from`/`valid_to` (date columns).
- The permit form (`request_type === 'permit'`) shows + **requires** both dates; they are hidden for every other type. The FRD's other three fields reuse existing columns (tenant = `tenant_id`/caller, item/work = `description`, request date = `submitted_at`).
- The **model** enforces only the **ordering** invariant in `TenantRequest::booted`: if both dates are set, `valid_to` must be ≥ `valid_from` (else a `DomainException`). It does **not** hard-require the dates, so non-permit rows and partial data never blow up — the form owns the "required for a permit" rule; `->afterOrEqual('valid_from')` gives inline validation so the model guard is a backstop, not a 500.
- There is **NO approval / grant / reject lifecycle** for permits (FR-REQ-13/14 ask only for a capture form). A permit flows through the same request state machine as any other request — no amount-based `ApprovalPolicy`, no approve step.

**Reference generation**:
- Pattern: `MR-{asset_code}-{year}-{seq}`.
- Sequence counts all MaintenanceRequest rows (including soft-deleted) for that year.
- Called by `create()` and defaulted in the admin form.

**Auto-close window** (config/maintenance.auto_close_after_days):
- Default: 7 days.
- Candidates: `status='resolved' && resolved_at ≤ now() - {days}`.
- Closed via console command `maintenance:auto-close` (not automatic).

## 4. Lifecycle / state machine

**Status enum** (MaintenanceRequest::STATUSES):
`['submitted', 'acknowledged', 'in_progress', 'awaiting_tenant', 'resolved', 'closed', 'cancelled']`

**Open statuses** (OPEN_STATUSES):
`['submitted', 'acknowledged', 'in_progress', 'awaiting_tenant']`

**Legal transitions** (MaintenanceRequestService::TRANSITIONS):
```
submitted        → acknowledged, in_progress, cancelled
acknowledged     → in_progress, awaiting_tenant, cancelled
in_progress      → awaiting_tenant, resolved, cancelled
awaiting_tenant  → in_progress, resolved, cancelled
resolved         → closed, in_progress (re-open)
closed           → (terminal, no successors)
cancelled        → (terminal, no successors)
```

**Terminal states**:
- `closed`: Final state after work is done and auto-close window expires (or manual close).
- `cancelled`: Request rejected/withdrawn before resolution. Cannot cancel from `resolved` or `closed`.

**Timestamps populated on transition**:
- `acknowledged_at` when → acknowledged
- `resolved_at` when → resolved (resolution_notes also set from extra array)
- `closed_at` when → closed

**Awaiting tenant detour**:
- From `in_progress`, staff can send it to `awaiting_tenant` (e.g. waiting for tenant access).
- From `awaiting_tenant`, tenant or staff can move it back to `in_progress`.
- Cannot resolve directly from `awaiting_tenant` without first going through `in_progress`.

**Cannot cancel from terminal states**:
- A `resolved` request cannot go to `cancelled`; must close or reopen it.
- This prevents "rejecting" after work has already been done.

## 5. Services, jobs & scheduled commands

**MaintenanceRequestService**

*`create(array $data, Tenant $tenant): MaintenanceRequest`*
- Wraps in DB transaction.
- Generates reference via `MaintenanceRequest::generateReference($assetCode)`.
- Derives unit and lease from tenant's active leases (or uses explicit data keys).
- Sets priority (default 'medium'), category (default 'other'), status='submitted', submitted_at=now().
- Computes target_resolution_at via `defaultTargetResolution($priority)`.
- Fires `notifyOperators()` to send a database notification to managers + operations staff assigned to the unit's asset (wrapped in Throwable; never breaks the write).
- Returns the saved request.

*`transition(MaintenanceRequest $request, string $next, array $extra=[]): MaintenanceRequest`*
- Validates next status against TRANSITIONS[$current].
- Throws InvalidArgumentException if illegal hop.
- Merges payload: status, and on →acknowledged/resolved/closed, sets the corresponding timestamp.
- If extra['resolution_notes'] is passed, uses it; else keeps existing notes.
- If extra['assigned_to'] is in extra, updates assigned_to.
- Calls `$request->update($payload)`.
- Notifies the tenant (via notifyPortal + MaintenanceStatusChangedNotification) unless the transition is 'cancelled' (tenant's own cancellation).
- Returns `$request->refresh()`.

*`assign(MaintenanceRequest $request, ?int $userId): MaintenanceRequest`*
- Guards: if `$request->isTerminal()`, returns unchanged.
- Sets assigned_to = $userId.
- If userId is not null and status='submitted', auto-transitions to 'acknowledged'.
- Returns refreshed request.

*`redirectToDepartment(MaintenanceRequest $request, ?int $departmentId): MaintenanceRequest`*
- Guards: if `$request->isTerminal()`, returns unchanged.
- Sets department_id = $departmentId (may be null to unassign).
- Activity log records the change.
- Returns refreshed request.

*`comment(MaintenanceRequest $request, Model $author, string $body, bool $isInternal=false): MaintenanceRequestComment`*
- Creates a MaintenanceRequestComment with author (User or Tenant, polymorphic).
- If $isInternal, the comment is hidden from the other party.
- If !$isInternal, calls `notifyOfComment()`:
  - If author is Tenant: notifies staff (managers + operations) via database notification.
  - If author is User: notifies the requesting tenant via notifyPortal (mail + database).
- All notification wrapping is Throwable-guarded.
- Returns the saved comment.

*`defaultTargetResolution(string $priority): Carbon`*
- Reads from MaintenanceSettings via app().
- On Throwable (missing settings rows), falls back to config/maintenance.php sla.{priority}.resolve_hours.
- Returns now()->addHours((int) $hours).

---

**ScanMaintenanceSlaBreachesCommand** (artisan maintenance:scan-sla-breaches)

*Signature*: `maintenance:scan-sla-breaches {--dry-run}`

*What it does*:
- Finds all open requests (OPEN_STATUSES) with target_resolution_at in the past and sla_breach_notified_at = null.
- For each:
  - Locks the row (SELECT FOR UPDATE) inside a transaction.
  - Re-checks sla_breach_notified_at (in case a concurrent scan beat us).
  - If still null:
    - Gathers recipients: managers + operations staff for the unit's asset, + all asset owners (FR MNT-5).
    - Dedupes by id.
    - Fires MaintenanceSlaBreachedNotification (database channel only, bell icon, persistent).
    - Sets sla_breach_notified_at = now() via forceFill + save.
  - Catches Throwable and logs warning (never stops the scan).
- Prints summary: "Alerted on X of Y breach(es)."

*Idempotency*:
- The WHERE sla_breach_notified_at IS NULL clause ensures a breached request is only alerted once.
- Re-running the command skips already-alerted requests.
- Lock + re-check inside the transaction prevents duplicate notifications if two scans run concurrently.

*Dry-run mode* (--dry-run):
- Lists what would be alerted without writing sla_breach_notified_at.

---

**AutoCloseMaintenanceRequestsCommand** (artisan maintenance:auto-close)

*Signature*: `maintenance:auto-close {--days=} {--dry-run}`

*What it does*:
- Reads --days or falls back to config(maintenance.auto_close_after_days, 7).
- Finds all resolved requests (status='resolved') with resolved_at ≤ now() - {days}.
- For each, calls `$service->transition($request, 'closed')`.
- Prints summary and lists any failures (caught Throwable).

*Dry-run mode* (--dry-run):
- Lists candidates without transitioning.

## 6. Filament resources & key fields

**Admin MaintenanceRequestResource** (/app/Filament/Admin/Resources/MaintenanceRequests/)

*Scoping*:
- Uses ScopesViaProperty trait.
- Scopes via unit→asset_id (TenantScope).
- RBAC gated by permission module 'maintenance'.

*canEdit() override*:
- Returns false if record.isTerminal(), overriding roleGatedCanEdit.
- Hides Edit, Assign, Redirect actions for closed/cancelled requests.

*Navigation*:
- Icon: Heroicon::OutlinedWrenchScrewdriver.
- Badge: count of OPEN_STATUSES requests (respects tenant scope; ALL pseudo-asset bypasses).
- Badge color: 'danger' if urgent request exists, else 'warning'.

*Form fields* (MaintenanceRequestForm):
- **Section 1: Request**
  - reference: TextInput, disabled, dehydrated (auto-generated).
  - tenant_id: Select, searchable/preload, required (scoped to TenantScope).
  - unit_id: Select, searchable, required (options from Unit with asset filter).
  - priority: Select from enum, default 'medium', required, native(false).
  - category: Select from enum, default 'other', required, native(false).
  - channel: Select from enum, default 'portal', required, native(false), helper text.
  - status: Select from enum, default 'submitted', required, native(false).

- **Section 2: Details**
  - title: TextInput, max 150 chars, required.
  - description: Textarea, max no limit in admin (2000 in portal), required, 4 rows.

- **Section 3: Assignment**
  - department_id: Select (Department::selectableOptions()), searchable, placeholder 'Unassigned'.
  - assigned_to: Select (User by name), searchable, placeholder 'Unassigned'.
  - assigned_to_vendor_id: Relationship select to active Vendors, searchable, preload, placeholder '—'.
  - target_resolution_at: DateTimePicker, native(false), seconds(false), minDate floor at record.created_at or today().
    - Validation: "after_or_equal" → __('admin.validation.maintenance_resolution_after_creation').
  - scheduled_from: DateTimePicker, native(false), seconds(false), no validation.
  - scheduled_to: DateTimePicker, native(false), seconds(false), no validation.

- **Section 4: Resolution** (collapsed/collapsible)
  - resolution_notes: Textarea, 3 rows.

- **Section 5: Attachments** (Spatie MediaLibrary)
  - Accepts image/*, application/pdf only.
  - Max 10 MB per file.
  - Multiple, reorderable, downloadable, openable, preserves filenames.

*Table columns* (MaintenanceRequestsTable):
- reference, title, tenant.name, unit.code, category, channel, priority, status, department.name, assignee.name, assignedVendor.name, submitted_at, target_resolution_at (red if overdue).

*Filters*:
- status, priority, category, channel, department_id, assigned_to.
- "Open only" (default: filters to OPEN_STATUSES).
- "SLA breached" (OPEN_STATUSES && target_resolution_at < now).
- Trashed (soft-deleted).

*Row actions*:
- Edit: visible if canEdit() && not terminal.
- Change Status: modal; selects next status from TRANSITIONS, shows resolution_notes field if → resolved.
- Assign: modal; selects assignee; calls assign().
- Redirect: modal; selects department; calls redirectToDepartment().

*Bulk actions*:
- Delete, ForceDelete, Restore (standard soft-delete actions).
- canDeleteAny() is false for non-super_admin.

---

**Portal MaintenanceRequestResource** (/app/Filament/Portal/Resources/MaintenanceRequests/)

*Scoping*:
- Query filtered to tenant_id = Portal::tenantId().
- Tenant-admin only (Portal::isAdmin()) can create; others read-only.
- No edit, delete (both return false).

*Pages*:
- index (list)
- create (tenant-admin only)
- view (detail, read-only)

*Form* (Portal):
- title, category, priority (no status/assigned_to/department).
- unit_id: pre-populated from tenant's active lease; multi-select if multiple leases.
- description, attachments (image/pdf only, max 5 files).

*Comments* (PortalMaintenanceCommentsRelationManager):
- Tenant can add public comments (visible to staff).
- Staff responds (visible to tenant).

## 7. Notifications & integrations

**PortalMaintenanceSubmittedNotification**
- Triggered on create().
- Sent via database channel (bell entry, no email).
- Recipients: managers + operations staff assigned to unit's asset, + super_admin.
- Wrapped in Throwable (never breaks create).

**MaintenanceStatusChangedNotification**
- Triggered on transition() (except cancelled).
- Channels: mail + database.
- Recipient: the requesting tenant (via notifyPortal).
- Mail subject: "Maintenance Status Changed: {reference} → {status}".
- Mail body: includes resolution_notes if transitioning to resolved/closed.

**MaintenanceCommentAddedNotification**
- Triggered on comment() if $isInternal = false.
- Channels: mail + database.
- If author is Tenant: sent to managers + operations.
- If author is User: sent to tenant.
- Wrapped in Throwable (never breaks comment write).

**MaintenanceSlaBreachedNotification**
- Triggered by maintenance:scan-sla-breaches.
- Channel: database only (bell icon, persistent).
- Recipients: managers + operations + asset owners.
- Payload: priority, reference, hours_over_sla, icon='heroicon-o-clock', color='danger'.

**AreaRequestRaisedNotification** (area routing, module 30 → 11)
- Triggered on **request creation** via the `TenantRequest::created` model event — the single
  hook every create path (admin Filament, portal, mobile API) passes through — dispatched by
  `NotifyAreaSupervisorsService`.
- Channels: **database + push, no mail** (a bell/app signal, matching the SLA-breach choice).
  Push is a no-op for admin Users today (they register no device tokens) but is declared so the
  routing reaches the mobile app the moment supervisors become push-capable.
- Recipients: the request's zone **supervisors** (`Area::supervisors`). **Notify, not assign** —
  assignment stays the coordinator's job.
- Runs **alongside** department routing, not instead of it: the department fan-out
  (`notifyOperators`) and the zone fan-out both fire. No zone / no supervisors ⇒ safe no-op;
  failures are contained so a bad recipient never breaks request creation.

## 8. Extension points — how to change/extend SAFELY

**To add a new priority tier**:
1. Add to MaintenanceRequest::PRIORITIES const.
2. Add to MaintenanceRequest::CATEGORIES const if a category.
3. Add translation key 'admin.enums.maintenance_priority.{new}' in lang/.
4. Add SLA hours to MaintenanceSettings: `public int $sla_{new}_hours = {value}`;
5. Update config/maintenance.php sla.{new}.resolve_hours fallback.
6. Add form option in MaintenanceRequestForm (automatic via __('admin.enums.maintenance_priority')).
7. Add test in MaintenanceScenarioTest covering new tier.

**To add a new status**:
1. Add to MaintenanceRequest::STATUSES.
2. Update TRANSITIONS in MaintenanceRequestService to define legal successors.
3. Add to OPEN_STATUSES if it represents an active work state.
4. Add translation key 'admin.statuses.maintenance_request.{new}'.
5. Add table column color mapping in MaintenanceRequestsTable.
6. Add test in MaintenanceScenarioTest covering transition paths involving new status.
7. If it's terminal-like, update isTerminal() guard logic.

**To add a new channel**:
1. Add to MaintenanceRequest::CHANNELS enum.
2. Add translation key 'admin.enums.maintenance_channel.{new}'.
3. Portal form defaults to 'portal'; admin form can select others (walk_in, phone, etc.).

**To send a new notification type**:
1. Create Notification class extending Illuminate\Notifications\Notification.
2. Via: ['database'] or ['mail', 'database'].
3. From the service (create, transition, comment), wrap Notification::send() in Throwable.
4. Log the error; never throw.

**To change SLA computation**:
1. Edit defaultTargetResolution() in MaintenanceRequestService.
2. Or adjust MaintenanceSettings values via /admin/settings → Maintenance.
3. Do NOT modify the column name target_resolution_at (many queries filter on it).

**To auto-close faster/slower**:
1. Edit config/maintenance.auto_close_after_days (deploy-time) or pass --days=5 to console command.
2. Schedule the command via Laravel's scheduler (not currently done; must wire via artisan schedule:run).

**To restrict department reassignment**:
1. Override canEdit() in MaintenanceRequestResource to add a department-based check.
2. Or wrap redirectToDepartment() with additional guards.

**To change immutability of terminal states**:
1. **DO NOT** — the constraint is a design requirement (FR REQ-3).
2. If a closed request must be re-opened, add a new status or explicitly document the exception.

**Terminal immutability is now a MODEL guard, not just `canEdit()` UI (pre-go-live sweep, 2026-07-31).**
`canEdit()` hides the Edit button for a terminal request, but that only gates the UI — the generic
admin Edit page's save path was still reachable (mountAction / crafted request), so a closed/cancelled
request's descriptive + routing fields could be rewritten off-form. `TenantRequest::booted()` now has a
`static::updating` guard (mirrors Lease/Invoice) that freezes them, keyed on the **original** status so
the transition INTO closed is allowed; **post-close CSAT** (`csat_rating`/`csat_comment` via `rate()`) and
soft-delete/restore stay allowed. Pinned by `TenantRequestTerminalImmutableTest`.

**To add media attachments to comments**:
1. Add HasMedia trait to MaintenanceRequestComment model.
2. Add Spatie file upload to comments relation manager.

## 8a. Action authorization — double-gated (2026-07-26)

The request write actions **changeStatus / assign / redirect** on `TenantRequestsTable` gated their
permission + terminal check **only in `visible()`** (via `TenantRequestResource::canEdit`), unlike
`raise_work_order` beside them (already triple-gated) and modules 08/09, which re-assert in both
`visible()` and `action()`. Brought into compliance with the project's double-gate invariant: the
same `canEdit` predicate now re-asserts in `->authorize()` **and** `abort_unless(...)` inside
`action()`, so they can't drift and a read-only viewer/owner (holds `maintenance.view`, not `.edit`)
can never re-status, reassign or reroute a request. Guarded by `TenantRequestActionAuthzTest`.

> **Filament-version note (empirically verified).** In the *installed* Filament version,
> `mountAction()`/`TestAction` **does** respect `visible()` — a visible-only action was already
> blocked from the `mountAction + callMountedAction` vector (proven by reverting the gate here **and**
> on `CamActionAuthzTest` and watching both still pass). So the codebase's older "**`visible()` is not
> a dispatch gate**" premise no longer holds for that vector. The `action()` gate is retained as
> **defense-in-depth + invariant compliance** (robust to a Filament upgrade, a `visible()` refactor,
> or a raw crafted HTTP request the test helper doesn't exercise) — it is **not** closing an exploit
> reproducible via `mountAction` today. Worth a wider team decision on whether the invariant's
> rationale should be updated.

## 9. Gotchas, edge cases & recently-fixed bugs

**SLA scan lock & re-check** (prevents duplicate alerts):
- The scan uses DB::transaction + lockForUpdate to re-check sla_breach_notified_at inside the transaction.
- If two scans run in parallel, the second skips already-stamped requests.
- Without the lock, both might fire a notification.

**Cancel does not notify tenant**:
- If a tenant cancels their own request (from submitted/acknowledged), they don't get a MaintenanceStatusChangedNotification.
- This is intentional (line 144-145 of MaintenanceRequestService): `if ($next !== 'cancelled')`.
- If staff cancels a request, the tenant also doesn't get notified (same guard).

**Resolved → closed — manual OR automatic** *(corrected 2026-07-26; the old text below was stale)*:
- The **Change Status** action builds its options from `TRANSITIONS[$status]`; for a `resolved`
  request that is `['closed', 'in_progress']`, so staff **can** close (or re-open) on the spot.
- `maintenance:auto-close` also closes a resolved request once the auto-close window expires — so
  a resolved ticket left alone still closes itself.

**Tenant cannot re-open a resolved request**:
- Portal UI is read-only for tenants (canEdit = false).
- Only staff can transition resolved → in_progress via the Redirect/Status actions.

**scheduled_from/to are decoupled from target_resolution_at**:
- Validation does NOT enforce scheduled_to ≥ target_resolution_at.
- A work order can have a SLA deadline 2 days away but scheduled work in 3 weeks.
- Form validation only checks scheduled_from ≤ scheduled_to (not hardcoded; may be missing).

**Reference is unique but not re-used on soft-delete**:
- If a request is soft-deleted, its reference remains in the DB.
- A new request the same year will have a higher sequence number.
- Example: MR-HW-2026-0001 deleted, MR-HW-2026-0002 next.

**Activity log logs only these columns** (logOnly):
- status, priority, category, assigned_to, assigned_to_vendor_id, department_id, target_resolution_at, resolution_notes.
- Other changes (title, description) are not audited in the activity log.

**Vendor assignment coexists with staff assignment**:
- assigned_to (User) and assigned_to_vendor_id (Vendor) are both nullable and independent.
- A request can be assigned to both a staff member and a vendor simultaneously.
- The UI allows both selects; the service doesn't prevent either.

**Department redirect is NOT an automatic assignment**:
- Changing department_id does not auto-assign a user or vendor.
- The department is for routing/triage; the actual worker is assigned via assigned_to or assigned_to_vendor_id.

**Staff recipients for notifications use AssetStaffRecipients**:
- Scopes to the unit's asset.
- In multi-property deployments, staff from other properties won't be notified.
- Super_admin is always included (platform-wide visibility).

**Notifications wrapped in Throwable**:
- If mail fails, the log is written but the request/comment/transition succeeds.
- Missing role catalogue (e.g. minimal test env) is silently skipped.
- This ensures a mail/SMTP hiccup never breaks user workflows.

**Resolution notes are optional on create, required on resolve**:
- A request can be submitted with no resolution_notes.
- When transitioning to resolved, resolution_notes from extra['resolution_notes'] is used, or the existing value is kept.
- A full resolution_notes wipe (empty string) is possible if passed in extra.

**Target resolution date cannot predate request creation**:
- Form validation (minDate) enforces target_resolution_at ≥ created_at (start of day on create, start of record creation on edit).
- Prevents data entry error where deadline is in the past.

**Auto-close only handles resolved status**:
- Candidates: `status='resolved' && resolved_at ≤ now() - days`.
- Cancelled requests are NOT automatically closed; they stay cancelled.
- Awaiting_tenant requests older than days are also not closed (must be resolved first).

**Comments are ordered by created_at**:
- The comments() relationship uses orderBy('created_at'), so newest are last.
- No built-in pagination for comments; all are eager-loaded (risk on high-volume requests).

## 10. Tests & related modules

**Tests** (critical coverage paths)

- **MaintenanceScenarioTest** (/tests/Feature/Scenarios/): Full lifecycle + RBAC matrix
  - Legal hops: submitted → acknowledged → in_progress → resolved → closed
  - Detours: in_progress ↔ awaiting_tenant
  - Re-open: resolved → in_progress
  - Cancel from each open state
  - Illegal hops rejection (e.g. submitted → resolved skipped)
  - Terminal immutability (closed & cancelled have no successors)
  - canEdit gates Edit, Redirect, Assign for terminal
  - SLA scan boundaries: not-yet-due, no target, idempotency, owner alert
  - RBAC: operations can edit open, viewer read-only, manager can edit, super_admin can delete

- **MaintenanceImmutabilityTest** (/tests/Feature/): Terminal state enforcement
  - isTerminal() for closed/cancelled
  - canEdit returns false for terminal
  - Blocks super_admin from editing closed record

- **MaintenanceDepartmentTest**: Redirect logic
  - Assign to department
  - Redirect from one department to another
  - Clear department (null)
  - Activity log records the change

- **MaintenanceDateValidationTest**: Form validation
  - Rejects target_resolution_at earlier than request creation date
  - Accepts target_resolution_at in future

- **MaintenanceSlaOwnerAlertTest**: Breach notification to asset owners
  - Scans detect overdue open requests
  - Notifies managers + operations + owners
  - Idempotent (sla_breach_notified_at stamp prevents re-alert)

- **MaintenanceRequestTest** (/tests/Feature/Models/):
  - isOpen() recognizes OPEN_STATUSES
  - isOverdue() true if status open and target past
  - Reference generation

**Related modules**

- **Departments** (/docs/modules/14-departments.md): Operator team routing
- **Vendors** (/docs/modules/12-vendors.md): External contractor assignment
- **Users & Asset Staff** (/docs/modules/01-assets.md): Staff role assignments (manager, operations)
- **Tenants** (/docs/modules/02-tenants.md): Request submission, portal access
- **Units & Leases** (/docs/modules/04-leases.md): Physical locations, lease context
- **Notifications** (/docs/modules/19-notifications-scans.md): Framework for bell/mail
- **Activity Log**: Spatie audit trail (department_id changes recorded)

---

**Configuration files**
- `config/maintenance.php`: SLA hours by priority, auto-close days
- `app/Settings/MaintenanceSettings.php`: Operator-tunable SLA hours (read first, then config fallback)

**Database migrations**
1. 2026_05_16_233721: Create maintenance_requests + comments tables
2. 2026_05_24_132917: Add assigned_to_vendor_id FK to vendors
3. 2026_05_25_145447: Add channel enum
4. 2026_05_31_213931: Add sla_breach_notified_at timestamp
5. 2026_06_24_000002: Add department_id FK + index
6. 2026_06_24_000009: Add scheduled_from, scheduled_to datetimes

---

## Deletion policy

Operator decision 2026-07-31, following Yardi/MRI/Entrata: a record that carries history is
**refused**, not warned about — the damage lands on the reports and audit trail that referenced
it, none of which are in front of whoever clicks the button. The single register is
[`App\Support\DeletionPolicy`](../../app/Support/DeletionPolicy.php); `DeletionPolicyConformanceTest` fails the build if a model here ships unclassified or a Delete
button reappears on a money record.

| Model | Rule | Instead / why |
|---|---|---|
| `MaintenancePenalty` | **Never deletable** | waive or release the penalty — it feeds the vendor bill |
| `MaintenanceWorkOrder` | Deletable (super_admin) | operational: a job record |
