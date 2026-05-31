# Module 09 — Maintenance / CAFM

> Date: 2026-05-31
> Status: 🟡 Yellow — feature-complete; 1 inline fix (F-17 carryover, both methods); 3 Yellow extensibility (settings drift, notifications, auto-close).
> Surface: [MaintenanceRequest model](../../app/Models/MaintenanceRequest.php), [MaintenanceRequestComment model](../../app/Models/MaintenanceRequestComment.php), [Admin resource](../../app/Filament/Admin/Resources/MaintenanceRequests/), [Portal resource](../../app/Filament/Portal/Resources/MaintenanceRequests/), [Owner resource](../../app/Filament/Owner/Resources/MaintenanceRequests/), [MaintenanceRequestService](../../app/Services/MaintenanceRequestService.php), [MaintenanceSettings](../../app/Settings/MaintenanceSettings.php), [config/maintenance.php](../../config/maintenance.php), [OpenMaintenanceRequests widget](../../app/Filament/Admin/Widgets/OpenMaintenanceRequests.php).

## 1. Inventory

### 1.1 MaintenanceRequest model (130 LOC)

- Traits: `HasFactory`, `InteractsWithMedia`, `LogsActivity`, `SoftDeletes`. Implements `HasMedia`.
- `$fillable` (18): reference, tenant_id, unit_id, lease_id, assigned_to (User), assigned_to_vendor_id (Vendor), status, priority, category, channel, title, description, resolution_notes, 5 datetime cols (submitted_at, acknowledged_at, resolved_at, closed_at, target_resolution_at).
- Status enum (7): `submitted, acknowledged, in_progress, awaiting_tenant, resolved, closed, cancelled`.
- Priority enum (4): `low, medium, high, urgent`.
- Category enum (7): `electrical, plumbing, hvac, structural, cleaning, safety, other`.
- Channel enum (6, added 2026-05-25): `portal, whatsapp, phone, email, walk_in, admin`.
- **`OPEN_STATUSES` constant**: `['submitted', 'acknowledged', 'in_progress', 'awaiting_tenant']` — used by widget, badges, SLA filters.
- Relations: tenant, unit, lease (nullable), assignee (User), assignedVendor (Vendor), comments (HasMany, ordered).
- Computeds: `isOpen()`, `isOverdue()`, `generateReference($assetCode)` → `MR-{HW}-{YYYY}-{####}`.
- LogsActivity allowlist (7 fields).

### 1.2 MaintenanceRequestComment model (32 LOC)

- Fillable: `maintenance_request_id, author_type, author_id, body, is_internal`.
- `$casts`: `is_internal` → bool.
- Relations: `request()` BelongsTo, `author()` **MorphTo** — User OR Tenant can author. Clean polymorphism.

### 1.3 Migrations

- `2026_05_16_233721_create_maintenance_requests_table.php` — 18 cols, 5 indexes incl. `(status, priority)`, `(tenant_id, status)`, `(unit_id, status)`.
- `2026_05_16_233722_create_maintenance_request_comments_table.php` — polymorphic via `author_type` + `author_id` morphs; index `(maintenance_request_id, created_at)`.
- `2026_05_25_145447_add_channel_to_maintenance_requests.php` — channel enum.

### 1.4 Admin Resource

[MaintenanceRequestResource.php](../../app/Filament/Admin/Resources/MaintenanceRequests/MaintenanceRequestResource.php) (140 LOC). Traits: `RoleGatedActions`, `ScopesViaProperty` (`tenantScopeRelation()`=`unit`). Module gate: `'maintenance'`. Nav: WrenchScrewdriver icon, sort 5, group Operations.

| File | Notes |
|---|---|
| MaintenanceRequestForm | 5 sections: Identity (reference disabled + tenant/unit/priority/category/channel/status), Details (title + description), Assignment (assigned_to User + assigned_to_vendor_id Vendor + target_resolution_at), Resolution (resolution_notes, collapsible), Attachments (Spatie media, 5 files × 10MB). |
| MaintenanceRequestsTable | 11 columns incl. external vendor; **7 filters** incl. open_only (default on), sla_breached, channel. **Row actions**: Change Status modal (status select populated from `MaintenanceRequestService::TRANSITIONS`, resolution_notes required if status=resolved), Assign modal, Edit. Bulk: delete/restore/force. |
| Relations | MaintenanceCommentsRelationManager, ActivitiesRelationManager. |

### 1.5 Portal Resource

[MaintenanceRequestResource](../../app/Filament/Portal/Resources/MaintenanceRequests/MaintenanceRequestResource.php). Scope: `where('tenant_id', Auth::guard('portal')->id())`. Tenant CAN create + view + comment publicly; NO edit/delete. Form has 2 sections: Maintenance Request (title, category default 'other', priority default 'medium', unit_id from active leases, description) + Attachments. Comments relation lets tenants reply.

### 1.6 Owner Resource

Read-only. Scope: `whereHas('unit.asset.owners', fn($q) => $q->where('user_id', userId))`. Same columns as Admin.

### 1.7 Service — [MaintenanceRequestService.php](../../app/Services/MaintenanceRequestService.php) (118 LOC)

State machine via `TRANSITIONS` constant — explicit allowed transitions per state:

```
submitted        → acknowledged, in_progress, cancelled
acknowledged     → in_progress, awaiting_tenant, cancelled
in_progress      → awaiting_tenant, resolved, cancelled
awaiting_tenant  → in_progress, resolved, cancelled
resolved         → closed, in_progress
closed           → (terminal)
cancelled        → (terminal)
```

Methods:
- `create($data, Tenant)` — derives unit/lease, sets `status='submitted'`, `submitted_at=now()`, target via `defaultTargetResolution(priority)`.
- `transition($request, $next, $extra=[])` — validates legal transition, updates timestamps conditionally (`acknowledged_at` on first transition out of submitted, `resolved_at` when reaching resolved, `closed_at` when closing). Throws `InvalidArgumentException` on illegal.
- `assign($request, $userId)` — auto-transitions `submitted → acknowledged` if assignee added from submitted state.
- `comment($request, Model $author, $body, $isInternal=false)` — polymorphic comment, uses `$author->getMorphClass()`.
- `defaultTargetResolution($priority)` — reads `config("maintenance.sla.{$priority}.resolve_hours", 168)`. **Does NOT read from `MaintenanceSettings`** — see F-36.

### 1.8 Settings + Config split

| Source | What it has |
|---|---|
| [config/maintenance.php](../../config/maintenance.php) | `'sla' => ['urgent' => ['resolve_hours' => 24], 'high' => ['resolve_hours' => 72], 'medium' => ['resolve_hours' => 168], 'low' => ['resolve_hours' => 336]]` plus `auto_close_after_days`. **This is what the service reads.** |
| [MaintenanceSettings.php](../../app/Settings/MaintenanceSettings.php) | `$sla_urgent_hours = 4, $sla_high_hours = 24, $sla_medium_hours = 72, $sla_low_hours = 168` (different numbers, default 4h urgent vs config 24h urgent). **The service ignores this entirely.** |

### 1.9 OpenMaintenanceRequests widget

Confirmed scoped via `TenantScope::applyTo(MaintenanceRequest::query(), 'unit')` — correctly per-property. Order: `FIELD(priority, 'urgent', 'high', 'medium', 'low')` then `submitted_at ASC`. Module-gated via `Modules::enabled('maintenance')`. Portal `OpenMaintenance` widget instead scopes by `where('tenant_id', portal-auth-id)`.

## 2. Spec map

| Source | Verbatim | Verified |
|---|---|---|
| MASTER-PLAN.md V2.2 §1 | "**Maintenance / CAFM module** — model, admin + portal resources, polymorphic comments, SLA config in `config/maintenance.php`, seeded data, `MaintenanceRequestService`." | ✅ all present |
| FEATURES.md | "Vendor · VendorContact · VendorContract (FK from `maintenance_requests.assigned_to_vendor_id` for external assignment)" | ✅ verified, see Module 15 |
| FEATURES.md | "SLA targets live in `config/maintenance.php` (urgent 24h, high 72h, medium 7d, low 14d) + `auto_close_after_days` window — tunable per deployment without a migration." | ✅ config exists; **auto-close not wired** — see F-38 |
| FEATURES.md | "Channel attribution on Maintenance Requests — new `channel` enum column (portal/whatsapp/phone/email/walk_in/admin)" | ✅ added 2026-05-25 |
| FEATURES.md | "OpenMaintenanceRequests (admin), OpenMaintenance (portal) widgets, gated by `Modules::enabled('maintenance')`" | ✅ |

## 3. Findings

### 🔴 F-17 (Fixed inline, Maintenance carryover) — both badge methods bypassed tenant scope

Before:
```php
$count = MaintenanceRequest::whereIn('status', MaintenanceRequest::OPEN_STATUSES)->count();
$hasUrgent = MaintenanceRequest::whereIn(...)->where('priority', 'urgent')->exists();
```

After:
```php
$count = static::getEloquentQuery()->whereIn(...)->count();
$hasUrgent = static::getEloquentQuery()->whereIn(...)->where('priority','urgent')->exists();
```

Both `getNavigationBadge()` and `getNavigationBadgeColor()` now respect Filament tenancy. In Haya Walk view an operator now sees only Haya Walk's open count (and the badge turns danger only if Haya Walk has an urgent open request). Pest 287/287 + maintenance e2e 3/3 green.

Cross-cutting progress: ✅ Units · ✅ Invoices · ✅ Maintenance · ⏳ TenantSales · ⏳ Vendors.

### 🟡 F-36. MaintenanceSettings SLA properties are unused

`MaintenanceSettings` declares 4 typed properties (`sla_urgent_hours = 4`, etc.) but `MaintenanceRequestService::defaultTargetResolution` reads `config("maintenance.sla.{$priority}.resolve_hours")` — never touches the Settings instance. Worse: the two sources have **different numbers** (Settings says 4h urgent, config says 24h urgent), so a future maintainer who edits Settings expecting to change SLAs would be silently ignored.

**Two valid fixes (deferred D-28):**
- **A**: Delete the unused Settings properties + corresponding migration. The config file is the single source.
- **B**: Update the service to prefer Settings over config (`app(MaintenanceSettings::class)->{"sla_{$priority}_hours"} ?? config(...)`). Then expose Settings on the admin Settings page so operators can tune SLAs via UI. Bigger, more useful.

Recommend B for production maturity; A for cleanliness if SLAs are intentionally hardcoded.

### 🟡 F-37. No notifications fire on status change or SLA breach

`MaintenanceRequestService::transition` updates the row but doesn't dispatch any notification or event. So:

- Tenant doesn't get notified when their request moves `submitted → in_progress` or to `resolved`.
- Operator doesn't get an alert when a request crosses its `target_resolution_at` (SLA breach).
- Assigned vendor doesn't get notified when a new request is assigned to them.

**Fix scope:** non-trivial. Need:
- Event class `MaintenanceRequestStatusChanged`.
- Notification classes per audience (tenant, vendor, operator).
- A scheduled job that scans `target_resolution_at < now AND status ∈ OPEN_STATUSES AND !sla_breach_notified_at` and dispatches an SLA-breach alert (with a `sla_breach_notified_at` column to ensure idempotency).

Defer D-29 — bundle with broader notification design at Module 20.

### 🟡 F-38. `auto_close_after_days` config is read but never acted on

`config/maintenance.php` has `auto_close_after_days` but no job/command scans `status='resolved' && resolved_at < now - days` and transitions to `closed`. So `resolved` is effectively the terminal state in practice.

**Fix scope (deferred D-30):** add `MaintenanceAutoCloseCommand` (similar to `cam:reconcile`) + schedule daily entry in the Module 20 cron commit.

### 🟢 Polymorphic comment authoring

`MaintenanceRequestComment` uses MorphTo for `author()` — both User and Tenant can author naturally without join tricks. The portal RelationManager lets tenants reply on their own ticket; the admin RelationManager lets staff comment internally (`is_internal=true`) without exposing to tenant. Clean.

### 🟢 State machine is explicit

Transitions are a class constant — easy to grep, easy to test, no hidden state changes. `LeaseTerminationService` could borrow this pattern (currently uses an `in_array($lease->status, ['active','pending_approval'])` check inline).

### 🟢 Channel attribution works end-to-end

`channel` column added 2026-05-25, surfaced in form select + table column + filter + seeder backfill. Clean modular migration.

## 4. Test sweep

| Filter | Result | Time |
|---|---|---|
| `php artisan test --parallel --filter='Maintenance'` | **17 passed / 0 failed** | 1.27 s |
| `npx playwright test 17-functional-actions --grep maintenance` (3 cases) | **3 passed / 0 failed** | 7.1 s |
| `php artisan test --parallel` (post-F-17 fix) | **287 passed / 0 failed** | 4.41 s |

## 5. Inline fix this module

F-17 carryover — 12 LOC, two badge methods. Pest 287/287 + maintenance e2e 3/3 green after.

## 6. Deferred decisions

| # | Decision | Default |
|---|---|---|
| D-28 | F-36: delete unused MaintenanceSettings SLA props (A) or wire service to use Settings (B) | **B** — operator-tunable SLAs are real value; do the bigger change post-pilot |
| D-29 | F-37: design notification stack (status-changed, SLA-breach, vendor-assigned) | Bundle with Module 20 broader notification design |
| D-30 | F-38: add `MaintenanceAutoCloseCommand` + schedule | Apply in Module 20 cross-cutting cron commit (alongside F-22, F-30) |

## 7. Verdict

**🟡 Yellow.** Maintenance is feature-complete for the demo: portal submission, admin triage queue with channel + SLA + vendor assignment, status transitions with state machine, polymorphic comments, modular config. The F-17 carryover was a real bug (badges leaked across properties) — fixed inline. The remaining Yellow findings are notification/auto-close gaps that go on the production-rollout list, not demo blockers.

Module ratings: 00 🟢 · 01 🟡 · 02 🟢 · 03 🟡 · 04 🟡 · 05 🟡 · 06 🟢 · 07 🟢 · 08 🟡 · 09 🟡.

## Next

**Pausing the per-module sweep here per the user's choice.** Modules 10-20 still ahead (Owner Portal, Tenant Portal, Tenant Sales, Utilities, Credit Notes, Vendors + the 2 remaining F-17 carryovers, Assets, Users+Roles, Reports, Mobile API, Cross-cutting). Recommended next step: walk D-1 through D-30 to make decisions on the deferred fixes so we know what to apply before continuing.
