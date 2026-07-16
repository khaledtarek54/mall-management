# Announcements

> Operator broadcasts to a property's tenants — staff compose a short message and it's delivered to every active tenant of that property via the in-app bell + mobile push (no email). Property-owned; each announcement targets exactly one property.

## 1. Purpose & business context

Eltizam staff sometimes need to tell all tenants of a mall something at once — "the garage is closed Friday", "Ramadan hours start Monday", "fire drill at 3pm". This module is that channel: compose once, reach every active tenant of the chosen property on their phone (push) and in the app inbox (bell). It is **informational and one-way** — there's no email (blasts shouldn't fill inboxes) and no reply.

Composing an announcement **is** sending it: there's no draft/schedule step and no edit — the record is immutable once created and serves as the audit trail (who sent what, to how many, when).

## 2. Domain model

| Table/Model | Key columns | Meaning |
|---|---|---|
| `announcements` / `Announcement` | `asset_id` (FK, the target property), `title`, `body`, `created_by` (FK users, nullable), `sent_at` (nullable), `recipients_count` (uint) | One broadcast. `sent_at` + `recipients_count` are stamped by the fan-out; NULL `sent_at` = not yet delivered (the queued job hasn't run / failed). Soft-deletes. |

**Property isolation:** `Announcement` is **OWNED** with a direct `asset_id` (registered in `App\Support\PropertyIsolation`). Reads are scoped by the resource's own `getEloquentQuery()`; the create write is guarded by `assertAssetInScope` (`GuardsAssetInScope`).

**"Active tenant of a property"** = a `Tenant` holding an **active** `Lease` covering a `Unit` in that property — via the `lease_unit` pivot (`whereHas('activeLeases.units', units.asset_id = target)`), **not** `leases.unit_id`, which is only the *master* unit and would miss a multi-unit lease whose additional unit sits in the target property.

## 3. Business rules & invariants

1. **One property per announcement.** `asset_id` is required; the fan-out only reaches that property's active tenants. Other properties' tenants never receive it (tested).
2. **Only active tenants.** A tenant with only terminated/expired leases in the property is not notified.
3. **Immutable after creation.** No edit page; only a `super_admin` may delete (audit hygiene).
4. **Bell + push only, never email.** `AnnouncementNotification::via()` = `['database', 'push']`.
5. **Idempotent send.** `SendAnnouncementAction` no-ops when `sent_at` is already set, so a retried job can't double-notify. The `BroadcastAnnouncement` job is `tries=1` as a second backstop against re-spam.
6. **A failing recipient never strands the blast.** Each `notifyPortal()` is individually try/caught (failures logged to `OpsLog` as `announcement.recipient_failed`) and `sent_at` is stamped **even on a partial blast**, with `recipients_count` = those actually reached. Letting the exception escape would leave `sent_at` NULL after some tenants were already notified — and since the job is `tries=1` with no re-send action, the operator's only recovery would be recomposing, which re-spams everyone already reached.

## 4. Services & jobs

- **`App\Services\SendAnnouncementAction::handle(Announcement): int`** — the single-action broadcaster. Finds the property's active tenants, `notifyPortal()`s each (Tenant + its portal logins), stamps `sent_at` + `recipients_count`, returns the count. No-op if already sent.
- **`App\Jobs\BroadcastAnnouncement`** (`ShouldQueue`, `tries=1`) — runs the action off the request thread (a property can have many tenants). Dispatched from the create page's `afterCreate()`.
- **`App\Notifications\AnnouncementNotification`** — `database` + `push`. `toDatabase()` carries `type: 'announcement'`, `announcement_id`, `title`, `body` (+ bell render hints). Push reuses the same title/body via the Phase-1 `PushChannel`.

## 5. Filament resource & RBAC

`AnnouncementResource` (nav group *Communications*): a **List** of past broadcasts (title + body preview, property, recipients, sent-at, author) and a **Create** page (property Select scoped via `TenantScope::selectableAssetOptions()`, title, body). No edit/view pages.

**⚠️ Do NOT add `$tenantOwnershipRelationshipName` to this resource.** The operator *chooses* the target property, so `asset_id` is client-supplied. Filament's tenancy registers a model `creating` hook that force-associates `asset_id` with the **current panel tenant** — in "All Properties" mode that tenant is the `ALL` pseudo-asset (a real `assets` row), so it would silently overwrite the chosen property, no unit would match, and the blast would reach **zero** tenants with `sent_at` stamped and no edit page to repair it. Hence `BypassesFilamentTenantAutoScope` (which returns `isScopedToTenant() = false`, disabling that hook) + an explicit `getEloquentQuery()` for reads. Guarded by a regression test asserting `isScopedToTenant()` stays false.

- **Permissions:** `announcements.view`, `announcements.create` (`RolesPermissionsSeeder`). Granted to `super_admin` (all), `manager` (auto — all non-delete), `viewer`/`owner` (view, auto), and **`marketing`** (view + create — the department that owns tenant comms). Delete is `super_admin`-only (`RoleGatedActions::canDelete`).
- **Write guard:** `CreateAnnouncement::mutateFormDataBeforeCreate()` calls `AnnouncementResource::assertAssetInScope()` (403 if the picked property is outside the user's visible set) and stamps `created_by`. Registered in `PropertyIsolationConformanceTest`'s must-guard set.

## 6. Extension points

- **Add channels** (e.g. email for critical alerts): add to `AnnouncementNotification::via()`; nothing else changes.
- **Target selection** (selected tenants instead of whole property): the natural next step is a recipient picker on the form + a `recipients` pivot; `SendAnnouncementAction` would iterate the chosen tenants instead of `activeLeases.unit`. Keep the `sent_at` idempotency guard.
- **All-properties broadcast:** allow the `Asset::ALL_PROPERTIES_CODE` pseudo-asset and fan out across every visible property (mind isolation — a restricted user must not reach properties outside their scope; reuse `TenantScope::visibleAssetIds()`).

## 7. Gotchas

1. **`sent_at` is NULL until the queued job runs.** The list shows a "Sending…" placeholder. If it stays NULL, the queue worker isn't running (PRODUCTION-RUNBOOK §3) or the job failed (`tries=1`, so it won't retry — recompose to resend).
2. **Push requires the FCM pipeline to be live** ([PUSH-NOTIFICATIONS.md](../PUSH-NOTIFICATIONS.md)); until then the bell still delivers and push is a no-op.
3. **Recipient set is evaluated at send time.** A tenant whose lease activates after the broadcast won't get that announcement — by design (it's a point-in-time blast, not a subscription).
4. **A soft-deleted unit drops its tenant from the blast.** `Unit` soft-deletes and the recipient query honours that scope, so a tenant whose only unit in the property is trashed won't be reached. Under-delivers only (never leaks); treated as a data anomaly rather than a case to special-case.
5. **`recipients_count` counts tenants, not notifications.** One tenant with 3 portal logins = 1 recipient, 4 bell rows (Tenant + 3 users). It also counts tenants *successfully* notified, so a partial blast reports the real number.
6. **`announcements` is intentionally not in `Modules::KEYS`** — it's treated as core (always on) and can't be feature-flagged off, unlike inventory/maintenance. Add it there if that changes.

## 8. Tests

- `tests/Feature/Announcements/AnnouncementBroadcastTest.php` — fan-out to active tenants only (property + lease-status isolation), idempotency, bell+push-only channels, real bell-row delivery, and RBAC (marketing/manager compose; leasing/viewer cannot).
- `tests/Feature/Scenarios/PropertyIsolationConformanceTest.php` — enforces the isolation classification + scoping + create guard.
