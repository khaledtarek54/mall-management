# Mobile Push Notifications (Firebase Cloud Messaging)

> How Atriom sends push notifications to the tenant mobile app, how to turn it on, and the API contract the app implements. For where push sits among the other channels (email, in-app bell) and the list of events that push, see [modules/19-notifications-scans.md](../modules/19-notifications-scans.md).

FCM itself is **free and unlimited**. The whole system ships **disabled** (`PUSH_ENABLED=false` → `NullPushSender`, a no-op) so the app runs with zero Firebase setup — email + the in-app bell still deliver. Turning it on is a config + credentials task, not a code change.

---

## 1. How it works (architecture)

```
 Domain event (invoice issued, payment received, …)
        │
        ▼
 Notification with 'push' in via()        e.g. InvoiceIssuedNotification
        │  (Tenant::notifyPortal → the Tenant leg carries device tokens)
        ▼
 PushChannel  (app/Notifications/Channels/PushChannel.php)
        │  renders title/body/data from toDatabase(); resolves the tenant's device tokens
        ▼
 SendPushNotification  (queued job — runs on the worker, off the request thread)
        │  calls the bound PushSender; prunes tokens FCM says are dead
        ▼
 FcmPushSender  (app/Services/Push/FcmPushSender.php)
        │  OAuth token from service-account JSON (cached ~55m) → FCM HTTP v1, one concurrent POST per token
        ▼
 Firebase Cloud Messaging ──► APNs (iOS) / device (Android)
```

**Design invariants** (don't break these — they were deliberate):
- **Push never blocks the request.** Delivery is a queued job; the triggering event returns immediately. *Requires the queue worker* (PRODUCTION-RUNBOOK §3).
- **Push never breaks the event.** `FcmPushSender` catches every error and logs via `OpsLog`; a Firebase outage cannot fail an invoice run or a payment capture. The email + bell have already delivered.
- **Dead tokens self-prune.** When FCM returns HTTP 404 `UNREGISTERED`/`NOT_FOUND` for a token (app uninstalled / token expired), the job deletes that `DeviceToken` row. Transient failures (5xx, network) are left intact.
- **Only Tenants receive push.** The `push` channel skips any notifiable without a `deviceTokens()` relation, so admin `User`s and portal `TenantUser`s are silently skipped.
- **Adding push to an event is one line:** append `'push'` to the notification's `via()`. The channel reuses the existing `toDatabase()` title/body and forwards the id fields (invoice_id, request_id, …) as the deep-link `data` payload.

---

## 2. Turn it on — Firebase project setup

You need a Google account. ~15 minutes, one-time.

### 2.1 Create the Firebase project
1. Go to <https://console.firebase.google.com> → **Add project**. Name it e.g. `atriom-prod` (create a separate `atriom-staging` for staging — keep prod and non-prod tokens apart). Google Analytics is optional; skip it.
2. In the project, add the client apps so Firebase issues the config the mobile app needs:
   - **Android:** Project settings → *Your apps* → Android. Enter the app's package name (from the mobile repo's `applicationId`, e.g. `com.eltizam.atriom`). Download `google-services.json` → hand to the mobile developer.
   - **iOS:** Project settings → *Your apps* → iOS. Enter the bundle ID (e.g. `com.eltizam.atriom`). Download `GoogleService-Info.plist` → hand to the mobile developer.

### 2.2 iOS only — upload the APNs Auth Key (required, or iOS silently won't deliver)
FCM delivers to iOS **through Apple's APNs**, so Firebase needs an APNs key. Needs an **Apple Developer account** (paid).
1. Apple Developer → *Certificates, Identifiers & Profiles* → **Keys** → **+** → enable **Apple Push Notifications service (APNs)** → download the `.p8` (you can only download it once) and note the **Key ID**. Note your **Team ID** (top-right of the developer portal).
2. Firebase → Project settings → **Cloud Messaging** → *Apple app configuration* → **APNs Authentication Key** → upload the `.p8` + Key ID + Team ID.

### 2.3 Download the server credential (the service-account JSON)
This is what **this Laravel backend** uses to authenticate to FCM.
1. Firebase → Project settings → **Service accounts** → **Generate new private key** → confirms → downloads a JSON file (contains `client_email`, `private_key`, `project_id`).
2. Treat it like a password. **Do not commit it.** Put it outside the web root or in a gitignored path:
   ```
   storage/app/firebase/atriom-prod.json        # storage/app is already gitignored
   ```
   On a server, `chmod 600` it and keep it out of backups that leave your control.

---

## 3. Wire the backend (`.env`)

```dotenv
PUSH_ENABLED=true
FCM_CREDENTIALS=/var/www/atriom/storage/app/firebase/atriom-prod.json   # ABSOLUTE path
FCM_PROJECT_ID=atriom-prod                                              # optional; falls back to project_id in the JSON
```

Read by [config/integrations.php](../../config/integrations.php) → `AppServiceProvider` binds `FcmPushSender` only when `PUSH_ENABLED=true` **and** the credentials path is set; otherwise `NullPushSender`. After editing `.env`:

```bash
php artisan config:clear
php artisan queue:restart   # workers must pick up the new binding
```

**Sanity check the binding** (Tinker):
```php
app(\App\Services\Push\PushSender::class);   // → App\Services\Push\FcmPushSender when wired, else NullPushSender
```

---

## 4. Mobile app API contract

All endpoints are under `/api/v1`, authenticated with the Sanctum bearer token from `POST /api/v1/auth/login` (`Authorization: Bearer <token>`). General auth/versioning: [api/MOBILE-API.md](../api/MOBILE-API.md). Success envelope is `{ data?, message? }`.

### 4.1 Register / refresh a device token
`POST /api/v1/me/devices`

```json
{ "platform": "ios", "token": "<FCM registration token>", "device_name": "iPhone 15" }
```
- `platform` — `ios` | `android` (required).
- `token` — the **FCM registration token** from the Firebase SDK (required, ≤512 chars). On iOS this is the FCM token, **not** the raw APNs token — let the Firebase SDK exchange it.
- `device_name` — optional, ≤255 chars. Used as the upsert key with `(tenant, platform)`, so pass a **stable** per-device value (don't randomize per launch, or you'll stack rows).

Response `201`:
```json
{ "data": { "id": 12, "platform": "ios", "device_name": "iPhone 15", "created_at": "2026-07-15T10:00:00+00:00" },
  "message": "Device registered for push notifications." }
```
The token is **write-only** — never echoed back. Registering the same `(tenant, platform, device_name)` again just refreshes the token (upsert), so it's safe to call on every login and on every FCM token-refresh callback. If the exact token was registered under a different tenant (shared/handed-over phone), that stale row is dropped so pushes can't leak across tenants.

### 4.2 Unregister a device (on logout)
`DELETE /api/v1/me/devices/{id}` → `200 { "message": "Device removed." }`, or `404` if the id isn't one of the caller's devices. Use the `id` from the register response. (You don't strictly need to unregister — a dead token self-prunes on the next send — but doing it on logout is cleaner and immediate.)

### 4.3 In-app inbox (mirror of what was pushed)
| Method & path | Purpose |
|---|---|
| `GET /api/v1/me/notifications` | Paginated list (the `database` channel rows for this tenant). |
| `GET /api/v1/me/notifications/unread-count` | Badge count. |
| `POST /api/v1/me/notifications/{id}/read` | Mark one read. |
| `POST /api/v1/me/notifications/read-all` | Mark all read. |

### 4.4 The push payload (what lands on the device)
FCM message shape the app receives:
```jsonc
{
  "notification": { "title": "Invoice ATR-2026-0007", "body": "EGP 12,340.00 is due on 2026-08-01." },
  "data": { "type": "InvoiceIssuedNotification", "invoiceId": "7", "invoiceNumber": "ATR-2026-0007", "dueDate": "2026-08-01" }
}
```
- **camelCase keys, same as every `/api/v1` response.** Push is an *outbound* call to FCM, so it never passes through the `CamelCaseResponseKeys` middleware — `PushChannel::wireData()` re-cases it explicitly (via the same `KeyCase` helper) so the app can read `data.invoiceId` exactly as it does from the inbox. Don't remove that: the app resolves the deep-link target from these id fields, so snake_case here silently lands every tap on a null id.
- **`data.type` is the short class name** (`InvoiceIssuedNotification`) — the *same* vocabulary `GET /me/notifications` returns in `NotificationResource::type`, **not** `toDatabase()`'s internal bell slug. The app deliberately routes a push tap through the same mapper as an inbox tap, so the two must match.
- **All `data` values are strings** (FCM requirement) — cast on the client (`int.parse(data['invoiceId'])`).
- The id fields (`invoiceId`, `paymentId`, `maintenanceId`, …) tell the app which record to open. Render hints used by the web bell (`icon`, `color`, `format`, `duration`) are stripped from push.
- Both rules are pinned by a regression test in `tests/Feature/PushNotificationTest.php` ("ships the push payload in the app wire contract").
- A deep-link scheme for the "Open the app" button on the public payment page is configurable via `APP_DEEP_LINK` (e.g. `atriom://invoices`).

**Client responsibilities:** request notification permission; obtain the FCM token via the Firebase SDK; `POST /me/devices` on login and on the SDK's token-refresh callback; handle foreground messages (show an in-app toast) and background/tap (deep-link using `data`); `DELETE` on logout.

---

## 5. Verify end-to-end

1. **Backend wired?** `app(PushSender::class)` is `FcmPushSender` (§3).
2. **Worker running?** `php artisan queue:work` (or supervisor) — push is a queued job.
3. **Device registered?** After the app logs in, a row exists: `Tenant::find($id)->deviceTokens` is non-empty.
4. **Trigger a real event** for that tenant — e.g. issue an invoice or capture a payment — and confirm the phone receives it. Quick manual trigger in Tinker:
   ```php
   $tenant = \App\Models\Tenant::has('deviceTokens')->first();
   $tenant->notify(new \App\Notifications\PaymentReceivedNotification(\App\Models\Payment::latest()->first()));
   ```
5. **Watch for failures:** `OpsLog` keys `push.fcm_auth_failed` (bad creds), `push.fcm_misconfigured` (no project id), `push.fcm_send_failed` (per-token non-fatal), `push.fcm_exception` (network). None of these fail the triggering event.

**Common "no delivery" causes:** iOS with no APNs key uploaded (§2.2); worker not running (job never executes); `FCM_CREDENTIALS` path wrong/unreadable; the app sent an APNs token instead of the FCM token on iOS; the app randomizes `device_name` so tokens don't upsert.

---

## 6. Operations & security

- **Credential rotation:** generate a new service-account key in Firebase, point `FCM_CREDENTIALS` at it, `config:clear` + `queue:restart`, then delete the old key in Firebase. The cached OAuth token (key `fcm.access_token`, ~55 min TTL) refreshes automatically.
- **Token hygiene is automatic:** dead tokens are pruned on send (§1). No cron needed.
- **Secrets:** the service-account JSON grants send rights to your Firebase project — keep it off git, `chmod 600`, and don't ship it to client devices (clients only get `google-services.json` / `GoogleService-Info.plist`, which are not secrets).
- **Staging vs prod:** use separate Firebase projects so a staging blast can't hit production devices.

---

## 7. Event coverage

Events that currently push (all tenant-facing): **Invoice issued**, **Payment received**, **Maintenance status changed**, **Maintenance comment added**, **Sales declaration locked**, **Overdue-invoice reminder** (`billing:remind-overdue-tenants`), **Late fee applied**, **Lease-expiry reminder** (`leases:remind-expiring`), **Operator announcements** (broadcast to a property's active tenants — see [modules/27-announcements.md](../modules/27-announcements.md)). See [modules/19-notifications-scans.md](../modules/19-notifications-scans.md) §7 for the authoritative table.

---

## 8. Tests

- `tests/Feature/PushNotificationTest.php` — channel fan-out + payload shaping, `NullPushSender` default, dead-token pruning, `FcmPushSender` OAuth+v1 send and 404/`UNREGISTERED` classification.
- `tests/Feature/Api/V1/DevicesTest.php` — register/refresh/unregister contract + cross-tenant isolation.
- `tests/Feature/Scenarios/NotificationFlowScenarioTest.php` — end-to-end notification fan-out including the Tenant leg.
