# Announcements — mall news

> Operator broadcasts to a property's tenants. Staff compose a notice in both languages, optionally
> with artwork, and it is delivered to every active tenant of that property via the in-app bell +
> mobile push (no email) **and kept as a post they can read again** — in the mobile app's mall-news
> feed and on the web portal. Every recipient is recorded, and so is whether they opened it.
>
> Property-owned; each announcement targets exactly one property.

## 1. Purpose & business context

Eltizam staff sometimes need to tell all tenants of a mall something at once — "the garage is closed
Friday", "Ramadan hours start Monday", "fire drill at 3pm". This module is that channel.

It used to be **only** that channel — a bell row and an FCM push, and nothing else. There was no API
endpoint, no portal screen, and no way back to the notice: once it scrolled out of the inbox it was
gone, and the record was not retrievable by the app at all. It was also monolingual, in a market
whose tenants read Arabic, so every recipient read whatever language the operator happened to type.

Since 2026-08-15 an announcement is a **post**:

| | Before | Now |
|---|---|---|
| Read again later | ✗ (a bell row only) | ✓ `GET /api/v1/me/announcements` + `/portal/announcements` |
| Arabic | ✗ one `title`/`body` | ✓ `title_ar`/`body_ar`, resolved per reader |
| Artwork | ✗ | ✓ one hero image, **private disk** |
| Category | ✗ | ✓ general · operations · event · emergency · hours |
| Write now, send later | ✗ composing WAS sending | ✓ draft · scheduled · sent |
| Falls off the feed | ✗ | ✓ optional `expires_at` |
| Who read it | ✗ (`recipients_count` only) | ✓ per-tenant read receipts |

It remains **informational and one-way** — no email (blasts shouldn't fill inboxes) and no reply.

## 2. Domain model

| Table/Model | Key columns | Meaning |
|---|---|---|
| `announcements` / `Announcement` | `asset_id` (the target property), `title`/`title_ar`, `body`/`body_ar`, `category`, `status`, `publish_at`, `expires_at`, `is_pinned`, `created_by`, `sent_at`, `recipients_count` | One notice. Soft-deletes. `hero` media collection (single file, **`local`** disk). |
| `announcement_recipients` / `AnnouncementRecipient` | `announcement_id`, `tenant_id`, `notified_at`, `read_at`, `read_by_tenant_user_id` | One tenant's copy: that it was sent to them, and whether they opened it. Unique on `(announcement_id, tenant_id)`. |

`status` — `draft` · `scheduled` · `sent`. `category` — `general` · `operations` · `event` ·
`emergency` · `hours`. Both are strings with their sets in `App\Support\ValueSets`, never DB enums
(house rule).

**`status` says intent; `sent_at` records what happened.** They are stamped together, and only ever
by `SendAnnouncementAction`. Nothing in the UI writes `status = sent` — a page that did would
produce a record claiming a broadcast still sitting in a queue.

**Property isolation:** `Announcement` is **OWNED** with a direct `asset_id`;
`AnnouncementRecipient` is **OWNED** through `'announcement'` (the property is the notice's, never
re-derived from the tenant — a retailer trades in more than one mall). Registered in
`App\Support\PropertyIsolation`. Reads are scoped by the resource's own `getEloquentQuery()`; the
write is guarded by `assertAssetInScope` on **create and edit** (Filament only stamps `asset_id` on
create).

**"Active tenant of a property"** = a `Tenant` holding an **active** `Lease` covering a `Unit` in
that property — via the `lease_unit` pivot (`whereHas('activeLeases.units', units.asset_id =
target)`), **not** `leases.unit_id`, which is only the *master* unit and would miss a multi-unit
lease whose additional unit sits in the target property.

## 3. Business rules & invariants

### The ONE visibility predicate: `Announcement::liveFor($tenant)`

The list endpoint, the detail endpoint, the unread badge (mobile summary **and** portal nav), and
the portal table all call it; none re-derives it.

```
status = sent
AND a recipient row exists for <tenant>
AND (expires_at IS NULL OR expires_at >= now)
```

**It keys on the recipient row, not on property membership**, and that is load-bearing rather than
an optimisation. Membership changes: a retailer who signs a lease on the 10th would suddenly see
September's notices as if they had been there, and a retailer who moves out loses the notice they
were actually sent — including the one they are arguing about.

There is deliberately **no start clause** — `sent_at` IS the start. A second one would let a notice
be pushed to a phone while absent from the feed the push deep-links into.

Feed ORDER is likewise one method — `feedOrder()`: pinned, then newest.

### Other rules

1. **One property per announcement.** `asset_id` is required; the fan-out only reaches that
   property's active tenants. Other properties' tenants never receive it (tested).
2. **Only active tenants**, evaluated at send time. A tenant whose lease activates after the
   broadcast never receives it and never sees it — by design (a point-in-time blast, not a
   subscription), and now enforced by the recipient row rather than by a re-derived query.
3. **A sent notice is immutable.** Tenants hold a push quoting its text and the recipient rows
   record who. `isEditable()` answers false, `canEdit()` refuses, and the edit page re-checks the
   record's own state because the key arrives from the browser. Correct it by sending another
   notice — the only correction a tenant can actually see. A **draft or scheduled** notice is
   ordinary editable content.
4. **Bell + push only, never email.** `AnnouncementNotification::via()` = `['database', 'push']`.
5. **Idempotent send.** `SendAnnouncementAction` no-ops when `sent_at` is set, so a retried job
   can't double-notify. `BroadcastAnnouncement` is `tries=1` as a second backstop, and the
   recipient table's unique key is a third.
6. **The recipient rows are written BEFORE the notifications go out.** The push deep-links into the
   post and the post is only visible to a tenant who has a row — so a row written afterwards would
   deep-link to a 404 for however long the gap lasted. Pinned by a test that observes from inside
   `NotificationSending`, because asserting after the fan-out passes whatever the order was.
7. **A failing recipient never strands the blast.** Each `notifyPortal()` is individually
   try/caught (failures logged to `OpsLog` as `announcement.recipient_failed`) and `sent_at` is
   stamped **even on a partial blast**. The recipient row stays either way with a null
   `notified_at`, which is where the misses are visible instead of quietly absent from a count.
8. **The first read is the one recorded.** Re-opening does not reset the timestamp — "when did they
   first see it" is answerable; "when did they last look" is not a question anyone asks.
9. **Sending is a separate permission from composing** (`announcements.send` vs
   `announcements.create`), because since notices gained a draft state the two stopped being the
   same act. Same reasoning as `marketing_posts.approve` vs `.edit`.

### Language

Both columns ship on every API payload and the client picks — same convention as `marketing_posts`.
The **bell and the push** are rendered server-side per recipient, so they resolve here:
`titleFor()`/`bodyFor()` read the ambient locale and **fall back to whichever language the operator
actually wrote**, so an English-only notice still reaches Arabic readers.

That is what finally gives `BellChannel`'s per-locale re-render something to do for announcements:
the channel has always stored every supported language under `data.i18n`, and with one text column
there was only ever one answer to store. An announcement's title is **operator content, not a
translation key** — the case `NotificationLocaleConformanceTest` exempts by name.

## 4. Services, jobs & commands

- **`App\Services\SendAnnouncementAction::handle(Announcement): int`** — the single-action
  broadcaster. Finds the property's active tenants, writes the recipient list in one
  `insertOrIgnore`, `notifyPortal()`s each (stamping `notified_at` per success), then stamps
  `status`/`sent_at`/`recipients_count`. No-op if already sent.
- **`App\Services\Announcements\MarkAnnouncementReadAction`** — stamps a read receipt. Idempotent,
  keeps the first read, no-op for a non-recipient.
- **`App\Jobs\BroadcastAnnouncement`** (`ShouldQueue`, `tries=1`) — runs the fan-out off the request
  thread. Dispatched from the create/edit pages when the operator chose "Send now".
- **`announcements:send-scheduled`** (every 15 minutes) — broadcasts `scheduled` notices whose
  `publish_at` has passed. Idempotent + lock-safe: each row is re-read and re-checked inside its own
  `lockForUpdate` transaction, registered in `App\Support\ConcurrencyPolicy`. `--dry-run`.
  Sends **inline** rather than dispatching the job — the scheduler is already off the request
  thread, and dispatching would put the fan-out beyond the lock that makes it safe.
- **`App\Notifications\AnnouncementNotification`** — `database` + `push`. Payload: `type:
  'announcement'`, `announcement_id`, `announcement_category`, a localized `title` and a 160-char
  `body` excerpt. The alert is a **pointer**, not the whole notice.

## 5. Surfaces

### `/admin` — `AnnouncementResource` (nav group *Marketing*)

List (tabs: All · Sent · Scheduled · Draft) → Create → View → Edit.

- **Form** in three sections: *Who it goes to* (property + category), *The message* (both languages
  side by side — not behind tabs, because a notice written in one language and not the other is the
  likeliest defect on this screen and a tab hides it — plus the hero image), *When it goes out*
  (`delivery` = now / schedule / draft, `expires_at`, `is_pinned`).
- **`delivery` is a choice, not the `status` column.** `TranslatesDeliveryChoice` maps it, once, for
  both pages.
- **Table** carries the read rate (`reads_count / recipients_count`, via `withCount`, deliberately
  not stored). **Send now** moved off the row on 2026-08-30 to the announcement's own Edit page
  (`App\Filament\Admin\Actions\AnnouncementActions`) — the list FINDS, the record ACTS — still gated
  twice on `canSend()` (`visible()` for the UI, `abort_unless` inside `action()` for the gate) with a
  confirmation naming the property, because there is no unsend.
- **`RecipientsRelationManager`** on the View page: who was sent it, who opened it, when, and which
  portal login. Read-only by construction.
- **Permissions:** `announcements.view` · `.create` · `.edit` · `.send` (`RolesPermissionsSeeder`).
  Granted to `super_admin` (all), `manager` (auto — all non-delete), `viewer`/`owner` (view, auto),
  and **`marketing`** (all four). Delete is `super_admin`-only (`RoleGatedActions::canDelete`).

**⚠️ Do NOT add `$tenantOwnershipRelationshipName` to this resource.** The operator *chooses* the
target property, so `asset_id` is client-supplied. Filament's tenancy registers a model `creating`
hook that force-associates `asset_id` with the **current panel tenant** — in "All Properties" mode
that tenant is the `ALL` pseudo-asset, so it would silently overwrite the chosen property and the
blast would reach **zero** tenants. Hence `BypassesFilamentTenantAutoScope` + an explicit
`getEloquentQuery()`. Guarded by a regression test asserting `isScopedToTenant()` stays false.

### `/portal` — `Portal\Announcements\AnnouncementResource`

The retailer's notice board: list (unread in bold, category chip, unread filter) → view. Strictly
read-only. An unread count badges the nav item, counted with the same predicate the list uses.
**Opening the view IS the read receipt** — unlike mobile, where a separate `POST …/read` exists
because the app also opens the detail endpoint to render a push preview.

A signed-out or tenant-less session gets `whereRaw('1 = 0')`: the failure mode of a null tenant id
must be "see nothing", never "see everything".

### `/api/v1` (Sanctum, `Tenant`)

| Route | |
|---|---|
| `GET me/announcements` | The feed. `?unread=1`, paginated, `feedOrder()`. |
| `GET me/announcements/{id}` | One notice in full. **Does not** mark it read. |
| `POST me/announcements/{id}/read` | The read receipt. Idempotent. |
| `GET me/announcements/{id}/hero/{media}` | Streams the artwork from the private disk. |
| `GET me/summary` | `+ unread_announcements` (wire: `unreadAnnouncements`). |

Not behind a module flag — `announcements` is deliberately absent from `Modules::KEYS`, because a
mall that cannot tell its tenants the garage is shut has no fallback channel. A notice the caller
was never sent returns **404, never 403** (no existence enumeration).

`App\Http\Resources\Api\V1\AnnouncementResource` is a **hand-written allowlist**: `recipients_count`,
`created_by`, `status`, `publish_at` and other tenants' receipts are absent by construction.
`read`/`read_at` are the caller's own receipt, read off an eager load constrained to them.

## 6. Extension points

- **Add channels** (e.g. email for `emergency`): add to `AnnouncementNotification::via()`; branch on
  `$this->announcement->category` if it should not apply to every notice.
- **A new category:** add to `Announcement::CATEGORIES`, `ValueSets::SETS['announcements.category']`
  and both `admin.announcements.categories.*` blocks. Nothing else branches on category except the
  bell colour.
- **Target selection** (chosen tenants instead of the whole property): the recipient table is
  already the shape for it — replace the query in `SendAnnouncementAction::handle()` with the
  chosen set and add a picker to the form. Keep the `sent_at` idempotency guard.
- **All-properties broadcast:** allow the `Asset::ALL_PROPERTIES_CODE` pseudo-asset and fan out
  across every visible property (mind isolation — reuse `TenantScope::visibleAssetIds()`).
- **"Who hasn't read it" chase:** the data is there (`recipients` where `read_at is null`). A
  reminder would be a second notification to that subset, with its own stamp column.

## 7. Gotchas

1. **`status` is not written by any form.** Only `SendAnnouncementAction` writes `sent`. If you add
   a surface that sets it, the record will claim a broadcast that may never have happened.
2. **The `hero` collection is on the `local` disk, unlike `marketing_posts`' hero.** That is the
   audience, not an oversight: a shopper reads a marketing card unauthenticated; a tenant notice's
   artwork can be an evacuation map. Registered in `MediaPrivacyConformanceTest`.
3. **A pre-2026-08-15 announcement has a *reconstructed* recipient list.** The true set at send time
   was never stored — it was a Collection inside a queued job — so the migration backfills the
   property's **current** active tenants, stamped with the notice's own `sent_at`.
   `recipients_count` is deliberately left alone: a reconstruction must not overwrite a
   measurement, so the two can disagree on historic rows and cannot on anything sent since.
4. **A stranded row (`sent_at` null) migrated to `draft`, not `sent`.** It used to mean "queued, and
   if it stays null the worker never ran" — unrecoverable, because the job is `tries=1`. As a draft
   it is visible and the Send action broadcasts it for real.
5. **`sent_at` is still NULL until the queued job runs** for a "Send now" notice. If it stays null,
   the queue worker isn't running (PRODUCTION-RUNBOOK §3).
6. **Push requires the FCM pipeline to be live** ([PUSH-NOTIFICATIONS.md](../integrations/PUSH-NOTIFICATIONS.md));
   until then the bell + the feed still deliver and push is a no-op.
7. **A soft-deleted unit drops its tenant from the blast.** `Unit` soft-deletes and the recipient
   query honours that scope. Under-delivers only (never leaks).
8. **`recipients_count` counts tenants, not notifications.** One tenant with 3 portal logins = 1
   recipient, 4 bell rows (Tenant + 3 users) — and exactly 1 recipient row.
9. **The read rate is derived, never stored.** `withCount('reads')` on the table. A stored counter
   would be a second truth that drifts the first time a receipt is stamped outside the one path.
10. **`announcements` is intentionally not in `Modules::KEYS`** — core, always on, can't be
    feature-flagged off.
11. **Changing `searchTextSources()` rewrites nothing on its own.** The Arabic columns joined the
    blob in this change — run `php artisan atriom:rebuild-search` on any database with existing
    announcements.
12. **In `tests/Feature/Api/V1`, one authenticated caller per test.** Laravel does not rebuild the
    auth guard between `getJson()` calls in a single test, so a second request with another
    tenant's token is still answered as the first — which makes an isolation assertion pass or fail
    for a reason unrelated to isolation. Controls live in their own `it()`.

## 8. Tests

- `tests/Feature/Announcements/AnnouncementBroadcastTest.php` — the fan-out: active tenants only
  (property + lease-status isolation), the `lease_unit` pivot case, idempotency, bell+push-only
  channels, real bell-row delivery, the create guard, and RBAC.
- `tests/Feature/Announcements/AnnouncementPostTest.php` — the recipient list and its ordering
  (observed from inside `NotificationSending`), read receipts and their idempotency, the
  draft/scheduled/sent lifecycle and the sweep (including dry-run and no-re-send), both languages
  from one broadcast plus the fallback, the deep-link payload, the compose screen's three delivery
  choices, the send/compose permission split, and the private media disk.
- `tests/Feature/Announcements/AnnouncementPortalTest.php` — portal scoping (every refusal paired
  with a positive control), expiry, read-on-view including *which login*, the nav badge, the
  read-only ceiling, and the signed-out "see nothing" failure mode.
- `tests/Feature/Api/V1/AnnouncementsTest.php` — the mobile feed: scoping, expiry, drafts excluded,
  pin order, both languages on the wire, the operator-side leak check, 404-not-403, the read
  receipt, the unread filter and the summary badge.
- Gates: `PropertyIsolationConformanceTest`, `DeletionPolicyConformanceTest`,
  `SearchPolicyConformanceTest`, `ScreenGuideConformanceTest`, `MediaPrivacyConformanceTest`,
  `ActionAuthzConformanceTest`, `NotificationDeepLinkConformanceTest`,
  `NotificationLocaleConformanceTest`, `ConcurrencyPolicyConformanceTest`,
  `NoDatabaseEnumsConformanceTest`, `AdminSmokeManifestConformanceTest`, `ApiSpecContractTest`.

---

**Document version:** 2026-08-15 | Laravel 13 + Filament 4


## A notice is never broadcast into a closed window (SW-151, fixed 2026-09-02)

`expires_at` was accepted unvalidated, so a notice could be sent with an end date already in the past
— or before its own `publish_at`. **The blast still goes out**: every tenant gets the push and the
bell, `announcement_recipients` records who was reached, and then the portal's own scope
(`whereNull('expires_at')->orWhere('expires_at', '>=', now())`) excludes it. The deep link every one
of them taps lands on nothing.

**And there is no way back.** `isEditable()` is false the moment a notice is sent — correctly,
because it is evidence: tenants hold a notification quoting its text, and
`announcement_recipients` records who. So the only repair is composing a SECOND notice to explain the
first, which is a worse thing to have to send than the original.

**`Announcement::assertSendable()` is the one rule**, on the model because three callers need the
same answer and only one of them has a form:

- **`SendAnnouncementAction`** — the gate.
- **`CreateAnnouncement::afterCreate()`** — the create-and-send path. The broadcast itself goes off
  the request thread and that job is `tries = 1` on the database queue, so a refusal inside it
  becomes a `failed_jobs` row the operator never sees: the record is created, the success toast
  shows, `sent_at` stays null, and nothing on screen says it was refused. The window check is cheap
  and needs no tenants, so it is asked on the request.
- **`announcements:send-scheduled`** — where an `expires_at` that was in the FUTURE when the notice
  was scheduled can be in the past by the time the sweep arrives, which no form rule can see.

**One refusal must cost only its own delivery.** The sweep's loop had no catch, so one expired notice
threw, every notice behind it in the same run went unsent, and the bad row stayed `scheduled` with a
past `publish_at` — which `dueToSend()` returns on every run, ordered by `publish_at`, so it came
first every time. The command runs every fifteen minutes: **every scheduled announcement in the
system would have stopped, permanently**, ~96 silent failures a day with nothing alerting. It catches
per row, reports the failures and exits non-zero, the same shape `GenerateRecurringExpensesService`
already uses.

**The form** bounds `expires_at` by `max(publish_at, now())`, not `publish_at ?: now()`: a scheduled
notice published next Tuesday may legitimately expire the Wednesday after, but `publish_at`'s state
survives a switch back to "Send now" and a scheduled time can slip into the past — either of which
would otherwise let an already-shut window through the form.

The idempotency guard still runs first, deliberately: a notice that HAS been sent and has since
expired is the ordinary end state of every notice ever, and re-entering the send path for it returns
the recorded count rather than throwing.

Tests: `ANoticeIsNotBroadcastIntoAClosedWindowTest` — the refusal with nothing recorded as sent, an
open window, no end date at all, a scheduled notice whose window shut while it waited, and the
already-sent case.
