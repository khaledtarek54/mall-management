# 13 — System Behaviors (the non-money machinery)

> **Audience:** the business/finance team and engineers new to Atriom.
> **Scope:** the **operational** behaviors that surround the money paths — the **tenant-request state machine**, **property scoping** (who can see which mall's data), **RBAC** (who can do what), the **scheduled background jobs** (when each fires and why it can't double-act), and **notifications** (mail / in-app bell / mobile push, and exactly who receives each).
> These are "non-money" but load-bearing: they decide *who is allowed to act on money*, *who gets told when money moves*, and *which automated jobs touch the books on a clock*. The money formulas themselves live in the sibling `docs/money/00–07` docs (linked at the end).

This document is intentionally exhaustive. Every statement cites a `file:line` so a reader can verify against the running code.

---

## 1. The Tenant-Request state machine

### 1.1 Plain-language summary

A **tenant request** is anything a retailer raises against the operator: a maintenance job, a complaint, an inquiry, an access request (keys/parking), a billing query, a document request, or "other". Historically this was only *maintenance*, which is why much of the code, the log channel, and the notification class names still say "maintenance" — but the model is `TenantRequest` and the `request_type` column distinguishes them (`app/Enums/TenantRequestType.php:21-27`).

Every request walks a **fixed lifecycle**. It starts `submitted`, the operator acknowledges and works it, and it ends either `closed` (done) or `cancelled` (abandoned). The system **only allows specific moves between statuses** — you cannot jump from `submitted` straight to `closed`, and you can never reopen a `closed`/`cancelled` request. This guarantees the audit trail and the SLA clock behave predictably.

### 1.2 The exact rule — statuses, transitions, terminal immutability

**The seven statuses** (`app/Models/TenantRequest.php:20-28`):
`submitted` · `acknowledged` · `in_progress` · `awaiting_tenant` · `resolved` · `closed` · `cancelled`.

**Open vs terminal:**
- **Open** = `['submitted','acknowledged','in_progress','awaiting_tenant']` (`TenantRequest.php:42`, `OPEN_STATUSES`). `isOpen()` at `TenantRequest.php:140-143`.
- **Terminal** = `['closed','cancelled']` (`TenantRequest.php:148-151`, `isTerminal()`). `resolved` is **NOT** terminal — a tenant can still reply and reopen it.

**The legal transition table** — the single source of truth is `TenantRequestService::TRANSITIONS` (`app/Services/TenantRequestService.php:30-38`):

| From \ allowed → | acknowledged | in_progress | awaiting_tenant | resolved | closed | cancelled |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| **submitted** | ✓ | ✓ | | | | ✓ |
| **acknowledged** | | ✓ | ✓ | | | ✓ |
| **in_progress** | | | ✓ | ✓ | | ✓ |
| **awaiting_tenant** | | ✓ | | ✓ | | ✓ |
| **resolved** | | ✓ | | | ✓ | |
| **closed** | *(none — terminal)* | | | | | |
| **cancelled** | *(none — terminal)* | | | | | |

Any move **not** in that row throws `InvalidArgumentException("Illegal transition: {current} → {next}")` (`TenantRequestService.php:170-174`). The transition is the **only** writer of status and its timestamps.

**Side-effects written on transition** (`TenantRequestService.php:176-192`), all via a single `$request->update($payload)`:
- → `acknowledged` sets `acknowledged_at = now()`.
- → `resolved` sets `resolved_at = now()` and `resolution_notes` (from `$extra['resolution_notes']`, else keeps existing).
- → `closed` sets `closed_at = now()`.
- Any transition may also set `assigned_to` if `$extra['assigned_to']` was passed.

**Notification on transition** (`TenantRequestService.php:197-208`): the requesting tenant is notified via `notifyPortal(...)` **except** when the move is to `cancelled` (the tenant usually triggered the cancel themselves, so no self-ping). Wrapped in `try/catch` — a notification failure never rolls back the status change.

### 1.3 Who can initiate which transition

- **Tenants** can: create (`→ submitted`); reply when `awaiting_tenant` (`→ in_progress`); cancel from `submitted`/`acknowledged` (see the docblock at `TenantRequestService.php:26-28`).
- **Staff** drive the rest (acknowledge, progress, resolve, close), gated by the `maintenance.change_status` permission (see §3).
- **Assignment** (`assign()`, `TenantRequestService.php:213-226`): setting an assignee on a `submitted` request **auto-acknowledges** it (`submitted → acknowledged`). Assignment on a terminal request is a **no-op** (returns unchanged, `:215-217`).
- **Department re-routing** (`redirectToDepartment()`, `:261-273`): changes `department_id`; **blocked on terminal requests** (no-op, `:266-268`).
- **Comments** (`comment()`, `:275-302`): blocked on terminal requests — throws a `ValidationException` keyed `body` (`:280-284`). `resolved` is **not** terminal, so a tenant comment can reopen it. Internal staff-only notes (`is_internal = true`) are never broadcast (`:297`).
- **CSAT rating** (`rate()`, `:237-253`): only allowed when status ∈ `['resolved','closed']` (`RATEABLE`, `:229`); otherwise throws `ValidationException`. Rating is clamped to **1–5** (`max(1, min(5, $rating))`, `:248`); a blank comment is coerced to `null` (`:245`). The tenant may overwrite an earlier rating.

### 1.4 Intake config carried by the request *type*

When a request is created (`create()`, `TenantRequestService.php:40-78`), the **type** drives several fields. All type behavior lives in `app/Enums/TenantRequestType.php`:

| Type (`value`) | Ref prefix | Sub-categories | Has SLA? | Default department (slug) | Scheduling? |
|---|---|---|:---:|---|:---:|
| Maintenance (`maintenance`) | `MR` | electrical, plumbing, hvac, structural, cleaning, safety, other | ✓ | operations | ✓ |
| Complaint (`complaint`) | `CR` | noise, cleanliness, conduct, other | ✓ | — | |
| Inquiry (`inquiry`) | `IQ` | — | | — | |
| Access (`access`) | `AR` | keys_cards, parking, after_hours, visitor, delivery | ✓ | operations | ✓ |
| Billing (`billing`) | `BQ` | — | | accounting | |
| Document (`document`) | `DR` | lease_copy, renewal, termination_notice, noc_certificate | | leasing | |
| Other (`other`) | `REQ` | — | | — | |

(Sub-categories: `TenantRequestType.php:71-80`; `hasSla()`: `:83-89`; `slaHours()`: `:97-109`; `defaultDepartmentSlug()`: `:124-132`; `referencePrefix()`: `:135-146`; `allowsScheduling()`: `:111-118`.)

**Reference number format:** `{PREFIX}-{ASSET_CODE}-{YEAR}-{NNNN}`, e.g. `MR-AW-2026-0001`. The counter is "count of all rows (incl. soft-deleted) created this year + 1" (`TenantRequest::generateReference()`, `TenantRequest.php:170-176`). The asset code comes from the tenant's active-lease unit's asset, defaulting to `'AW'` (`TenantRequestService.php:54`).

**Category coercion (a NOT-NULL-class guard):** for types with no sub-categories (inquiry, billing, other), `category` is **forced to `null`** so a stray cross-type value can never persist (`TenantRequestService.php:66`).

**Default department:** resolved from the type's slug → `Department` row id, or `null` (unassigned, manual triage) if the type has no default or the department isn't seeded (`defaultDepartmentIdFor()`, `:86-95`).

### 1.5 The SLA target (`target_resolution_at`)

`targetResolutionFor(type, priority)` (`TenantRequestService.php:106-119`) is **public** so the `/admin` create page shares the exact logic (otherwise a Complaint made in admin would wrongly get the maintenance window):

1. If the type has no SLA (`inquiry`, `billing`, `document`, `other`) → **`null`** (no deadline).
2. If the type is **Maintenance** → `defaultTargetResolution(priority)` (`:344-362`), which reads the **operator-tunable** `SlaSettings` (`/admin/settings → Maintenance`), falling back to `config('maintenance.sla.{priority}.resolve_hours')` then a hard default of **168 h** if Settings aren't seeded.
3. Otherwise (Complaint, Access) → `type->slaHours()[$priority]` hours from `now()`.

**Per-priority SLA hours** (`TenantRequestType::slaHours()`, `:103-108`):

| Type | urgent | high | medium | low |
|---|---:|---:|---:|---:|
| Maintenance | 4 | 24 | 72 | 168 |
| Complaint | 8 | 48 | 96 | 168 |
| Access | 4 | 24 | 48 | 96 |

> Note: the Maintenance row above is the *enum* fallback. At runtime the maintenance target comes from `SlaSettings` first; the `config/sla.php` defaults are urgent 24 / high 72 / medium 168 / low 336 (`config/sla.php:14-19`). The enum constants are the per-type *code* defaults used only if you route a maintenance request through the generic enum path.

A request is **overdue** when it is open AND `target_resolution_at` is in the past (`isOverdue()`, `TenantRequest.php:153-158`). This is what the hourly SLA scan keys on (§4.6).

### 1.6 Worked example

A tenant submits a **maintenance** request, priority **high**, on a unit in asset `AW`:
- `reference` = `MR-AW-2026-00NN`, `status` = `submitted`, `submitted_at` = now.
- `category` = whatever sub-category they chose (e.g. `electrical`), or null.
- `department_id` = the `operations` department's id.
- `target_resolution_at` = now + 72 h (from `SlaSettings.sla_high_hours`, default 72).
- A `PortalMaintenanceSubmittedNotification` fires to the operations + manager staff on `AW`, plus every super_admin (bell only, §5).

Staff assign it → auto `acknowledged` (sets `acknowledged_at`). They progress it `in_progress`, then `resolved` with notes (sets `resolved_at`, `resolution_notes`); the tenant is emailed + belled + pushed. The tenant rates it 5/5. Seven days later the auto-close job moves it to `closed` (§4.5).

### 1.7 Edge cases

- **Illegal transition** → `InvalidArgumentException` (`:170-174`). The UI only offers legal moves, but the service guards every path (admin, portal, mobile API, the auto-close job).
- **Comment on a closed/cancelled request** → `ValidationException` (`:280-284`). Comment on a *resolved* request is allowed and reopens the conversation.
- **Re-routing/assigning a terminal request** → silent no-op (`:215-217`, `:266-268`).
- **Rating a non-resolved/closed request** → `ValidationException` (`:239-243`).
- **Notification fan-out fails** (e.g. Spatie roles not seeded in a minimal env) → caught + logged as a warning, request creation/transition still succeeds (`:142-147`, `:201-207`, `:329-335`).
- **Tenant has no active lease** → `unit`/`lease` resolve to `null`; the reference falls back to asset code `'AW'` and the request is created unattached to a unit (`:43-44`, `:54`).

### 1.8 Invariants + gotchas

- **`TRANSITIONS` is law.** Never write `status` directly — always `transition()`. The timestamps (`acknowledged_at`/`resolved_at`/`closed_at`) are only correct if you do.
- **Terminal = immutable.** Closed/cancelled requests reject comments, re-routing, and assignment. This is enforced in the **service**, not just the UI.
- **`resolved` is reopenable**, by design — a tenant reply (`comment`) is the signal a "fixed" job wasn't actually fixed.
- The activity log only records a curated field set (`request_type, status, priority, category, assigned_to, assigned_to_vendor_id, department_id, target_resolution_at, resolution_notes, csat_rating`) under log name `maintenance_request` (`TenantRequest.php:44-51`).

---

## 2. Property scoping — `TenantScope` & `visibleAssetIds()`

### 2.1 Plain-language summary

Atriom is multi-property: one operator runs several malls (assets). A user must only ever see the malls they're entitled to. Filament implements this as **per-property tenancy**: the active property is carried in the URL, and there's a synthetic **"All Properties"** pseudo-asset for portfolio views. Three layers cooperate so that **even in "All Properties" mode a restricted user never sees a mall they aren't assigned to.** Super-admins (and unconstrained single-mall installs) see everything.

### 2.2 The exact rule

The single helper is `App\Support\TenantScope` (`app/Support/TenantScope.php`). Three entry points:

**`currentAssetId(): ?int`** (`:23-36`) — the *active* property's id, or **`null`** when scoping should not apply: when no Filament tenant is set (CLI/console) **or** the "All Properties" pseudo-asset is active (`isAllProperties()`).

**`visibleAssetIds(): ?array`** (`:87-105`) — the set the current user may see:
1. No Filament tenant (CLI/edge) → defer to `AssignedAssets::idsForCurrentUser()`.
2. A real single property is active → `[that id]`.
3. "All Properties" active → `AssignedAssets::idsForCurrentUser()` (super_admin gets `null` = all; others get their assigned set).

**`applyTo($query, ?$relation)`** (`:53-76`) — applies scoping in one call:
- If `currentAssetId()` is non-null → `where('asset_id', id)` (or `whereHas($relation, …)` when the model reaches asset through a relation).
- Else fall back to `visibleAssetIds()`: `null` → no constraint (super_admin / unconstrained); otherwise `whereIn('asset_id', $ids)`.

> **The "All Properties" leak fix.** Before this fallback existed, "All" mode handed restricted users *every* property in widgets/services. The `visibleAssetIds()` fallback in `applyTo()` (`:68-75`) closes that — a restricted user in All mode is still pinned to their assigned set. This is the canonical reason to scope through `TenantScope`/the `selectable*` helpers rather than rolling your own query.

**Who counts as "assigned"** — `AssignedAssets::idsFor($user)` (`app/Support/AssignedAssets.php:46-68`):
- super_admin → **`null`** (sees everything).
- otherwise the **union** of staff assignments (`asset_user`) **and** legal ownership (`asset_owner`) — so a Jawad owner is scoped to the malls they own, never unrestricted.
- the synthetic "All Properties" asset (`Asset::ALL_PROPERTIES_CODE`) is excluded from both sets.
- **empty union → `null`** (back-compat: a single-mall install with no explicit assignments sees everything).

### 2.3 The selectable-options helpers (form selects)

These prevent a cross-property leak in dropdowns:
- **`selectableAssetOptions()`** (`:115-125`) — property picker, scoped to `visibleAssetIds()`, always excluding the "All Properties" pseudo-asset.
- **`selectableTenantOptions()`** (`:134-147`) — tenants reachable via `leases.unit.asset_id` within the visible set; **plus** tenants with **no lease yet** (`orWhereDoesntHave('leases')`) — these belong to no property and are safe to offer everywhere.

`TenantScope::applyTo` is what widgets/services use; resource tables auto-scope via Filament tenancy; cross-property selects must use these `selectable*` helpers (this is the project invariant in `CLAUDE.md`).

### 2.4 Worked examples

- **Manager assigned to AW only, single-property view (AW active):** `currentAssetId()` = AW's id; every scoped query gets `where('asset_id', AW)`.
- **Same manager, "All Properties" active:** `currentAssetId()` = null; `visibleAssetIds()` = `[AW]`; queries get `whereIn('asset_id', [AW])` — they do **not** see other malls despite "All".
- **super_admin, "All Properties":** `currentAssetId()` = null; `visibleAssetIds()` = null → no constraint, sees everything.
- **Jawad owner of HW:** owns HW via `asset_owner`; `visibleAssetIds()` = `[HW]` in All mode; read-only (RBAC, §3).
- **Console/cron (no Filament tenant):** `currentAssetId()` = null; `visibleAssetIds()` defers to `AssignedAssets`, which for the unauthenticated console returns `null` → unscoped (jobs intentionally run portfolio-wide).

### 2.5 Edge cases + gotchas

- **CLI/queue has no Filament tenant** → `currentAssetId()` is null and scoping defers to `AssignedAssets` (null when unauthenticated). Scheduled jobs therefore operate across **all** properties — correct for billing/SLA scans.
- **"All Properties" is a real `Asset` row** with code `Asset::ALL_PROPERTIES_CODE`; it must be excluded everywhere a real-property list is built (the helpers do this).
- **Unaffiliated tenants** (no lease) are deliberately visible in every tenant picker — not a leak, since they belong to no property.
- A restricted user with **empty** assignment+ownership union is treated as **unrestricted** (single-mall back-compat). If you onboard a second mall, every restricted user MUST get explicit assignments or they'll see both.

---

## 3. RBAC — `RoleGatedActions`, permissions, and the delete rule

### 3.1 Plain-language summary

Permissions are **granular** and named `{module}.{action}` (e.g. `invoices.create`, `maintenance.change_status`). Roles are bundles of those permissions. A Filament resource derives its module from its model name and checks the matching permission for each CRUD action — **except delete**, which is **super_admin-only project-wide**, ignoring any `{module}.delete` permission. Bulk delete is additionally off everywhere unless a resource explicitly opts in.

### 3.2 The exact rule — `RoleGatedActions`

The trait `app/Filament/Admin/Resources/Concerns/RoleGatedActions.php` gates every Filament resource:

- **Module key** (`permissionModule()`, `:50-58`): snake_case plural of the model basename (`Invoice → invoices`). Override in resources whose model name ≠ module key (e.g. `CamExpensePool → cam`).
- **`hasPermission($action)`** (`:60-78`): returns true only when **both** (a) the module is enabled (`Modules::enabled($module)`) **and** (b) `$user->can("{module}.{action}")`. So a turned-off module blocks all its actions regardless of permission.
- The mapping:
  - `{module}.view` → `canViewAny`, `canView` (`:95-103`)
  - `{module}.create` → `canCreate` (`:105-108`)
  - `{module}.edit` → `canEdit`, `canRestore`, `canRestoreAny` (`:110-113`, `:137-145`)
- **DELETE is special** (`:115-135`):
  - `canDelete` / `canForceDelete` → **`isSuperAdmin()` only** — the `{module}.delete` permission is **ignored** (`:117-119`, `:127-130`).
  - `canDeleteAny` / `canForceDeleteAny` (bulk) → `$bulkDeletable && isSuperAdmin()`. `$bulkDeletable` defaults to **`false`** project-wide (`:41`); opt in per resource with `protected static bool $bulkDeletable = true;`.
- **Module off → resource hidden from the sidebar** (`shouldRegisterNavigation()`, `:90-93`).

`Modules::enabled()` (`app/Support/Modules.php:36-44`): a module not in the `KEYS` list (`:23-34`) is **core** and always on; toggleable ones read `ModulesSettings`. Toggleable: `credit_notes, maintenance, tenant_sales, cam, utility_meters, vendors, notes, reports, activity_log, eta`.

### 3.3 The permission catalog & built-in roles

Single source of truth: `database/seeders/RolesPermissionsSeeder.php`.

**Permissions** are grouped by module in `PERMISSIONS` (`:46-182`). Beyond plain `view/create/edit/delete`, several modules expose **workflow** permissions, e.g.:
- `leases.terminate`, `leases.renew`, `leases.generate_invoice`
- `invoices.run_monthly_billing`, `invoices.submit_to_eta`, `invoices.send_whatsapp`
- `credit_notes.issue` / `.apply` / `.void`
- `maintenance.assign`, `maintenance.change_status`
- `tenant_sales.lock`, `tenant_sales.dispute`
- `cam.generate_allocations`, `cam.bill_allocation`, `cam.mark_reconciled`
- `reports.download`, `settings.manage`

**Built-in roles** (`ROLES`, `:27-37`; wired in `syncRolePermissions()`, `:204-282`):

| Role | Gets |
|---|---|
| **super_admin** | **everything** (`:209`). The only role that can delete, manage settings, edit roles. |
| **manager** | every `view`/`create`/`edit` + all workflow actions; **no `.delete`**, no `settings.manage`, no `roles.create/edit/delete` (`:211-217`). Cross-department. |
| **viewer** | every `.view` + `reports.download` (`:220-224`). |
| **owner** (Jawad) | every `.view` + `reports.download` + `owner_requests.create` — read-only oversight, scoped to owned properties (`:229-235`). |
| **leasing** | properties/units/tenants/leases/sales + notes (`:240-248`). |
| **operations** | maintenance + vendors + utility meters + notes (`:251-257`). |
| **accounting** | invoices/payments/credit-notes/CAM workflow + reports (`:260-269`). |
| **marketing** | marketing budgets + spend (`:272-274`). |
| **hr** | users + roles.view + departments.view (`:277-281`). |

The five **department roles** (leasing/operations/accounting/marketing/hr) are **strictly scoped to their own sidebar group**; super_admin + manager are cross-department (docblock `:20-22`). Custom roles created in the UI can hold any combination of the same permissions and work with zero code changes (`:16-18`).

### 3.4 Worked examples

- **An `accounting` user clicks "Delete invoice":** `canDelete` returns `isSuperAdmin()` = false → action hidden/blocked, even though accounting can create/edit invoices. Only super_admin deletes.
- **A custom role with `invoices.create` but not `invoices.edit`:** can create invoices, cannot edit them — `hasPermission('edit')` is false. No code change needed.
- **`maintenance` module toggled off in settings:** every maintenance resource disappears from the nav and all its actions are denied for everyone (composite gate, `:73-74`).
- **`marketing` user opens the bulk-select on the budget table:** no bulk delete checkbox — `$bulkDeletable` is false by default and they aren't super_admin.

### 3.5 Invariants + gotchas

- **Delete = super_admin only, project-wide.** A `{module}.delete` permission row exists in the catalog (for completeness / custom roles), but `RoleGatedActions` deliberately ignores it for delete — only the role gate counts.
- **Bulk delete off by default.** Opt-in is per resource via `$bulkDeletable = true`, and even then super_admin-only.
- **Module flag AND permission** — both must pass. Turning a module off is a global kill-switch for its actions and its nav entry.
- **Custom Filament actions are not covered by this trait** — per the project's authz gotchas, every custom/relation-manager action must be gated explicitly (this trait only covers the standard CRUD lifecycle methods).

---

## 4. Scheduled jobs — what fires, when, and why it can't double-act

All schedules are registered in `routes/console.php`. **Every** schedule carries `->withoutOverlapping()`, so a slow run never overlaps the next tick at the *scheduler* level. Each job is *additionally* made idempotent/lock-safe at the *data* level, described per job below.

### 4.1 The schedule at a glance

| Job (schedule name) | Cadence (default) | Config keys | Idempotency / lock guard |
|---|---|---|---|
| `RunMonthlyBilling` (`atriom-monthly-billing`) | Monthly on day **1** at **02:00** | `billing.monthly_billing_day`, `billing.monthly_billing_time` | `WithoutOverlapping('monthly-billing:{period}')` queue middleware + per-lease+period existence check |
| `ApplyLateFees` (`atriom-late-fees`) | Daily **04:00** | `billing.late_fee_*` | per-invoice `lockForUpdate` + "already has a `late_fee` item" skip |
| `cam:reconcile` (`atriom-cam-reconcile`) | Yearly **Jan 15, 03:00** | `billing.cam_reconciliation_{month,day,time}` | `firstOrCreate` allocations; lock-safe; review-only unless `--auto-bill` |
| `vendors:expire-contracts` (`atriom-expire-vendor-contracts`) | Daily **02:30** | — | filtered `UPDATE` (only `active` + past `end_date`); naturally idempotent |
| `activitylog:clean` (`atriom-clean-activity-log`) | Monthly day 1, **05:00** | `activitylog.clean_after_days` (365) | Spatie deletes by age; re-running is harmless |
| `maintenance:auto-close` (`atriom-auto-close-maintenance`) | Daily **03:00** | `maintenance.auto_close_after_days` (7) | per-request `lockForUpdate` + re-check `status='resolved'` |
| `maintenance:scan-sla-breaches` (`atriom-scan-sla-breaches`) | **Hourly** | (SLA from settings/config) | per-request `lockForUpdate` + `sla_breach_notified_at` stamp |
| `billing:scan-overdue-invoices` (`atriom-scan-overdue-invoices`) | Daily **06:00** | — | per-invoice `lockForUpdate` + `owner_overdue_notified_at` stamp |
| `marketing:ensure-budgets` (`atriom-ensure-marketing-budgets`) | Daily **01:30** | — | `MarketingBudget::forPeriod` (`firstOrCreate`) |

(Registration: `routes/console.php:24-93`.)

### 4.2 `RunMonthlyBilling` — monthly invoices

- **Fires:** `monthlyOn(config('billing.monthly_billing_day',1), config('billing.monthly_billing_time','02:00'))` (`routes/console.php:24-30`).
- **What it does:** `MonthlyBillingService::runForPeriod($period)` generates one invoice per active lease for the month (the money detail is in `docs/money/01-billing-monthly.md`).
- **Lock-safety:** the queued job carries `WithoutOverlapping('monthly-billing:'.($period ?? 'current'))->dontRelease()` (`app/Jobs/RunMonthlyBilling.php:29-32`), so a **manually-dispatched** run can't race the scheduled one and double-bill the same period. The per-lease+period existence check inside the service is the idempotency key; the lock serialises concurrent runs around it (the existence check is not yet a DB unique constraint — noted in the hardening backlog).
- **`tries = 1`, `timeout = 600`** (`:19-21`) — a billing run is not auto-retried (a partial double-run is worse than a clean re-trigger).
- **Manual:** `php artisan billing:run-monthly {--period=YYYY-MM} {--queue}` (`RunMonthlyBillingCommand.php`). Idempotent per lease+period.

### 4.3 `ApplyLateFees` — daily overdue surcharge

- **Fires:** `dailyAt('04:00')` (`routes/console.php:32-35`).
- **What it does:** `LateFeeService::runForToday($today)` finds invoices past `due_date + grace_days` that aren't fully paid and adds **one** `late_fee` line item.
- **Config:** `billing.late_fee_grace_days` (default **7**), `billing.late_fee_percent` (default **2.0%** of balance), `billing.late_fee_minimum` (default **EGP 50**) — `config/billing.php:14-16`.
- **Idempotency + lock:** inside a `DB::transaction`, the invoice is `lockForUpdate`-loaded and skipped if it **already has a `late_fee` item** (`LateFeeService.php:65-71`). The fee = `max(minimum, round(balance × percent/100, 2))`, then **`recomputeTotals()`** is called (`:93`) — the late fee flows through the single source of truth, never by writing `total`/`balance` directly. (See `docs/money/01-billing-monthly.md` for the money detail.)
- **Manual:** `php artisan billing:apply-late-fees {--date=YYYY-MM-DD} {--queue}`; reports considered/applied/skipped/failed.

### 4.4 `cam:reconcile` — annual CAM true-up

- **Fires:** `yearlyOn(config('billing.cam_reconciliation_month',1), config('billing.cam_reconciliation_day',15), config('billing.cam_reconciliation_time','03:00'))` → **Jan 15, 03:00** (`routes/console.php:37-44`).
- **What it does:** `CamReconciliationService::autoTrueUpForYear($year, $autoBill)` for **last calendar year by default** (`--year` to override). Generates per-lease allocations for every pool. **Review-only by default** — the admin still bills each allocation manually unless `--auto-bill` is passed (`CamAnnualReconciliationCommand.php:10-19`).
- **Idempotency/lock:** allocation generation is `firstOrCreate`-based and lock-safe so a re-run can't clobber an already-**billed** allocation (commit `91fb4be`). Positive true-ups settle on a recovery invoice; negative true-ups are modelled as credit notes (commits `67ae0e0`, `e9e6235`, `c022f9f`). Full money detail in `docs/money/04-cam-reconciliation.md`.

### 4.5 `maintenance:auto-close` — close stale resolved requests

- **Fires:** `dailyAt('03:00')` (`routes/console.php:66-69`).
- **What it does:** transitions `resolved` requests whose `resolved_at ≤ now − auto_close_after_days` to `closed` (`AutoCloseMaintenanceRequestsCommand.php`).
- **Config:** `maintenance.auto_close_after_days` (default **7**, `config/sla.php:31`); `--days` overrides (must be ≥ 1, `:22-26`).
- **Lock-safety:** each candidate is `lockForUpdate`-loaded inside a transaction and **re-checked `status === 'resolved'`** before closing (`:64-74`) — so an overlapping run or a manual close between query and write is skipped cleanly rather than double-transitioning. Closing goes through `TenantRequestService::transition()`, so it sets `closed_at` and notifies the tenant via the normal path.
- **`--dry-run`** prints what would close without writing.

### 4.6 `maintenance:scan-sla-breaches` — hourly SLA alert

- **Fires:** `hourly()` (`routes/console.php:75-78`).
- **What it does:** finds **open** requests with a `target_resolution_at` in the past and **no `sla_breach_notified_at`** stamp (`ScanMaintenanceSlaBreachesCommand.php:20-26`), and alerts staff.
- **Recipients:** `manager` + `operations` users assigned to the request's asset, **plus** every super_admin (`AssetStaffRecipients::for`), **plus** the asset's Jawad **owners** (`->owners()`, FR MNT-5) — deduped by id (`:62-67`).
- **Idempotency + lock:** inside a transaction the request is `lockForUpdate`-loaded and **re-checked that `sla_breach_notified_at` is still null** (`:55-57`); only then does it send and stamp `sla_breach_notified_at = now()` (`:74`). So each breach surfaces **once**, even under overlapping scans. `MaintenanceSlaBreachedNotification` is **database (bell) only** (`:21-23`).
- **`--dry-run`** lists breaches without alerting.

### 4.7 `billing:scan-overdue-invoices` — daily owner alert

- **Fires:** `dailyAt('06:00')` (`routes/console.php:82-85`).
- **What it does:** finds invoices in `issued`/`partially_paid`/`overdue` with `balance > 0`, `due_date < today`, and **no `owner_overdue_notified_at`** stamp (`ScanOverdueInvoicesCommand.php:25-31`), and alerts the property's Jawad owners.
- **Recipients:** `AssetStaffRecipients::owners($assetId)` — the owners of the invoice's lease→unit→asset (`:65`).
- **Idempotency + lock:** mirror of the SLA scan — `lockForUpdate`, re-check `owner_overdue_notified_at` is null, send, stamp (`:59-74`). One alert per overdue invoice. `InvoiceOverdueOwnerNotification` is **database (bell) only**.

### 4.8 `vendors:expire-contracts` — daily housekeeping

- **Fires:** `dailyAt('02:30')` (`routes/console.php:49-52`).
- **What it does:** a single filtered `UPDATE` flipping `active` contracts past their `end_date` to `expired` (`ExpireVendorContractsCommand.php:16-46`), keeping the "expiring soon" nav badge meaningful. Naturally idempotent (the filter excludes already-expired rows).

### 4.9 `activitylog:clean` — monthly log pruning

- **Fires:** `monthlyOn(1,'05:00')` (`routes/console.php:57-60`).
- **What it does:** Spatie's built-in command deletes activity-log rows older than `activitylog.clean_after_days` (**365**, `config/activitylog.php:18`). Re-running is harmless (age-based delete).

### 4.10 `marketing:ensure-budgets` — daily budget auto-provision

- **Fires:** `dailyAt('01:30')` (`routes/console.php:90-93`).
- **What it does:** for every real property, `MarketingBudget::forPeriod($asset->id, $year)` (a `firstOrCreate`) so the current year's budget always exists; users never hand-create budgets — they appear here and at year rollover (`EnsureMarketingBudgetsCommand.php`). The "All Properties" pseudo-asset is excluded (`:27`).

### 4.11 Invariants + gotchas (jobs)

- **Two layers of safety on every schedule:** `withoutOverlapping()` at the scheduler, **plus** a data-level guard (a durable stamp column, a `lockForUpdate` + re-check, or a `firstOrCreate`). The CLAUDE.md invariant — *"scheduled scans must be idempotent + lock-safe (`lockForUpdate` + re-check the stamp inside the transaction)"* — is satisfied by exactly this pattern in the SLA scan, the overdue scan, and auto-close.
- **The "stamp" columns are the idempotency keys:** `tenant_requests.sla_breach_notified_at`, `invoices.owner_overdue_notified_at`. They are written **inside** the locked transaction after the notification is queued, so a crash mid-send won't permanently suppress the alert but a successful send won't repeat it.
- **Jobs run unscoped (portfolio-wide)** because there's no Filament tenant in the console (see §2.5). That's intended — billing and SLA must cover every mall.
- **`tries = 1`** on the money jobs (`RunMonthlyBilling`, `ApplyLateFees`) — no silent auto-retry that could half-apply twice.
- **Per-job failure isolation:** the scan/auto-close loops wrap each row in `try/catch` and continue, so one bad row doesn't abort the whole run (`:79-85` in the scans; `:79-82` in auto-close).

---

## 5. Notifications — channels and recipient routing

### 5.1 Plain-language summary

Atriom talks to three audiences over three channels:
- **`mail`** — a real email (sometimes with a PDF attached).
- **`database`** — the in-app **bell** (Filament admin bell for staff/owners; the portal bell for tenant portal users).
- **`push`** — a mobile push to the tenant's app (only the **Tenant** model registers device tokens).

Each notification declares which channels it uses in `via()`, and **who** receives it is decided by the sender (a service or a scheduled job). The rule of thumb: **high-frequency operational events go to the bell only** (no email spam); **tenant-facing milestones (invoice issued, payment received, status changed, sales locked) go mail + bell + push.**

### 5.2 The channel mechanics

- **`database`** — rendered by Filament's bell. Every `toDatabase()` payload is tagged `'format' => 'filament'` and `'duration' => 'persistent'` so the bell renders it and it stays until dismissed (a non-persistent toast auto-deletes the row after ~6 s). See e.g. `PortalMaintenanceSubmittedNotification.php:47-48`.
- **`mail`** — standard Laravel `MailMessage`; `InvoiceIssuedNotification` additionally attaches the invoice PDF built on-the-fly (`InvoiceIssuedNotification.php:34-37`).
- **`push`** — the custom `App\Notifications\Channels\PushChannel` (registered in `AppServiceProvider::boot()` at `:74`). It:
  - skips notifiables that don't expose `deviceTokens()` (admin `User` and `TenantUser` are silently skipped — **only `Tenant` registers tokens**, `PushChannel.php:23-25`, `Tenant.php:131-134`);
  - reuses the notification's `toDatabase()` title/body (already localized), stripping the bell-only render hints (`icon, color, format, duration`) and forwarding the id/reference fields as the deep-link `data` payload (`PushChannel.php:58-68`);
  - a notification may define `toPush()` to override (`:48-56`).
  - The actual sender is the bound `PushSender`: `FcmPushSender` only when `integrations.push.enabled` is true **and** FCM credentials are set; otherwise `NullPushSender` (a silent no-op) — so the app runs with **push off by default** (`AppServiceProvider.php:42-50`). The bell + email still deliver regardless.

### 5.3 Tenant routing — `notifyPortal()`

`Tenant::notifyPortal($notification)` (`Tenant.php:92-99`) notifies **on every surface**: the `Tenant` record itself (so the mobile API, which authenticates the Tenant, sees it and push routes to its device tokens) **and** every `TenantUser` portal login (so the web portal bell shows it). Tenants with no portal users still get the Tenant copy — nothing regresses.

### 5.4 Staff/owner routing — `AssetStaffRecipients`

`App\Services\AssetStaffRecipients` is the one place that resolves operator-side recipients (`app/Services/AssetStaffRecipients.php`):
- **`for($assetId, $roles)`** (`:24-37`) — users holding one of `$roles` **AND** assigned to the asset, **unioned with every super_admin** (platform owners always see all property activity, not just as a fallback). Deduped by id.
- **`owners($assetId)`** (`:45-54`) — the Jawad owner users for that asset (via `asset_owner`).

### 5.5 The notification catalog (channels + recipients)

| Notification | `via()` | Recipients | Trigger |
|---|---|---|---|
| `PortalMaintenanceSubmittedNotification` | `database` | manager + operations on the asset + all super_admin | tenant submits a request (`TenantRequestService::create → notifyOperators`, `:74`,`:131-148`) |
| `MaintenanceCommentAddedNotification` | tenant→`mail,database,push`; staff→`database` | the *other* party (tenant comment → staff team; staff comment → the tenant) | public (non-internal) comment (`:275-302`, routing `:308-336`) |
| `MaintenanceStatusChangedNotification` | `mail,database,push` | the requesting tenant via `notifyPortal` | any non-cancel transition (`:197-208`) |
| `MaintenanceSlaBreachedNotification` | `database` | manager + operations + super_admin + asset owners | hourly SLA scan (§4.6) |
| `InvoiceIssuedNotification` | `mail,database,push` (+ PDF attachment) | the tenant | invoice issued |
| `PaymentReceivedNotification` | `mail,database,push` | the tenant | captured payment |
| `InvoiceOverdueOwnerNotification` | `database` | the asset's Jawad owners | daily overdue scan (§4.7) |
| `SalesDeclarationSubmittedNotification` | `database` | manager + leasing on the asset + super_admin | tenant submits a sales declaration |
| `SalesDeclarationLockedNotification` | `mail,database,push` | the tenant | declaration locked → percentage-rent charge |
| `OwnerRequestNotification` | `database` | operator team on submit; the owner on operator update | owner (Jawad) request lifecycle |
| `DepartmentMessageNotification` | `database` | the target department's members | inter-department message (FR DEPT-2) |
| `TenantResetPasswordNotification` | `mail` | the tenant | mobile-app password reset (deep-link) |

(`via()` for each: `PortalMaintenanceSubmittedNotification.php:21-24`; `MaintenanceCommentAddedNotification.php:33-36`; `MaintenanceStatusChangedNotification.php:16-20`; `MaintenanceSlaBreachedNotification.php:21-23`; `InvoiceIssuedNotification.php:18-21`; `PaymentReceivedNotification.php:16-19`; `InvoiceOverdueOwnerNotification.php:19-22`; `SalesDeclarationSubmittedNotification.php:22-25`; `SalesDeclarationLockedNotification.php:16-19`; `OwnerRequestNotification.php:19-22`; `DepartmentMessageNotification.php:18-21`.)

### 5.6 Why the channel split exists

- **Operational, high-frequency, operator-facing** events (a portal submission, a tenant comment seen by staff, an SLA breach, an overdue-owner alert, a sales submission, an owner/department message) → **bell only**. Email would flood the team.
- **Milestone, tenant-facing** events (status change, staff comment to the tenant, invoice issued, payment received, sales locked) → **mail + bell + push**, because the tenant needs the durable record and the app nudge.

### 5.7 Edge cases + gotchas

- **Push silently no-ops** when push is disabled or the notifiable has no device tokens — the bell/email still deliver (`PushChannel.php:23-39`, `AppServiceProvider.php:42-50`).
- **Only the `Tenant` model registers device tokens.** Staff (`User`) and portal logins (`TenantUser`) never receive push; they're skipped by the channel (`PushChannel.php:23-25`).
- **Fan-out is failure-tolerant:** the request service wraps every notification send in `try/catch` and logs a warning, so a missing role catalogue or a mail hiccup never breaks the underlying write (`TenantRequestService.php:142-147`, `:201-207`, `:329-335`).
- **Internal staff-only comments (`is_internal = true`) never notify anyone** — the service skips `notifyOfComment` entirely (`:297`).
- **super_admins always receive property fan-outs** (not just as a fallback when nobody is assigned) — by design in `AssetStaffRecipients::for` (`:33-36`).
- **The bell row's lifetime** depends on `duration`: `persistent` rows stay until dismissed; a notification tagged otherwise would be auto-deleted after ~6 s (every Atriom DB notification uses `persistent`).

---

## Where it lives in the code (file:line index)

| Concern | Location |
|---|---|
| Request state machine + `TRANSITIONS` | `app/Services/TenantRequestService.php:30-38` |
| `transition()` (side-effects, tenant notify) | `app/Services/TenantRequestService.php:166-211` |
| `create()` (intake, reference, routing, SLA) | `app/Services/TenantRequestService.php:40-78` |
| `assign` / `redirectToDepartment` / `comment` / `rate` | `app/Services/TenantRequestService.php:213-302` |
| SLA target logic (`targetResolutionFor`, `defaultTargetResolution`) | `app/Services/TenantRequestService.php:106-119`, `:344-362` |
| Statuses / open / terminal / overdue / reference | `app/Models/TenantRequest.php:20-42`, `:140-176` |
| Type intake config (SLA, subcats, routing, prefix) | `app/Enums/TenantRequestType.php` |
| Property scoping (`currentAssetId`, `applyTo`, `visibleAssetIds`, `selectable*`) | `app/Support/TenantScope.php:23-147` |
| Assigned/owned asset resolution | `app/Support/AssignedAssets.php:31-81` |
| RBAC gating trait (delete=super_admin, bulk off) | `app/Filament/Admin/Resources/Concerns/RoleGatedActions.php` |
| Permission catalog + role → permission wiring | `database/seeders/RolesPermissionsSeeder.php:46-282` |
| Module feature flags | `app/Support/Modules.php` |
| Schedule registration (all jobs) | `routes/console.php:24-93` |
| Monthly billing job + queue lock | `app/Jobs/RunMonthlyBilling.php` |
| Late-fee job + service idempotency | `app/Jobs/ApplyLateFees.php`, `app/Services/LateFeeService.php` |
| SLA-breach scan (lock + stamp) | `app/Console/Commands/ScanMaintenanceSlaBreachesCommand.php` |
| Overdue-invoice scan (lock + stamp) | `app/Console/Commands/ScanOverdueInvoicesCommand.php` |
| Auto-close scan (lock + re-check) | `app/Console/Commands/AutoCloseMaintenanceRequestsCommand.php` |
| Vendor-contract expiry / activity-log clean / ensure-budgets / CAM reconcile | `app/Console/Commands/{ExpireVendorContracts,…,EnsureMarketingBudgets,CamAnnualReconciliation}Command.php` |
| Recipient resolution (staff/super_admin/owners) | `app/Services/AssetStaffRecipients.php` |
| Tenant fan-out (`notifyPortal`, `deviceTokens`) | `app/Models/Tenant.php:92-99`, `:131-134` |
| Push channel + binding | `app/Notifications/Channels/PushChannel.php`, `app/Providers/AppServiceProvider.php:42-50`, `:74` |
| Notifications | `app/Notifications/*.php` |
| Config keys | `config/billing.php`, `config/sla.php`, `config/activitylog.php`, `config/integrations.php` |

---

## Related

- [`docs/money/00-money-model.md`](00-money-model.md) — the single source of truth (`recomputeTotals`), AR invariant, statuses; the money that these behaviors surround.
- [`docs/money/01-billing-monthly.md`](01-billing-monthly.md) — the monthly billing engine + late fees that the scheduled jobs above drive.
- [`docs/money/03-marketing-levy.md`](03-marketing-levy.md) — the levy that funds the auto-provisioned marketing budgets.
- [`docs/money/04-cam-reconciliation.md`](04-cam-reconciliation.md) — the CAM true-up that `cam:reconcile` runs.
- [`docs/money/05-percentage-rent.md`](05-percentage-rent.md) — sales declarations whose lock/submit fire the sales notifications above.
- [`docs/money/07-credit-notes.md`](07-credit-notes.md) — credit-note locking/apply/void/reverse referenced by the CAM negative-true-up path.
- [`docs/modules/18-rbac-scoping.md`](../modules/18-rbac-scoping.md) — the RBAC + property-scoping module reference (sidebar grouping, custom roles, test index).
- [`docs/modules/19-notifications-scans.md`](../modules/19-notifications-scans.md) — the notifications + scheduled-scan module reference.
- [`docs/modules/11-tenant-requests.md`](../modules/11-tenant-requests.md) — the tenant-request module reference (Filament resources, lifecycle, full test index).
