# 31 · Violations

> A **violation recorded against a tenant**, with an optional cost/fine — the
> operator's record that a retailer breached a mall rule (blocked fire exit,
> unauthorised signage, after-hours noise). Covers exactly **FR-REQ-15/16/17**:
> record the violation + its fine, view it (property-scoped, RBAC-gated), and
> send the tenant a notice on an explicit operator action.

---

## 1. Purpose & business context

Eltizam's on-site teams need to log when a tenant breaks a mall rule and, where
applicable, the **fine** they assessed for it. The module is first a **register** —
it captures *what happened* and *how much was charged as a fine* so the operator has
an auditable record and can formally notify the tenant — and can then **bill** that
fine to the tenant as AR on an explicit operator action.

| FR | What it asks | How this module answers it |
|---|---|---|
| **FR-REQ-15** | record a violation against a tenant, incl. an associated cost/fine | `violations` row + nullable `fine_amount` |
| **FR-REQ-16** | violations viewable by authorized staff | property-scoped, RBAC-gated Filament resource |
| **FR-REQ-17** | send violation notices to the relevant tenant | an explicit **"Send notice"** record action (never on create) |
| *(completion)* | actually charge the fine | an explicit **"Bill fine"** action → a VAT-exempt AR invoice (see §5) |

> **Deliberately NOT built:** auto-billing on create (billing is always an explicit
> operator act, never a side effect of recording), and any workflow richer than
> `open` / `resolved`.

## 2. Domain model

### `violations` — one violation at one mall

| Column | Notes |
|---|---|
| `asset_id` | FK → `assets`, `cascadeOnDelete`. The mall where it occurred — the property-isolation anchor (direct `asset_id`, like Area / Equipment). |
| `tenant_id` | FK → `tenants`, `cascadeOnDelete`. The tenant it is against. **Tenant is SHARED** (a retailer may lease in several malls); the violation is pinned to the mall via `asset_id`, exactly like Invoice / Lease. |
| `description` | text, **required**. |
| `fine_amount` | `decimal(12,2)`, **nullable** — the associated cost/fine (FR-REQ-15). Recorded only. |
| `violation_date` | date, **required**, not in the future. |
| `status` | string, `open` / `resolved`, DB default `open` **and** model `$attributes` default `open` (a blank Select must never send `null` into the NOT-NULL column). |
| `notified_at` | timestamp, nullable — **when the tenant notice was sent** (FR-REQ-17). Null until the operator sends it. |
| `billed_invoice_id` | FK → `invoices`, nullable, `nullOnDelete` — the fine invoice this violation produced (the once-only + traceability anchor). Null until billed. |
| `billed_at` | timestamp, nullable — when the fine invoice was issued. |
| `notes` | nullable text. |
| `created_by_user_id` | FK → `users`, nullable, `nullOnDelete` — who recorded it (stamped on create). |
| soft deletes | trashed rows retained for history; Delete/Restore/ForceDelete on the Edit page. |

`Violation::reference` is a **display-only accessor** (`VIO-00042`) — there is no
stored reference column; the id is the natural key.

Relations: `asset()`, `tenant()`, `createdBy()` (all `BelongsTo`). `LogsActivity`
with `useLogName('violation')`.

## 3. Business rules & invariants

- **The debtor may be a LESSEE or an OWNER-OCCUPIER** (2026-08-18). `BillViolationFineService`
  resolves the party's active lease in the violation's property and, failing that, their unit
  ownership there — module 37's other kind of occupier, who bought the shop and holds no lease.
  Nothing downstream needed changing: `UnitOwnership implements BillableAgreement` and
  `IssueInvoiceService::issue()` takes the contract, not a `Lease`, which is how his صيانة is already
  raised. Only the lookup had been lease-shaped, so an owner could be fined in the register and the
  fine was then unbillable. The ownership lookup is **tenure-aware on the violation's date, not
  today**, so a later resale cannot move the debt onto the buyer. A party holding neither is still
  refused — there is no agreement to bill against.


- **Recording is not billing.** Creating a violation touches no Invoice / Charge /
  GL entry — `fine_amount` is a recorded number until an operator explicitly bills
  it. (`ViolationScenarioTest` asserts the invoice/charge/item counts are unchanged
  after *recording* a fined violation.)
- **Billing is explicit + once-only.** The **"Bill fine"** action issues a single
  VAT-exempt AR invoice (a `violation_fine` line → misc_income) and stamps
  `billed_invoice_id`; `isBilled()` then blocks a second bill and locks the
  fine/tenant/property fields (see §5). A fine can only be re-billed after its
  invoice is **cancelled** (which voids the GL entry) — never after `credited`.
- **The notice is explicit.** `notified_at` is stamped only by the **"Send notice"**
  action, never on create. FR-REQ-17 is "support *sending*" — an operator act.
- **Minimal lifecycle.** `open` → `resolved`. No invented workflow.
- **Property isolation.** `Violation` is registered `OWNED (direct asset_id)` in
  `App\Support\PropertyIsolation`. Reads scope via `BypassesScopingOnAll` +
  `$tenantOwnershipRelationshipName = 'asset'`; writes are guarded with
  `assertAssetInScope()` on **both** Create and Edit pages (Filament only stamps
  `asset_id` on create). `ViolationResource` is registered in
  `propertyIsolationMustGuardResources()` — `PropertyIsolationConformanceTest`
  gates all of this.
- **The tenant select can't leak.** The picker uses
  `TenantScope::selectableTenantOptions()`, scoped to tenants leasing in the
  user's visible properties (plus unaffiliated tenants) — a restricted user is
  never offered another mall's tenants.
- **Delete is super_admin-only** (project-wide via `RoleGatedActions`); bulk delete off.

## 4. Lifecycle / state machine

`status` ∈ {`open`, `resolved`}, default `open`. Plus `notified_at` (null →
stamped once the notice is sent) and soft-delete for retirement. There is no
richer state machine by design.

## 5. Services & commands

**`App\Services\SendViolationNoticeAction`** — the single-action service behind
FR-REQ-17. Given a `Violation`, it sends the tenant a `ViolationNoticeNotification`
through `Tenant::notifyPortal()` (the same path every operator→tenant signal
uses) and stamps `notified_at`. **Failure-contained:** a missing recipient (e.g.
a soft-deleted tenant) or a throwing send is logged via `OpsLog` and reported as
an un-sent notice — never a 500 — and `notified_at` is left null so the operator
can retry. `notified_at` is stamped only on a successful send.

**`App\Services\BillViolationFineService`** — `bill(Violation): Invoice` recharges a
recorded fine to the tenant. Mirrors the utility-recharge / CAM immediate-billing pattern:
- Issues a **dedicated invoice NOW**, dated (`period_start/end`) to the violation month,
  carrying a single `violation_fine` line → `misc_income` (42101001) via the existing
  `InvoiceJournalizer`. `violation_fine` is excluded from `MonthlyBillingService`'s
  already-billed probe so a fine dated to the current month can't suppress that lease's
  base rent (the revenue-leak class fixed for % rent / utility).
- **VAT-EXEMPT.** A fine is a penalty, not consideration for a supply, so it is out of VAT
  scope (0%) — unlike a service recharge (14%). *Accountant-confirmable; misc_income is a
  reclassifiable default (a dedicated penalty-income account can replace it later).*
- **Lease resolution.** AR needs a lease, so it bills the tenant's **active lease in the
  violation's property** — keeping the AR scoped to the mall the violation happened in.
  No active lease there ⇒ a clear refusal (a runtime toast, since it's dynamic).
- **Idempotent + lock-safe.** Re-reads the violation under `lockForUpdate`; an already-billed
  violation returns its invoice untouched. **Only a cancelled invoice** (whose GL entry is
  voided) frees the fine to re-bill — a credited invoice keeps its posting, so re-billing
  would double-count `misc_income`.

Dispatched from the **"Bill fine"** record action (triple-gated: `visible()` + `authorize()` +
`abort_unless`, all on `ViolationResource::canBillFine`, which requires `invoices.create`; a
`DomainException` surfaces as a toast, never a 500). Once billed, `ViolationForm` locks the
`fine_amount` / `tenant_id` / `asset_id` fields (they define the issued invoice) — cancel the
invoice to change them.

No scheduled command — nothing auto-fires here; billing is always operator-initiated.

## 6. Filament fields & validation

`app/Filament/Admin/Resources/Violations/` — Resource + `Schemas/ViolationForm`
+ `Tables/ViolationTable` + `Pages/{List,Create,Edit}Violation`. Operations
navigation group.

- **Property** (`asset_id`) — `TenantScope::selectableAssetOptions()`, defaulted +
  disabled to the current property, enabled only in All-Properties mode.
- **Tenant** (`tenant_id`) — required, `searchable` + `preload`,
  `TenantScope::selectableTenantOptions()` (scoped to the user's visible malls).
- **Category** (`category`) — required Select over `Violation::CATEGORIES` (signage / operating hours /
  cleanliness / safety / unauthorised works / noise / other). Strings, not a DB enum. A field officer
  picks the kind instead of retyping it, and the operator can then **filter and report by it**.
- **Evidence photos** (`photos`) — `SpatieMediaLibraryFileUpload`, `multiple`/`image`/`reorderable`,
  max 8, on the **private** `photos` collection (`useDisk('local')`, gated by
  `MediaPrivacyConformanceTest`). The thing that makes a violation defensible.
- **Description** — required textarea.
- **Fine** (`fine_amount`) — numeric, `minValue(0)`, `EGP` prefix, **optional**.
- **Date** (`violation_date`) — required, default today, `maxDate(today)` (not future).
- **Status** — Select (`open` / `resolved`), default `open`, no placeholder.
- **Notes** — textarea.

**Table (FR-REQ-16):** reference (mono/bold), tenant, **category** (badge), description
(with a **camera icon** when the breach carries photos), fine (money), date, status
(badge), notified-at (with a "Not sent" placeholder), property badge; filters for status,
**category**, and trashed. Record actions: **Send notice** + Edit (gated on `canEdit`).

**Create page** stamps `created_by_user_id` and guards `assertAssetInScope`.
**Edit page** guards `assertAssetInScope` and ships Delete/ForceDelete/Restore.

## 7. Notifications / integrations — the tenant notice (FR-REQ-17)

The **"Send notice"** record action (`ViolationTable`) is the whole of FR-REQ-17.

- **Explicit, gated in both layers.** `visible()` (the UI) and
  `authorize()` + an `abort_unless()` inside `action()` (the real dispatch gate)
  both check the SAME predicate — `ViolationResource::canNotify()` — so they can't
  drift. `visible()` alone is not a dispatch gate (a crafted Livewire `mountAction`
  bypasses it), which is why the server-side re-check is present and tested via
  `mountAction` (not `callAction`, which would give a false pass).
- **Delivery.** `ViolationNoticeNotification` goes out over **`database` + `push`**
  (bell + mobile) — **no email** — matching the operator→tenant broadcast channel
  choice (`AnnouncementNotification` / `AreaRequestRaisedNotification`). It reaches
  the Tenant's mobile inbox + registered devices and each portal login's web bell
  through `Tenant::notifyPortal()`.
- **Failure-contained** (see §5): a bad recipient never 500s the click.

No auto-billing, no GL, no ETA — this module touches none of the money paths.

## 8. Extension points (how to change safely)

- **Billing the fine** (a real follow-up): do NOT auto-post from this register.
  Add a separate action/service that raises a `Charge` on the tenant's lease (the
  normal AR path) so `Invoice::recomputeTotals()` and the GL registry stay the
  single sources of truth. Keep `fine_amount` as the recorded assessment.
- **Richer lifecycle** (e.g. `disputed`, `waived`): extend `Violation::STATUSES`
  and add the `admin.statuses.violation.*` keys in EN + AR. Keep the default `open`.
- **More attributes** (category, evidence photos, location): add nullable columns
  + form fields; media collections must declare `useDisk('local')` (private) per
  the media-privacy invariant. The isolation plumbing is unaffected.
- **Do not** relax the `assertAssetInScope` write guards, the `canNotify` dispatch
  gate, or the property-scoped tenant select.

## 9. Gotchas

- **`fine_amount` is recorded, not billed.** Nothing here posts to AR/GL — the
  regression test asserts no invoice/charge/item is created. Don't "helpfully"
  wire it into billing without going through the normal `Charge` path.
- **`notified_at` is not auto-set.** Only the Send-notice action stamps it. A
  create never notifies.
- **`asset_id` is client-supplied on edit.** Filament does not re-stamp it on
  update; the Edit page's `assertAssetInScope()` is the real guard.
- **The Send-notice gate lives in `action()` too.** `visible()` is display-only;
  the `abort_unless()` + `authorize()` are what actually block a crafted dispatch.
- **The tenant select is scoped to visible malls,** not to the specific selected
  `asset_id` — `selectableTenantOptions()` clamps to `visibleAssetIds()`, which is
  what stops a restricted user seeing another mall's tenants.
- **Larastan mistypes the `tenant` BelongsTo to `Model`** — the service types the
  local `$tenant` var after the null guard (`/** @var Tenant $tenant */`), and the
  trait's `Model::$asset_id/$unit` artifacts are baselined for `ViolationResource`
  exactly as for `AreaResource` / `EquipmentResource`.

## 10. Tests

`tests/Feature/Scenarios/ViolationScenarioTest.php`:

- **FR-REQ-15** — records a violation with a fine; the fine is optional; `status`
  defaults to `open`; and **recording a fined violation creates NO invoice /
  charge / invoice-item** (the fine is recorded, not billed).
- **FR-REQ-16** — RBAC (operations + coordinator create; viewer view-only;
  leasing none; delete = super_admin); property read-scoping via
  `scopedResourceQuery`; the write guard rejects an out-of-scope `asset_id`; the
  tenant select does not offer cross-property tenants; the table renders with rows.
- **FR-REQ-17** — the Send-notice action notifies the tenant via the real path
  (`Notification::fake` + `assertSentTo`) and stamps `notified_at`; it is
  bell+push, no email; it is a safe no-op / contained on a missing recipient; and
  it is blocked for a role lacking `violations.notify`, dispatched via
  `mountAction` (not `callAction`, the project's false-pass gotcha).

`tests/Feature/Regression/ViolationFineBillingTest.php` — the fine-billing path: a fine bills as a
**VAT-exempt** `violation_fine` invoice + stamps the violation; idempotent (no double-charge); refuses
with no fine / no active lease; posts to **misc_income** and ties AR out **through the real
`accounting:sync-ledger` sweep**; a fine invoice does **NOT** suppress that month's base rent in the
monthly run; a **credited** invoice can't be re-billed but a **cancelled** one can; `canBillFine` is
gated on `invoices.create` and hidden once billed; and a billed violation refuses **force-delete**
(audit-link protection) while still allowing a reversible soft-delete.

Plus the standing gates: `PropertyIsolationConformanceTest` (classification +
scope + guard), `AdminSmokeManifestConformanceTest` (regenerate with
`php artisan atriom:dump-admin-manifest`), and `TranslationCoverageTest` (EN/AR
parity for the `admin.violations.*` keys + the `violation` status + activity subject).
