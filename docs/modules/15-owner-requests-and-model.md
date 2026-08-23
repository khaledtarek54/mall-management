# Owner Requests & Owner Model

> Jawad (owner) users raise requests to the operator team or to other owners for coordination; immutable once closed/cancelled; scoped to their owned properties via RBAC + `asset_owner` pivot; no separate /owner portal—owners are RBAC users in the admin app.

## 1. Purpose & business context

The Owner Requests module enables legal property owners (Jawad) to communicate with the Eltizam (operator) team and with each other. A Jawad can raise a ticket to escalate maintenance issues, coordinate shared-property matters (e.g. facade work budget split), or request information. Operators respond and track resolution. Owners see read-only oversight of their portfolio (invoices, maintenance, CAM) + the ability to raise + track their own requests.

**Real-world scenario**: Owner A and Owner B co-own a property. A needs to confirm the budget split for shared facade repairs → raises an owner-to-owner request to B. B reviews it, agrees, and resolves it with notes. Both stay synchronized without manual emails.

## 2. Domain model

| Table | Model | Key columns | Meaning |
|-------|-------|-------------|---------|
| `owner_requests` | `OwnerRequest` | `reference` (string, unique, auto-generated `OR-YYYY-NNNN`) | Auto-incrementing ticket ID scoped to calendar year |
| | | `created_by_user_id` (FK users) | Jawad owner who raised the request |
| | | `recipient` (enum: `operator` \| `owner`) | Recipient type: operator team or a specific owner |
| | | `assigned_to_user_id` (FK users, nullable) | If recipient='owner', the owner to escalate to; if recipient='operator', null |
| | | `asset_id` (FK assets, nullable) | Related property (optional scoping hint) |
| | | `subject` (string, ≤150 chars) | Ticket title |
| | | `body` (text) | Full description |
| | | `priority` (enum: `low` \| `medium` \| `high`) | Severity; defaults to medium |
| | | `status` (enum: `open` \| `in_progress` \| `resolved` \| `closed` \| `cancelled`) | Lifecycle state |
| | | `scheduled_from`, `scheduled_to` (datetime, nullable) | Work window for the request (FR REQ-1) |
| | | `resolution_notes` (text, nullable) | Operator/owner's response & closure reason |
| | | `resolved_at`, `closed_at` (datetime, nullable) | Timestamps when moved to resolved/closed |
| | | `created_at`, `updated_at`, `deleted_at` (soft delete) | Audit trail |
| `asset_owner` | `User::ownedAssets()` | `user_id`, `asset_id` | Legal ownership link (distinct from staff assignment `asset_user`) |
| | | `ownership_percentage` (decimal 5,2) | Jawad's % stake in property (often 100, can be split) |
| | | `started_at`, `ended_at` (date) | Ownership period (nullable = ongoing) |

**Relationships**:
- `OwnerRequest.creator()` → BelongsTo `User` (who raised it)
- `OwnerRequest.assignee()` → BelongsTo `User` (recipient owner, if recipient='owner')
- `OwnerRequest.asset()` → BelongsTo `Asset` (optional property context)
- `User.ownedAssets()` → BelongsToMany `Asset` via `asset_owner` (with pivot `ownership_percentage`, `started_at`, `ended_at`)
- `User.assignedAssets()` → BelongsToMany `Asset` via `asset_user` (staff assignment, distinct from ownership)
- `Asset.owners()` → BelongsToMany `User` via `asset_owner`

## 3. Business rules & invariants

| Rule | Enforcement | Test(s) |
|------|------------|---------|
| **Reference uniqueness** | Unique constraint on `owner_requests.reference`; generated as `OR-{YYYY}-{NNNN}` incrementing per calendar year | `OwnerRequestTest::creates_an_owner_request_with_a_reference_and_notifies_the_operator_team` |
| **Recipient routing: operator** | If `recipient='operator'`, notification goes to all `manager` + `super_admin` users only; sibling owners are NOT notified | `OwnerRequestScenarioTest::routes_an_owner→operator_request_to_the_operator_team_only` |
| **Recipient routing: owner** | If `recipient='owner'`, must have `assigned_to_user_id` set; notification goes ONLY to that owner; operator team is bypassed | `OwnerRequestScenarioTest::routes_an_owner→owner_request_to_the_assigned_owner_only_not_the_operator_team` |
| **Only creator sees their request** | Owner in list query sees only requests where `created_by_user_id = Auth::id()`, regardless of whether they're the assignee of an owner-directed request from someone else | `OwnerRequestScenarioTest::an_owner_sees_only_their_OWN_requests_across_both_recipient_types` |
| **Operator inbox scoping** | Operators (manager/super_admin) see ONLY `recipient='operator'` requests, not owner-to-owner traffic | `OwnerRequestResourceTest::operator_inbox_shows_operator_directed_requests_only` |
| **Terminal immutability** | Closed or cancelled requests are locked—UI hides respond action; service does not prevent update but UI gate + permissions block it. See 4. | `OwnerRequestScenarioTest::hides_the_respond_action_on_a_terminal_request_even_from_an_operator` |
| **Status transitions** | Valid paths: `open` → (`in_progress` \| `resolved` \| `cancelled`) → `closed` or terminal; implementation does not enforce but UI predicate prevents respond on closed/cancelled | `OwnerRequestScenarioTest::treats_closed_and_cancelled_requests_as_terminal_and_not_open` |
| **Resolved/closed timestamps** | `resolve` sets `resolved_at = now()`, `close` sets `closed_at = now()` | `OwnerRequestTest::transitions_status_stamps_resolved_at_and_notifies_the_owner` |
| **Property picker scoping (REQ-1 security)** | An owner can pick only properties from `accessibleAssets()` (union of owned + staff-assigned); super_admin sees all real properties | `OwnerRequestScenarioTest::scopes_the_asset_id_picker_to_the_OWNER's_owned_properties_only` |
| **Resolution notes on resolve** | If `status → resolved`, `resolution_notes` from the transition payload is recorded; defaults to existing value if not provided | `OwnerRequestScenarioTest::notifies_the_assigned_owner_recipient_when_an_owner_to_owner_request_is_progressed` |

## 4. Lifecycle / state machine

| Status | Transitions to | Meaning | Timestamp | Terminal? |
|--------|---|---------|-----------|-----------|
| `open` | `in_progress`, `resolved`, `cancelled` | Freshly raised; awaiting response | created_at | No |
| `in_progress` | `resolved`, `cancelled`, (back to open) | Responder has started work | — | No |
| `resolved` | `closed` | Responder has finished; owner has reviewed and accepted resolution notes | resolved_at | No |
| `closed` | — | Locked; no further action. Marks end of lifecycle | closed_at | **Yes** |
| `cancelled` | — | Request abandoned or withdrawn; locked | — | **Yes** |

**Triggering transitions**:
- Create: starts in `open` (hardcoded in `OwnerRequestService::create()`)
- Operator/owner reply: `OwnerRequestService::reply($request, $author, $body, ?$status)` (the OwnerRequestsTable **Reply** action) — see the conversation note below.
- Legacy status-only move: `transition(request, status, ['resolution_notes' => '...'])` is retained for programmatic callers.

### Conversation thread (module 15 UX pass)

Owner requests are a communication *channel* but had no conversation: the whole exchange was the
owner's opening message plus a single `resolution_notes` field the operator overwrote — and which was
**silently dropped unless** the status happened to be set to `resolved`. So an operator replying "we're
looking into it" while moving it to *in-progress* lost their message, and there was no back-and-forth.

Now `owner_request_replies` (a `HasMany` thread, oldest-first, immutable once posted) is the
conversation. The **Reply** action shows the whole thread inline (opening message + every reply,
attributed and timestamped), takes a **required message that is always saved** regardless of status,
and lets an **optional status move ride along**. Each reply notifies the *counterparty* (the owner when
the operator replies; the operator team when the owner replies), never the author. The list shows a
**reply-count** badge. `OwnerRequestReply` is a property-owned chain model (`=> 'ownerRequest'`), no
resource of its own — it is posted only through the Reply action.
- UI guard: respond action hidden if `canEdit($record) && ! $record->isTerminal()` (line 77, OwnerRequestsTable)

**Notification on transition**:
- Always notifies `$request->creator()` (the raiser) with event='updated' when status changes
- Does NOT notify the assignee of an owner-directed request on transition (only the creator)

## 5. Services, jobs & scheduled commands

### `OwnerRequestService::create(array $data, User $owner): OwnerRequest`
- **Signature**: Takes form data + the authenticated owner creating it
- **What it does**:
  1. Generates unique reference via `OwnerRequest::generateReference()` (OR-YYYY-NNNN)
  2. Creates row with `status = 'open'`, `created_by_user_id = $owner->id`
  3. Calls `notifyRecipients($request)` (see below)
  4. Returns the persisted request
- **Transactional**: Wrapped in `DB::transaction()`
- **Called from**: `CreateOwnerRequest::handleRecordCreation()` (Filament page)
- **Test**: `OwnerRequestTest::creates_an_owner_request_with_a_reference_and_notifies_the_operator_team`

### `OwnerRequestService::transition(OwnerRequest $request, string $status, array $extra = []): OwnerRequest`
- **Signature**: Progresses request status + optional metadata
- **What it does**:
  1. Updates `status` in payload
  2. If `status = 'resolved'`: sets `resolved_at = now()`, captures `$extra['resolution_notes']` (or keeps existing)
  3. If `status = 'closed'`: sets `closed_at = now()`
  4. Calls `$request->update($payload)`
  5. Notifies the **creator** (raiser) with event='updated' via `OwnerRequestNotification`
  6. Returns refreshed record
- **Error handling**: Silently logs notification failures; does not rollback state change
- **Called from**: `OwnerRequestsTable::respond()` action (inline modal on the list view)
- **Test**: `OwnerRequestTest::transitions_status_stamps_resolved_at_and_notifies_the_owner`

### `OwnerRequestService::notifyRecipients(OwnerRequest $request): void` (private)
- **Recipient routing**:
  - If `recipient = 'operator'`: sends to all users with role `super_admin` or `manager` only
  - If `recipient = 'owner'`: sends ONLY to `$request->assignee` (must be set)
  - Catches and logs exceptions; never throws
- **Notification class**: `OwnerRequestNotification` (event='submitted' on initial create)
- **Test**: `OwnerRequestScenarioTest` recipient routing tests

## 6. Filament resources & key fields

### Admin Resource: `OwnerRequestResource` (app/Filament/Admin/Resources/OwnerRequests/OwnerRequestResource.php)

**Not tenant-scoped**: `isScopedToTenant = false` (owner requests cross all properties; they're global).

**Permissions** (via `RoleGatedActions` trait):
- `canViewAny()` / `canView()`: Requires `owner_requests.view` permission
  - **owner** role: Has it (read-only access; every `.view` permission granted)
  - **manager/super_admin**: Has it
  - **department roles** (leasing, operations, etc.): Do NOT have it
- `canCreate()`: Requires `owner_requests.create` permission
  - **owner**: Has it (allowed to raise requests)
  - **manager/super_admin**: Has it
  - **viewer, department roles**: Do NOT have it
- `canEdit()` (respond/update): Requires `owner_requests.edit` permission
  - **owner**: Does NOT have it (can raise, not respond)
  - **manager/super_admin**: Has it (can respond to all requests)
  - **others**: Do NOT have it
- `canDelete()`: Only **super_admin** (project-wide policy; {module}.delete permission ignored)

**Query filtering**:
- If `canEdit($r)` (operator): Shows only `recipient = 'operator'` (operator inbox)
- Else (owner): Shows only `created_by_user_id = Auth::id()` (own requests)
- Eager loads `creator`, `asset`, `assignee`

**Navigation badge**: Shows count of open requests (status in `OPEN_STATUSES = ['open', 'in_progress']`)

### Form: `OwnerRequestForm` (app/Filament/Admin/Resources/OwnerRequests/Schemas/OwnerRequestForm.php)

| Field | Type | Validation | TenantScope Notes |
|-------|------|-----------|-------------------|
| `recipient` | Enum Select | Required, native=false | Both owner & operator can pick |
| `assigned_to_user_id` | User Select | Required if recipient='owner'; filtered to owner role + exclude self | Visible only when recipient='owner' |
| `asset_id` | Asset Select | Not required; nullable | **Scoped to owner's accessibleAssets()** (union of owned + staff-assigned); super_admin sees all real properties excluding 'ALL' |
| `priority` | Enum Select | Required, default='medium' | Hardcoded: low/medium/high |
| `subject` | Text Input | Required, ≤150 chars | — |
| `body` | Textarea | Required, 4 rows | — |
| `scheduled_from` | DateTime Picker | Nullable; no seconds | For FR REQ-1 (scheduled work window) |
| `scheduled_to` | DateTime Picker | Nullable; no seconds | For FR REQ-1 (scheduled work window) |

### Table: `OwnerRequestsTable` (app/Filament/Admin/Resources/OwnerRequests/Tables/OwnerRequestsTable.php)

| Column | Sortable | Searchable | Notes |
|--------|----------|-----------|-------|
| `reference` | No | Yes | Monospace, xs size; e.g. OR-2026-0001 |
| `subject` | No | Yes | Truncated to 40 chars, medium weight |
| `creator.name` | No | Yes | The raising owner |
| `asset.name` | No | No | Property (if set); placeholder "—" if null |
| `priority` | No | No | Badge; color: danger=high, warning=medium, gray=low |
| `status` | No | No | Badge; color: info=open, primary=in_progress, success=resolved, gray=closed, danger=cancelled |
| `created_at` | Yes | No | Format d/m/Y |

**Filters**:
- `status`: Multi-select from enum values (live-formatted headlines)
- `TrashedFilter`: Soft-deleted records togglable

**Record actions**:
- **respond** (modal): Visible if `canEdit($record) && ! $record->isTerminal()`
  - Modal heading: Reference (e.g. OR-2026-0001)
  - Modal description: "subject — body"
  - Fields: status (required enum select), resolution_notes (optional textarea, 3 rows)
  - Action: Calls `OwnerRequestService::transition()`, shows success notification

**Default sort**: `created_at desc` (newest first)

## 7. Notifications & integrations

### `OwnerRequestNotification`
- **Channel**: Database only (no email/SMS in v1; bell notifications in Filament)
- **Payload**:
  ```php
  [
      'type' => 'owner_request',
      'event' => 'submitted' | 'updated',
      'owner_request_id' => $request->id,
      'reference' => $request->reference,
      'subject' => $request->subject,
      'status' => $request->status,
      'title' => 'New owner request' | 'Owner request updated',
      'body' => "{reference}: {subject}" | "{reference} is now {status}.",
      'icon' => 'heroicon-o-inbox',
      'color' => 'warning' if priority='high' else 'info',
      'url' => null,
      'format' => 'filament',
      'duration' => 'persistent',
  ]
  ```
- **On create**: event='submitted' → recipients (operator team or assigned owner)
- **On transition**: event='updated' → **creator only** (the raiser gets the update)
- **Error handling**: Wrapped in try-catch; logged but never throws

### External integrations
None currently. Owner requests do not integrate with Paymob, ETA, or external systems. They're internal communication only.

## 8. Extension points — how to change/extend SAFELY

### To add a new status or lifecycle state
1. Update `OwnerRequest::STATUSES` constant (add to array)
2. Update migration (alter enum constraint) if a new DB state is needed
3. Add test in `OwnerRequestScenarioTest` for the transition path
4. Update `OwnerRequestsTable` badge color mapping if the state has unique UX
5. **Do NOT break**: `isOpen()` or `isTerminal()` invariants unless intentional; these guard query filters and UI visibility

### To add a new notification trigger (e.g., on escalation)
1. Create new `*Notification` class in `app/Notifications/`; use existing `OwnerRequestNotification` as template
2. Emit from appropriate service method or observer
3. Test in `OwnerRequestScenarioTest` with `Notification::fake()` + `assertSentTo()`
4. **Do NOT break**: The `submitted` / `updated` event distinction; external systems may key off it

### To add a new query filter or view mode
1. Override `OwnerRequestResource::getEloquentQuery()` query builder logic (currently scopes by recipient + creator)
2. Test with new scenario in `OwnerRequestResourceTest` or `OwnerRequestScenarioTest`
3. If filtering impacts RBAC, update `canViewAny()` / `canView()` gates in `RoleGatedActions`
4. **Do NOT break**: Owner query-scoping (`created_by_user_id = Auth::id()`) or the operator-inbox filter (`recipient = 'operator'`)

### To add a new field to the form
1. Edit `OwnerRequestForm::configure()` to add form component
2. Add the column to the `owner_requests` table migration if DB-backed
3. Add it to `OwnerRequest::$fillable` if not already mass-assignable
4. Update the table's `TextColumn` list to show it
5. Test form rendering in `OwnerRequestResourceTest` (e.g. `rendersTheAdminOwnerRequestsList`)
6. **Do NOT break**: Existing field scoping (e.g. asset_id picker scope) unless intentional

### To change permission levels
1. Edit `RolesPermissionsSeeder::PERMISSIONS['owner_requests']` to add or rename permissions
2. Update the corresponding role's sync call (e.g. owner role assignment at line ~235)
3. Re-run `php artisan db:seed --class=RolesPermissionsSeeder`
4. Test with new role in `OwnerRequestResourceTest` (e.g. owner/manager/super_admin gates)
5. **Do NOT break**: `RoleGatedActions` trait's permission-checking logic or custom roles created via UI become invalid

### To modify owner-scoping (asset access for owners)
1. Check `User::accessibleAssets()` (line 123) — controls what an owner can pick in the property dropdown
2. It's a union of `ownedAssets()` (via `asset_owner`) and `assignedAssets()` (via `asset_user`)
3. To restrict or expand, edit the union logic
4. Test with `OwnerRequestScenarioTest::scopes_the_asset_id_picker_to_the_OWNER's_owned_properties_only` and the scenario test for staff-assigned properties
5. **Do NOT break**: The unique constraint on `asset_owner (user_id, asset_id)` or the cascade deletes

## 9. Gotchas, edge cases & recently-fixed bugs

### Immutability & terminal state
- **Gotcha**: The service `transition()` method does NOT prevent updating a closed request (no guard). The UI layer (`OwnerRequestsTable`) hides the respond action via the `canEdit($r) && ! $r->isTerminal()` predicate. If someone directly calls the service, they can mutate a closed record. **Solution**: Always check `!isTerminal()` before allowing state changes outside the UI.
- **Test**: `OwnerRequestScenarioTest::hides_the_respond_action_on_a_terminal_request_even_from_an_operator` (line 237)

### Notification for owner-to-owner requests
- **Gotcha**: When an owner-to-owner request is transitioned (e.g. assigned owner responds), `OwnerRequestService::transition()` notifies the **creator** (raiser) only, not the assignee. The assignee already got the initial "submitted" notification. This is intentional but can be confusing. **Solution**: Review the `notifyRecipients()` and `transition()` separation; if you need two-way updates, add a separate transition-notification path.
- **Test**: `OwnerRequestScenarioTest::notifies_the_assigned_owner_recipient_when_an_owner_to_owner_request_is_progressed` (line 99)

### Query scoping for owners
- **Gotcha**: An owner-directed request **assigned to me** but raised by someone else does NOT appear in my inbox (I see only requests I **raised**). The resource query filters by `created_by_user_id = Auth::id()` for owners. This is a deliberate design: owners manage their own escalations, not incoming requests from peers. **Solution**: If you want owners to see incoming requests, change the owner query filter to `created_by_user_id = Auth::id() OR assigned_to_user_id = Auth::id()`.
- **Test**: `OwnerRequestScenarioTest::an_owner_sees_only_their_OWN_requests_across_both_recipient_types` (line 190), line 199 regression

### Property picker security (REQ-1 leak fixed)
- **Recently fixed**: The asset_id dropdown was initially not scoped to an owner's owned properties; any owner could pick any property. Now it's scoped via `accessibleAssets()`. **Do NOT revert**.
- **Test**: `OwnerRequestScenarioTest::scopes_the_asset_id_picker_to_the_OWNER's_owned_properties_only` (line 253)

### Notification failure isolation
- **Gotcha**: If the notification send fails (e.g. database is down), the exception is caught, logged, but the request is still created/updated. No rollback. This is intentional to prevent mail outages from blocking operations. **Solution**: Monitor logs for `Owner request submit notification failed` / `Owner request update notification failed`.
- **Code**: `OwnerRequestService` lines 58–66 (transition) and lines 72–87 (notifyRecipients)

### Soft delete + reference generation
- **Gotcha**: The reference generator counts deleted records: `whereYear('created_at', $year)->withTrashed()->count()`. This means a year's counter is never reset even if you force-delete a request. This prevents reference reuse across undo scenarios. **Do NOT change** unless you have a retention policy.
- **Test**: None explicit; relies on migration idempotence test

### Operator vs owner RBAC confusion
- **Gotcha**: In the context of owner requests, "operator" in the `recipient` enum refers to the **operator role/team** (super_admin + manager). This is NOT the same as the `operations` department role. Only users with role `manager` or `super_admin` see operator-directed requests in the inbox. The `operations` department role has NO visibility into owner requests.
- **Clarification**: Line 81–83 in `OwnerRequestService`

## 10. Tests & related modules

### Test files
- **`tests/Feature/OwnerRequestTest.php`** (69 lines): Core service behavior (create, transition, notification routing)
- **`tests/Feature/OwnerRequestResourceTest.php`** (66 lines): Resource RBAC gates (operator inbox, owner scoping, canCreate/canEdit)
- **`tests/Feature/Scenarios/OwnerRequestScenarioTest.php`** (314 lines): Comprehensive lifecycle, notification, property picker scoping, terminal immutability, regression tests

### Run tests
```bash
php artisan test tests/Feature/OwnerRequestTest.php
php artisan test tests/Feature/OwnerRequestResourceTest.php
php artisan test tests/Feature/Scenarios/OwnerRequestScenarioTest.php
```

### Related modules (links to docs)
- **docs/modules/01-properties-units.md**: The `Asset` model, ownership (asset_owner) vs staff assignment (asset_user), property scoping
- **docs/modules/18-rbac-scoping.md**: RBAC roles (owner, manager, super_admin, department roles), the Spatie permissions trait, `User::accessibleAssets()` union
- **docs/modules/19-notifications-scans.md**: Filament database notification channel, `OwnerRequestNotification` payload structure
- **docs/modules/XX-maintenance-requests.md** (TBD): Maintenance requests are distinct; owner requests are owner ↔ operator coordination; maintenance is operator ↔ tenant/vendor

### Activity log
- OwnerRequest logs are recorded under log name `'owner_request'` and only log changes to `['recipient', 'assigned_to_user_id', 'status', 'priority', 'subject']` via Spatie ActivityLog. Sensitive data (body, resolution_notes) are NOT logged.
- Access via `/admin/activity-log` with `activity_log.view` permission.
