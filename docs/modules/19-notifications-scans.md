# Notifications & Scheduled Scans
> Multi-channel notification routing (email + database/bell + **mobile push via Firebase Cloud Messaging**) for tenant-facing events, plus hourly/daily scheduled scans that alert operators and owners when SLAs breach or invoices go overdue.
>
> **Mobile push setup + operations:** see [docs/PUSH-NOTIFICATIONS.md](../PUSH-NOTIFICATIONS.md) for the Firebase/APNs runbook, `.env` wiring, and the device-registration API contract. Push is a first-class notification channel — a notification opts in simply by adding `'push'` to its `via()`.

## 1. Purpose & business context
This module delivers time-sensitive alerts to tenants, operators (Eltizam), and property owners (Jawad) across two channels:

**Tenant-facing notifications** (email + web/portal bell + mobile push): Invoice issued, payment received, maintenance status change, comment on maintenance request, sales declaration locked. These carry `'push'` in their `via()`, so they also fan out to the tenant's registered mobile devices via FCM (when push is enabled — otherwise the no-op `NullPushSender` runs and only email + bell deliver).

**Operator-side bell (database-only)**: Portal maintenance submitted, portal sales declaration submitted, maintenance SLA breach (scanned hourly), overdue invoice alert to owners (scanned daily).

**Owner oversight alerts** (database-only): When an invoice on their property is overdue, they are notified via the operator bell; when a maintenance SLA breaches, oversight users at that asset see it.

The module enforces **idempotency** through two mechanisms:
- **Notification lock stamps** (`MaintenanceRequest.sla_breach_notified_at`, `Invoice.owner_overdue_notified_at`) prevent duplicate alerts.
- **Tenant portal fan-out** (`Tenant::notifyPortal()`) ensures all portal logins (TenantUser records) receive tenant-facing events.

**Real-world flow**: A tenant submits a maintenance request → PortalMaintenanceSubmitted alerts assigned ops staff → staff acknowledges it → MaintenanceStatusChanged notifies tenant + portal logins via email + bell → if 72h SLA passes (for high priority) without resolution → ScanMaintenanceSlaBreachesCommand locks the request, stamps it, and alerts managers + owners again → resolved → auto-closed after 7 days.

---

## 2. Domain model

| Table/Model | Key Columns | Meaning |
|---|---|---|
| `notifications` (Laravel default) | `id` (uuid), `type`, `notifiable_type`, `notifiable_id`, `data` (JSON), `read_at`, `created_at` | Stores all notifications sent to Users and TenantUsers. The `data` column holds `toDatabase()` payload: type, title, body, format ('filament' for bell-rendered), duration ('persistent' for no auto-close), icon, color. |
| `MaintenanceRequest` | `sla_breach_notified_at` (timestamp, nullable) | Idempotency stamp: set when hourly scan fires the SLA breach alert; if not null, scan skips this request. |
| `Invoice` | `owner_overdue_notified_at` (timestamp, nullable) | Idempotency stamp: set when daily scan fires the overdue alert to Jawad owners; if not null, scan skips this invoice. |

**Relationships**:
- `Tenant` → (1:many) `TenantUser` (portal logins); `Tenant::notifyPortal($notification)` sends to both Tenant + all TenantUsers
- `MaintenanceRequest` → (many:1) `Tenant`, `Unit`, `Asset`
- `Invoice` → (many:1) `Lease` → `Unit` → `Asset`, `Tenant`
- `MaintenanceRequest` → (many:1) `MaintenanceRequestComment` (public/internal)
- `TenantSalesDeclaration` → (many:1) `Lease` → `Tenant`

---

## 3. Business rules & invariants

1. **Tenant-side notifications ALWAYS fan to both Tenant record AND all portal logins**  
   - `notifyPortal()` is the single entry point; never call `->notify()` directly on a Tenant.
   - Violation: portal users miss alerts. Tested in `NotificationFlowScenarioTest::billing fans the invoice-issued notification to the tenant AND every portal user`.

2. **SLA breach alert fires exactly once per request**  
   - `sla_breach_notified_at` is the guard; transaction lock prevents concurrent scans from double-alerting.
   - Formula: if `status` ∈ `['submitted', 'acknowledged', 'in_progress']` AND `target_resolution_at < now()` AND `sla_breach_notified_at IS NULL`, alert fires.
   - Tested in `ScanMaintenanceSlaBreachesCommandTest::alerts on a breached request and stamps sla_breach_notified_at`.

3. **Overdue invoice alert fires exactly once per invoice**  
   - `owner_overdue_notified_at` is the guard; transaction lock prevents concurrent scans from double-alerting.
   - Formula: if `status` ∈ `['issued', 'partially_paid', 'overdue']` AND `balance > 0` AND `due_date < today()` AND `owner_overdue_notified_at IS NULL`, alert fires.
   - Tested in `ScanOverdueInvoicesCommandTest` (implicit via command tests).

4. **SLA targets are priority-based** (from `config/maintenance.php`)  
   - urgent: 24h, high: 72h, medium: 7 days (168h), low: 14 days (336h)
   - `MaintenanceRequest.target_resolution_at = created_at + priority_hours`

5. **Late-fee policy** (from `config/billing.php`)  
   - Grace period: `late_fee_grace_days` (default 7 days after due_date)
   - Percent: `late_fee_percent` (default 2% of balance)
   - Minimum: `late_fee_minimum` (default EGP 50)
   - Formula: `fee = max(minimum, balance * percent / 100)`
   - Applied once per invoice; subsequent late-fee runs skip if `late_fee` line item exists (idempotent).

6. **Status transitions block invalid notifications**  
   - cancelled → maintenance status change does NOT notify (tenant initiated).
   - internal-only comments do NOT trigger notification (guarded in service).
   - Tested in `MaintenanceAndSalesNotificationsTest::the cancelled transition does NOT fire`.

7. **Operator/owner routing is role + asset-scoped**  
   - `AssetStaffRecipients::for($assetId, ['manager', 'operations'])` returns users with those roles + assigned to that asset, plus all super_admins (fallback).
   - Owners returned via `AssetStaffRecipients::owners($assetId)`: users with Jawad owner role + asset_owner relationship.
   - If no users found, notification silently skips (no mail bounces).

---

## 4. Lifecycle / state machine

The notification module itself is **stateless** (notifications are created and archived, not transitioned). However, it guards the **triggering** state machines:

**Maintenance Request Status Machine** (triggers MaintenanceStatusChanged on non-cancelled transitions):
```
submitted ──→ acknowledged ──→ in_progress ──→ resolved ──→ closed
        ╲                                          ↑
         ╚──────────── cancelled (no notify) ─────╝
```

**Invoice Lifecycle** (governs when notifications fire):
```
issued ──→ partially_paid ──→ paid (no more alerts)
  ↓            ↓
  └───→ overdue ────────────────┘
       (scan alerts on due_date < today)
```

**Scan Idempotency (by timestamp):**: Each scan passes its entry point idempotency state to the next run:
```
maintenance:scan-sla-breaches (hourly)
  ├─ Find open requests with target_resolution_at < now AND sla_breach_notified_at IS NULL
  ├─ For each: DB::transaction { lock request, re-check stamp, if still null → Notification::send() + stamp }
  └─ Repeated runs skip already-stamped rows

billing:scan-overdue-invoices (daily @ 06:00)
  ├─ Find invoices with status=[issued|partially_paid|overdue], balance > 0, due_date < today, owner_overdue_notified_at IS NULL
  ├─ For each: DB::transaction { lock invoice, re-check stamp, if still null → Notification::send() + stamp }
  └─ Repeated runs skip already-stamped rows
```

---

## 5. Services, jobs & scheduled commands

### Scheduled Commands (defined in `routes/console.php`)

| Command | Cron | Signature | What it does | Idempotency | Locking |
|---------|------|-----------|--------------|-------------|---------|
| `maintenance:scan-sla-breaches` | hourly | `--dry-run` | Find open maintenance requests past `target_resolution_at`, notify asset managers + owners if breach is new (sla_breach_notified_at=null), then stamp. Rolls up breach count + delivery success count. | sla_breach_notified_at | DB::transaction + lockForUpdate per request |
| `maintenance:auto-close` | 03:00 daily | `--days=7 --dry-run` | Transition MaintenanceRequest rows from 'resolved' → 'closed' if resolved_at ≤ (today - --days). Uses MaintenanceRequestService::transition(). | resolved_at timestamp | none (service provides) |
| `billing:scan-overdue-invoices` | 06:00 daily | `--dry-run` | Find invoices with balance > 0, due_date < today, status=[issued\|partially_paid\|overdue], alert Jawad owners, stamp owner_overdue_notified_at. | owner_overdue_notified_at | DB::transaction + lockForUpdate per invoice |
| `vendors:expire-contracts` | 02:30 daily | `--dry-run` | Transition active VendorContract rows past end_date to status='expired' (housekeeping for nav badge). | none (direct update) | none |
| `billing:apply-late-fees` | 04:00 daily | `--date=YYYY-MM-DD --queue` | Scans overdue invoices (due_date ≤ today - grace_days), adds `late_fee` line item if not already present. Idempotent via line item type check. | late_fee line item presence | DB::transaction + lockForUpdate per invoice |
| `cam:reconcile` | Jan 15, 03:00 yearly | `--year=YYYY --auto-bill` | Generates CamAllocation rows for draft pools; optionally bills them. Review-only by default. | pool status (must be 'draft') | none |
| `activitylog:clean` | 05:00 monthly (1st) | (Spatie audit log cleanup) | Drops activity log rows older than config's clean_after_days (default 365). | row age | none |

### Job Classes (queueable versions)

| Job | Dispatched by | What it does | Timeout | Retry |
|-----|---------------|--------------|---------|-------|
| `RunMonthlyBilling` | `Schedule::job(new RunMonthlyBilling)` at 02:00 1st of month (or --queue flag on command) | Calls `MonthlyBillingService::runForPeriod($period)` with a YYYY-MM string. Fires InvoiceIssuedNotification per lease. | 600s | 1 try |
| `ApplyLateFees` | `Schedule::job(new ApplyLateFees)` at 04:00 daily (or --queue flag on command) | Calls `LateFeeService::runForToday($date)` with optional YYYY-MM-DD. Adds late_fee line items idempotently. | 600s | 1 try |

**All commands support `--dry-run`**: prints what would change without writing or notifying.

### Services

**`LateFeeService::runForToday(?CarbonImmutable $today)`**  
Returns `['considered', 'applied', 'skipped', 'failed']` stats.  
Loops through eligible invoices, calls `applyTo()` per invoice inside a transaction + lock to guard against concurrent duplicates.

**`AssetStaffRecipients::for(?int $assetId, array $roles)`**  
Returns Collection of Users with $roles assigned to $assetId, plus all super_admins.  
Used by: PortalMaintenanceSubmitted, MaintenanceSlaBreached; also called by SalesDeclarationSubmitted (with `['manager', 'leasing']`).

**`AssetStaffRecipients::owners(?int $assetId)`**  
Returns Collection of Users with owner (Jawad) role + asset_owner relationship for $assetId.  
Used by: ScanMaintenanceSlaBreachesCommand, ScanOverdueInvoicesCommand for oversight alerts.

---

## 6. Filament resources & key fields

**Notification Bell (in Filament UI)**  
- Reads from `notifications` table where `notifiable_type = 'App\\Models\\User'` or `'App\\Models\\TenantUser'` and `data->format = 'filament'`.
- Renders: `data->title`, `data->body`, `data->icon`, `data->color`, `data->duration` ('persistent' = pinned until user dismisses).
- **No RBAC permission required** — the notification routing itself is permission-checked upstream (only assigned staff receive operator alerts).

**Tenant Portal Bell**  
- Reads from `notifications` where `notifiable_type = 'App\\Models\\TenantUser'` and `data->format = 'filament'`.
- Tenant-side notifications flow through `Tenant::notifyPortal()` which sends to all portal logins automatically.

**No Filament Resource for notifications themselves** — they are read-only and managed by the notification system.

---

## 7. Notifications & integrations

### Notification Classes (all use Queueable trait)

| Notification | Channels | Recipients | Fired by | When |
|--------------|----------|-----------|----------|------|
| `InvoiceIssuedNotification` | mail, database, **push** | Tenant + portal logins | MonthlyBillingService::generateForLease() | Invoice created during monthly billing run |
| `PaymentReceivedNotification` | mail, database, **push** | Tenant + portal logins | Payment observer (status: initiated → captured, with allocations) | Payment captured + allocated to invoices |
| `MaintenanceStatusChangedNotification` | mail, database, **push** | Tenant + portal logins (except for cancelled transition) | MaintenanceRequestService::transition() | Any status change except cancelled |
| `MaintenanceCommentAddedNotification` | mail + database + **push** (Tenant recipient) OR database-only (staff recipient) | Tenant (if staff commented) OR asset staff (if tenant commented) | MaintenanceRequestService::comment($isInternal=false) | Public comment added to maintenance request |
| `PortalMaintenanceSubmittedNotification` | database | Asset managers + operations + super_admins | MaintenanceRequestService::create() | Tenant submits maintenance request via portal |
| `MaintenanceSlaBreachedNotification` | database | Asset managers + operations + super_admins + owners | ScanMaintenanceSlaBreachesCommand (hourly) | Request still open past target_resolution_at (hourly scan) |
| `InvoiceOverdueOwnerNotification` | database | Jawad owners of the property | ScanOverdueInvoicesCommand (daily) | Invoice past due_date with balance > 0 (daily scan) |
| `SalesDeclarationSubmittedNotification` | database | Asset managers + leasing | PercentageRentCalculationService (on declaration submit) | Tenant submits sales declaration from portal |
| `SalesDeclarationLockedNotification` | mail, database, **push** | Tenant + portal logins | PercentageRentCalculationService (on declaration lock) | Sales declaration locked (percentage rent calculated) |
| `OwnerRequestNotification` | database | Asset managers + super_admins (submitted) OR Jawad owner (updated) | OwnerRequest model observer | Owner request submitted or status changed |
| `DepartmentMessageNotification` | database | Assigned users + super_admins | DepartmentMessage model observer | Message created in a department |

**toDatabase() Payload Structure** (all notifications):
```php
[
    'type' => 'unique_notification_type',  // used to route in bell UI
    'format' => 'filament',                // must be 'filament' to render in admin/portal bell
    'title' => 'Human-readable title',     // from translations (admin/notifications.*)
    'body' => 'Short description',
    'icon' => 'heroicon-o-...',           // Heroicon name
    'color' => 'primary|success|danger|warning|info',
    'duration' => 'persistent',            // 'persistent' = stay until dismissed; else auto-clears ~6s
    // + type-specific fields (invoice_id, request_id, etc.)
]
```

**Mail Channels** (tenant-facing + lock notifications only):
- InvoiceIssuedNotification: view `emails.invoice-issued`, attaches invoice PDF
- PaymentReceivedNotification: generic MailMessage with allocated invoices
- MaintenanceStatusChangedNotification: generic MailMessage with status + resolution notes if resolved/closed
- MaintenanceCommentAddedNotification: generic MailMessage with comment body
- SalesDeclarationLockedNotification: generic MailMessage with period + amount

### Mobile Push (Firebase Cloud Messaging)

The `push` channel delivers tenant-facing notifications to the tenant mobile app. Full setup + API contract: [docs/PUSH-NOTIFICATIONS.md](../PUSH-NOTIFICATIONS.md). Key pieces:

| Piece | File | Role |
|---|---|---|
| `push` channel | `app/Notifications/Channels/PushChannel.php` | Resolves the notifiable's device tokens + renders the payload (reuses `toDatabase()` title/body, strips bell render-hints, keeps id fields for deep-linking). Registered in `AppServiceProvider::boot()`. |
| `SendPushNotification` job | `app/Jobs/SendPushNotification.php` | Queued delivery — the FCM round-trip runs off the request thread. Carries the **already-rendered** payload (no model to reload) so it's safe to dispatch inside the triggering DB transaction. Prunes dead tokens. |
| `PushSender` (interface) | `app/Services/Push/PushSender.php` | Pluggable transport; `send()` returns the tokens the provider reported as permanently invalid. Bound to `FcmPushSender` when `integrations.push.enabled` + creds, else `NullPushSender` (no-op). |
| `FcmPushSender` | `app/Services/Push/FcmPushSender.php` | FCM HTTP v1 (no SDK): mints a Google OAuth token from the service-account JSON (cached ~55 min), sends one concurrent request per token via `Http::pool()`, classifies HTTP 404 `UNREGISTERED`/`NOT_FOUND` as dead. |
| `DeviceToken` | `app/Models/DeviceToken.php` | One row per (tenant, platform, device). Registered by the app via `POST /api/v1/me/devices`; unique on `(tenant_id, platform, device_name)`. |

**Push routing rule:** only notifiables exposing a `deviceTokens()` relation receive push — the **Tenant** does; TenantUsers and admin Users don't (silently skipped). So `Tenant::notifyPortal()` delivers push once via the Tenant leg; the Tenant's own `database` notification is what the mobile inbox (`GET /api/v1/me/notifications`) reads.

**Never-throw contract:** a push failure (bad creds, FCM 5xx, network) must never break the triggering event — the DB bell + email have already delivered. `FcmPushSender` catches everything and logs via `OpsLog`.

**External Integrations**:
- Firebase Cloud Messaging (mobile push) — see above + [docs/PUSH-NOTIFICATIONS.md](../PUSH-NOTIFICATIONS.md).
- Invoice PDF generation (InvoicePdfService) is called by InvoiceIssuedNotification but is defined elsewhere.

---

## 8. Extension points — how to change/extend SAFELY

### Adding a new tenant-facing event (e.g., "Lease expiry approaching"):

1. **Create the Notification class** in `app/Notifications/LeaseExpiryApproachingNotification.php`:
   ```php
   class LeaseExpiryApproachingNotification extends Notification {
       use Queueable;
       public function __construct(public Lease $lease) {}
       public function via($notifiable) { return ['mail', 'database']; }
       public function toMail($notifiable) { /* MailMessage */ }
       public function toDatabase($notifiable) {
           return [
               'type' => 'lease_expiry_approaching',
               'format' => 'filament',
               'lease_id' => $this->lease->id,
               'expiry_date' => $this->lease->expiry_date->toDateString(),
               'title' => 'Lease expiry notice',
               'body' => "Your lease {$this->lease->unit->code} expires on {$this->lease->expiry_date->format('M d, Y')}.",
               'icon' => 'heroicon-o-calendar',
               'color' => 'warning',
               'duration' => 'persistent',
           ];
       }
   }
   ```

2. **Emit the notification** from the right service/observer:
   ```php
   // In a scheduled command or service:
   $lease->tenant->notifyPortal(new LeaseExpiryApproachingNotification($lease));
   ```

3. **Add the translation** in `resources/lang/en/admin/notifications.php`:
   ```php
   'lease_expiry_approaching_title' => 'Lease expiry notice',
   'lease_expiry_approaching_body' => 'Your lease {unit} expires on {date}.',
   ```

4. **Test**:
   - Mock Notification facade, verify `assertSentTo($tenant, LeaseExpiryApproachingNotification::class)` and fan-out to portal users.
   - Verify `toDatabase()` payload shape: `type`, `format='filament'`, `title`, `body`, `icon`, `color`, `duration`.

### Adding a new operator/owner alert (e.g., "Unit vacancy"):

1. **Create the Notification class** in `app/Notifications/UnitVacancyAlertNotification.php`:
   ```php
   class UnitVacancyAlertNotification extends Notification {
       use Queueable;
       public function __construct(public Unit $unit) {}
       public function via($notifiable) { return ['database']; }  // No mail for ops alerts
       public function toDatabase($notifiable) {
           return [
               'type' => 'unit_vacancy_alert',
               'format' => 'filament',
               'unit_id' => $this->unit->id,
               'title' => 'Unit vacancy',
               'body' => "Unit {$this->unit->code} is now vacant.",
               'icon' => 'heroicon-o-home',
               'color' => 'info',
               'duration' => 'persistent',
           ];
       }
   }
   ```

2. **Emit from a scheduled command or observer**, using `AssetStaffRecipients`:
   ```php
   // In a command handle() or service:
   $recipients = app(AssetStaffRecipients::class)->for($unit->asset_id, ['manager', 'leasing']);
   if ($recipients->isNotEmpty()) {
       Notification::send($recipients, new UnitVacancyAlertNotification($unit));
   }
   ```

3. **Add translation** and **test** (same as above, but no portal fan-out assertion).

### Modifying scan idempotency:

**DO NOT** rename or reuse `sla_breach_notified_at`, `owner_overdue_notified_at` columns without:
1. Creating a new migration to add a new stamp column (e.g., `property_owner_notified_at`).
2. Updating the command to read/write the new column.
3. Re-running the command manually on production to backfill the stamp for already-alerted rows.

**DO NOT** rely on a single database row to track multiple alert events** — use separate stamp columns. Example: if you need to notify both the owner AND a supervisor on overdue invoice, add a second column `supervisor_overdue_notified_at` rather than reusing `owner_overdue_notified_at`.

### Changing SLA targets:

Edit `config/maintenance.php` `sla` array. The `MaintenanceRequestService` reads this at create-time to set `target_resolution_at`. **Changing the config does NOT update existing targets** — only new requests get the new SLA. If you need to re-calculate existing requests, write a one-off command.

### Changing late-fee policy:

Edit `config/billing.php`. The `LateFeeService::applyTo()` reads these at run-time:
- `late_fee_percent`: % of balance
- `late_fee_grace_days`: days after due_date before fee applies
- `late_fee_minimum`: floor amount

**Changing the config takes effect immediately** on the next late-fee run. Existing fees are not retroactively adjusted.

---

## 9. Gotchas, edge cases & recently-fixed bugs

1. **Portal fan-out must hit ALL portal logins**  
   Subtle bug: if you call `$tenant->notify($notification)` instead of `$tenant->notifyPortal($notification)`, the portal logins miss the alert. The Tenant record is primarily for the mobile/API surface; the web bell reads TenantUser notifications only.  
   **Guard**: Always use `notifyPortal()` for tenant-facing events. Tests in `NotificationFlowScenarioTest` assert both the Tenant AND each TenantUser receive the notification.

2. **Concurrent scan can double-alert if lock is missing**  
   The scan commands use `DB::transaction { lockForUpdate() }` inside the loop. If a second scan process starts before the first finishes, without the lock, both could pass the `stamp IS NULL` check and fire twice.  
   **Guard**: Every scan command wraps the idempotency re-check inside `lockForUpdate()`. Never move the lock outside the transaction or remove the re-check.

3. **No notification if AssetStaffRecipients returns empty**  
   If an asset has no users with 'manager' role (or no users + no super_admins), the scan silently skips the alert. This is intentional to avoid mail bounces, but can hide misconfiguration.  
   **Guard**: Seed super_admin users in development. In production, ensure at least one super_admin exists, or assign staff to the asset with the right role.

4. **Payment notification only fires if captured AND has allocations**  
   An operator may create a Payment in 'captured' status without allocations (to batch-allocate later). `PaymentReceivedNotification` is NOT fired on create; it fires only when status transitions from initiated → captured AND the payment.invoices relationship is not empty.  
   **Guard**: Tests in `InvoiceAndPaymentNotificationsTest` assert this; check the Payment observer.

5. **Maintenance status change does NOT notify if cancelled**  
   The `cancelled` transition is user-initiated (tenant or staff cancelling a request they submitted). No notification fires because the other party (staff or tenant) already knows.  
   **Guard**: `MaintenanceRequestService::transition()` has an explicit check: `if ($to === 'cancelled') return; // no notify`.

6. **Internal-only maintenance comments never trigger notifications**  
   If `MaintenanceRequestService::comment(..., isInternal: true)`, the notification is suppressed entirely.  
   **Guard**: The service checks `isInternal` before calling the notify method.

7. **SLA breach alerts include both assigned staff AND owners**  
   The `ScanMaintenanceSlaBreachesCommand` merges both `AssetStaffRecipients::for(..., ['manager', 'operations'])` AND `AssetStaffRecipients::owners(...)`. This is intentional (FR MNT-5 oversight), but means owners see high-priority operational alerts.  
   **Guard**: Tests verify this; if you need to exclude owners from SLA, edit the command's recipient merge logic.

8. **Invoices with balance=0 (fully paid) are never scanned for overdue alert**  
   The overdue scan filters `balance > 0`. If an invoice is fully paid, even if it was paid after the due date, it does not trigger an overdue alert (because the owner cares about outstanding money, not historical late payment).  
   **Guard**: Intended design; tests verify this.

9. **Hourly scan may batch-alert a high-volume asset**  
   If 50 requests breach their SLA in the same hour, all 50 trigger notifications. The command runs them sequentially (with individual locks), so the run may take a few seconds, but will complete.  
   **Guard**: The command logs success/failure per request and returns a count. If you see timeouts, tune the queue timeout (default 600s).

10. **Late-fee is applied to invoice.balance, NOT to a separate line item amount**  
    When a late fee is added, the invoice's `subtotal`, `total`, and `balance` are all incremented by the fee amount. This is important if the tenant then pays the invoice — the late fee is included in the payment calculation.  
    **Guard**: `LateFeeService::applyTo()` updates all three columns atomically.

11. **Notification channel ordering matters for UI polish**  
    Notifications with `['mail', 'database']` send both. The mail sends immediately (or queues); the database entry is written synchronously. If mail fails, the database entry is still created (best-effort); the notification shows in the bell even if mail didn't send.  
    **Guard**: Use `Queueable` trait to avoid blocking the response; the queue can retry.

12. **Push is delivered off-thread and prunes its own dead tokens**  
    The `push` channel does NOT call FCM inline — it dispatches `SendPushNotification`, which runs on the queue (requires the worker, PRODUCTION-RUNBOOK §3). The payload is materialized before dispatch (title/body/data), so there's no lazy model to reload and it's safe even when the notification fires inside a DB transaction (on the `database` queue the job row commits with the transaction). When FCM reports a token as permanently gone (HTTP 404 `UNREGISTERED`), the job deletes that `DeviceToken` row — transient failures (5xx/network) are left alone.  
    **Guard**: never make `PushSender::send()` throw, and never prune on anything but the 404/`UNREGISTERED` signal (a payload bug returns 400 — pruning on that would wipe live tokens). Covered by `PushNotificationTest`.

---

## 10. Tests & related modules

### Test Files
- `tests/Feature/Notifications/InvoiceAndPaymentNotificationsTest.php` — invoice issued, payment received, no-notify-if-no-allocations
- `tests/Feature/Notifications/MaintenanceAndSalesNotificationsTest.php` — maintenance status change, cancelled no-notify, tenant/staff comment routing
- `tests/Feature/Notifications/AdminTriageNotificationsTest.php` — operator-side routing (portal maintenance submitted, sales declaration submitted, no-notify-if-no-roles)
- `tests/Feature/Scenarios/NotificationFlowScenarioTest.php` — **authoritative suite** covering notifyPortal fan-out, scoping (unrelated tenant not notified), payload shape (title/body/format/color/duration), recipient branching (maintenance comment Tenant vs staff), sales locked, payment received with invoice list
- `tests/Feature/Console/ScanMaintenanceSlaBreachesCommandTest.php` — breach alert fired, sla_breach_notified_at stamped, --dry-run, already-alerted skipped, closed/resolved skipped
- `tests/Feature/Console/ConsoleCommandsTest.php` — apply-late-fees stats, monthly billing dispatch, CAM reconcile, cam:reconcile --auto-bill

### Related Modules (see docs/modules/<name>.md)
- `08-maintenance-requests.md` — MaintenanceRequest model, status machine, SLA logic
- `06-billing.md` — Invoice creation, payment capture, late-fee math, allocation
- `05-leases.md` — Lease model, tenant relationship
- `12-sales-declarations.md` — TenantSalesDeclaration, percentage rent locking
- `14-users-rbac.md` — User roles (manager, operations, leasing, owner), asset assignment, super_admin fallback
- `17-portal-auth.md` — TenantUser, portal login fan-out surface
