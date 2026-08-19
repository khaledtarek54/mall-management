# Property Isolation — how it works & how to extend it safely

> **The living reference** — and now the only one. The separate design/sign-off plan was folded
> away on 2026-08-19: its decisions are settled and restated below, and a plan kept beside a living
> reference is a second answer to the same question. This file is what you read before
> touching a property-owned module.

## The invariant

> A property-restricted user, with a property selected, can only **read or write** rows belonging to
> that property (or their assigned set in All-Properties mode). Portfolio roles (super_admin / owner)
> may consolidate, but never accidentally.

Isolation is **soft, row-level**: one shared MySQL database; every property-owned row carries `asset_id`
(directly or via a relation chain); `Asset` is the Filament panel tenant. There is **no** separate
database per property — the operator's shared chart of accounts, cross-mall tenants, and portfolio
consolidation all depend on one shared store.

> **Property-first UX — "All Properties" is no longer a selectable operational tenant** (see
> [plans/03-remove-all-properties-mode.md](PROPERTY-ISOLATION.md)). The operator always
> works **inside one real mall**: the switcher offers only real properties (`User::getTenants()`), and the
> ALL pseudo-asset is refused by `canAccessTenant()` — a crafted `/admin/ALL` URL 404s. Consequences you
> can rely on **on operational screens**: `currentAssetId()` is always a real mall (never null from
> All-mode), and `visibleAssetIds()` returns `[currentId]`. The ALL pseudo-asset row,
> `Asset::ALL_PROPERTIES_CODE`, `isAllProperties()`, `TenantScope`'s pseudo-asset handling, and **every
> guard described below stay in place** — as internal plumbing for a future read-only *consolidation*
> surface (Phase B) and as defense-in-depth. The guards are still load-bearing: the conformance gate and
> the clobber tests exercise them by **force-setting** the pseudo-asset tenant (`Filament::setTenant`),
> which bypasses `canAccessTenant()` on purpose. Do not "simplify" them away on the theory that All-mode
> is gone — it is gone from the *switcher*, not from the plumbing.

## Shared vs. isolated — the register

The authoritative, testable source of truth is **[`App\Support\PropertyIsolation`](../app/Support/PropertyIsolation.php)**.

**SHARED across all properties** (operator-wide; no per-property row scoping):
User/roles · **LedgerAccount** (one chart; property is a *dimension* on journal lines) · FiscalYear ·
AccountingPeriod · AccountMapping (global default + optional per-property override) · SystemSetting ·
**InventoryItem** (catalog) · **Vendor** (catalog) · **Tenant** / TenantUser / DeviceToken · Note.

> **Department is NOT shared** — it is a *hybrid* per-property model (nullable `asset_id`: null = operator-wide,
> a set value scopes it to one property). It lives in `OWNED`; its resource scopes reads to
> "global OR your visible set" and guards its edit. (Misclassifying it SHARED was a real read leak — a
> `SHARED`-model-has-no-`asset_id` test now guards against that class.)

**The shared-catalog-with-per-property-use pattern** — how something is shared *without* leaking:

| Shared master (global) | → | Per-property usage (`asset_id`) |
|---|---|---|
| Vendor | → | VendorContract, VendorBill |
| InventoryItem | → | Warehouse, StockMovement |
| LedgerAccount | → | JournalEntry / JournalLine (dimension) |
| Tenant | → | Lease, Invoice, Payment |
| AccountMapping | → | per-property override row |

**ISOLATED per-property**: every model carrying `#[PropertyOwned]` — leases, invoices, payments, credit
notes, deposits, CAM, marketing, meters, maintenance, HR/payroll/custody, fixed assets, warehouses,
journal entries, expenses, and their children.

## The two halves of the mechanism

### 1. Read scoping (which rows a query returns)

- Direct-`asset_id` models, **read-only or with a non-editable `asset_id`** → **`BypassesScopingOnAll`**
  (Filament auto-tenancy via `$tenantOwnershipRelationshipName = 'asset'`, plus the All-Properties
  escape hatch).
- Direct-`asset_id` models whose **form exposes an editable `asset_id`** (the operator picks the mall;
  the Select is enabled in All-Properties mode) → **`ScopesToProperty`** (2026-08-15). It turns
  Filament's auto-tenancy hook off *and* supplies the whole read scope from the model's own
  `#[PropertyOwned]`, so the resource writes no query at all. **Do NOT use `BypassesScopingOnAll`
  here** — it keeps `isScopedToTenant() === true`, so
  Filament's `creating` hook force-associates `asset_id` with the current tenant, and in All-Properties
  mode (tenant = ALL pseudo-asset) that silently clobbers the chosen mall (the "Announcements tenancy
  trap"; `Test D` of the conformance gate + `AllPropertiesCreatePinsAssetTest` guard against it). Keep
  the `assertAssetInScope` write guard on create **and** edit.
- Indirect models → **`ScopesToProperty`** as well; the relation chain is declared once on the model
  as `#[PropertyOwned(via: 'lease.unit')]`, not restated in the resource. (`ScopesViaProperty`, which
  required each resource to declare `tenantScopeRelation()`, is the older form of the same idea.)
- Models with a **nullable** `asset_id` where a null row is portfolio-level overhead every property
  must still see (`Expense`, `VendorBill`, `JournalEntry`, `Payroll`, `DepositTransaction`) →
  `#[PropertyOwned(portfolioRowsWhenNull: true)]`. Scoping one of these strictly hides those rows
  from every screen, and nothing fails loudly — which is why it is declared on the model.
- **Needs an eager-load or aggregate as well?** Write `getEloquentQuery()` and compose:
  `return static::scopeToProperty(parent::getEloquentQuery()->withCount([...]));` — a method on the
  class wins over the trait's, and the scoping rule is reused rather than copied.
- Other special cases → **`BypassesFilamentTenantAutoScope`** + a custom `getEloquentQuery()`. Five
  resources genuinely need this (`Asset`, `CreditNote`, `Department`, `InventoryItem`,
  `OwnerRequest`); say at the call site why the standard rule does not fit.
- Widgets / services / reports → **`App\Support\TenantScope`** (`applyTo`, `visibleAssetIds`,
  `reportAssetIds`, `selectable*`). **Always** derive the constraint from `visibleAssetIds()` (null only
  for portfolio users), never `currentAssetId()` alone — the latter is null in All-mode and leaks.

### 2. Write guarding (which property a create/edit may target)

**Filament stamps `asset_id` = current tenant on CREATE only** (for `isScopedToTenant() === true`
resources) — never on update. So:

| Resource kind | Create | Edit |
|---|---|---|
| `isScopedToTenant() === true` (auto-stamped) | Safe — Filament overwrites any tampered `asset_id` | **Needs a guard** if `asset_id` is editable (not re-stamped on update) |
| `isScopedToTenant() === false` (opts out) | **Needs a guard** — `asset_id` is fully client-supplied | **Needs a guard** |

The guard is **`App\Filament\Admin\Resources\Concerns\GuardsAssetInScope`**. It `abort(403)`s when a
restricted user submits a property outside `visibleAssetIds()` and is a no-op for portfolio users. Wire it
from the page's `mutateFormDataBeforeCreate` / `mutateFormDataBeforeSave`:

- **Direct `asset_id`** (Expense, VendorBill, Payroll, JournalEntry, OwnerRequest, Unit, CamExpensePool,
  UtilityMeter, Employee, …): `assertAssetInScope($data['asset_id'])`.
- **Chain-derived** (Invoice/TenantSalesDeclaration/CreditNote/DepositTransaction ← lease;
  Lease/MaintenanceRequest ← unit; Payment ← allocated invoices): the property comes from a client FK, so
  the picker `->when(currentAssetId(), …)` is a **no-op in All-mode and leaks** — scope every such picker to
  `visibleAssetIds()` **and** guard with the FK-resolving helpers `assertLeaseAssetInScope` /
  `assertUnitAssetInScope` / `assertUnitsAssetInScope` / `assertInvoiceAssetInScope`.
- **Relation managers** are outside the resource-page flow — guard a client-supplied `asset_id`/FK there
  with a field `->rules([...])` closure (see `Vendors/RelationManagers/ContractsRelationManager`).

**Filament stamps `asset_id` only on CREATE** (for `isScopedToTenant()===true` resources), never on
update — so an editable `asset_id`/FK on the **edit** page always needs a guard.

## The property picker shows the answer (2026-08-19)

Isolation was complete on both halves above long before this section existed — and the **screens did
not say so**. Every property picker on a document form offered "Consolidated (all)" and every other
mall beside the one selected, and each of those options was already refused:

| What the operator picked | What happened |
|---|---|
| blank / *Consolidated (all)* | `assertAssetInScope()` sees `(int) null === 0`, which is not in `visibleAssetIds()` (`[currentId]` whenever a real mall is selected) → **abort(403)**, for every role including `super_admin` |
| another mall | `EntitySelect` resolves a submitted value's LABEL through the property-scoped `pickable()` query; Filament refuses what it cannot label → *"The selected property is invalid."* |
| the selected mall | saves |

So the control offered one workable value and a set of dead ends: fill in a whole journal entry,
choose "Consolidated", press Create, meet a bare 403. The **reports** were the worse half, because
they failed quietly — `TenantScope::reportAssetIds()` clamps its argument to the visible set, so on
a trial balance both "Consolidated (all)" and the mall next door resolved to the mall you were
already in. Right figures under a wrong caption, and nobody re-checks a total they believe they
asked for.

**One component now answers the question instead of asking it:**
[`App\Support\Filament\PropertyField`](../app/Support/Filament/PropertyField.php).

- `PropertyField::make()` — the pinned picker for anything that RECORDS a mall's business:
  defaulted to `currentAssetId()`, disabled, `dehydrated()` (a disabled input is not submitted, so
  without it the pinned value never reaches the model). Pass an extra lock as
  `$alsoDisabledWhen` — **chaining `->disabled()` after it silently unpins the field**, because
  Filament's `disabled()` overwrites rather than composes.
- `PropertyField::free()` — the same scoping, still editable and nullable, for the three
  PORTFOLIO-CONFIGURATION screens registered in `PropertyField::PORTFOLIO_LEVEL` with a reason:
  the posting map (the blank row is the global default every property inherits), Departments (the
  one hybrid model — blank is an operator-wide department), and Owner Requests (a general question
  is about no single mall).
- `PropertyField::reportScope()` — the same pin for a page's `$assetId`. `ScopesLedgerReport` also
  gives the property switcher **the last word** after a drill-down URL and a remembered preference,
  so the disabled picker can never name one mall while the rows below it come from another.

**The pin is a UI truth, not a guard.** The field is dehydrated, so its value still arrives in the
Livewire payload and a crafted request can state anything — every `assertAssetInScope()` call stays
exactly where it was. `PropertyFieldPinnedConformanceTest` **renders** each create form and reads
the built component's evaluated state (a call site can chain `->disabled(false)` and look correct in
source), fails on a stale `PORTFOLIO_LEVEL` entry, and pairs the whole thing with the two refusals
it stands in for plus a control that must succeed.

**A rendered create-form sweep has a blind spot shaped exactly like the bug it was written for.** A
relation manager, a table filter, a header-action form and a page filter strip all declare property
controls in directories it never opens, and each would go on looking correct forever. So a second,
coarser check sweeps EVERY `make('asset_id')` / `make('assetId')` under `app/Filament` and fails on
one that is neither built by `PropertyField` nor registered. It cannot tell a pinned control from an
unpinned one — that is the rendered sweep's job — it can only tell whether somebody **decided**.
The decisions live in `PropertyField::UNPINNED`, each with why it is not a pinnable picker:
`OccupancyMap` (its whole strip is `visible(currentAssetId() === null)`, so the control never
renders while a mall is selected), the Units table **filter** (nothing is written, and it is hidden
outright when pinned — the table is already scoped to that mall, so it offered a list of one),
`MarketingBudgetForm` (a read-only display; the resource has no create page), the vendor-contract
relation manager (a null there is a genuine PORTFOLIO-WIDE contract, and it carries its own
`->rules()` guard because a relation manager is outside the resource-page flow), and the tenant
**portal** post form (a different panel — tenant-scoped, not asset-scoped, so there is no selected
property to pin to).

**Edit forms inherit the pin for free**, and that is asserted rather than assumed: both pages read
`XResource::form()`, so an edit form is the same built schema the sweep already inspected —
`default()` simply does not fire and the record's own property loads disabled. A gate check fails
any `Edit*` page that declares its own `form()` / `getFormSchema()` / `content()` and would step
outside that inheritance silently.

### The two paths a property-less row can still take

Pinning the pickers closes the operator's path and leaves the two that run before anyone looks at a
screen: a CSV **import**, and a **migration** off the system the operator is leaving. A row from
either is not merely mis-filed — `portfolioRowsWhenNull: true` puts it on **every** mall's list, it
reaches no mall's owner statement (`GenerateOwnerStatementRunService` scopes
`where('asset_id', $asset->id)`), and nothing about it looks wrong on screen.

`php artisan atriom:audit-property-dimension` sweeps every model declaring
`#[PropertyOwned(portfolioRowsWhenNull: true)]` and **exits non-zero** when a money document names
no property — the same pre-deploy contract as `atriom:audit-charge-schedules`, rather than a report
somebody remembers to read. Which nulls are *expected* is **derived** from
`PropertyField::PORTFOLIO_LEVEL` (a global department is the normal answer for `Department`), so the
command and the screens cannot disagree; a second hand-written list here would cry wolf on every run
until people stopped reading it. It is read-only and never repairs a row — the correction for a
posted entry is a reversing entry, which is not a decision a sweep should take on money.

## The self-enforcing gate

**[`tests/Feature/Scenarios/PropertyIsolationConformanceTest.php`](../tests/Feature/Scenarios/PropertyIsolationConformanceTest.php)**
fails CI when a new model/resource ships unclassified, unscoped, or unguarded:

- **A** — every Eloquent model is classified in `PropertyIsolation`; direct-FK owned models have an
  `asset_id` column; indirect ones expose their chain's first hop.
- **B** — every property-owned admin resource scopes its table reads (a scoping trait, a
  `getEloquentQuery()` override, or an explicit `$tenantOwnershipRelationshipName`).
- **C** — every must-guard resource wires `assertAssetInScope` on its create/edit pages; **and** any
  not-auto-stamped (`isScopedToTenant=false`) owned resource exposing an editable `asset_id` is
  auto-flagged if it's missing from the must-guard set.
- **D** — no owned resource whose **create form** exposes an editable, dehydrated `asset_id` still uses
  Filament auto-tenancy (`isScopedToTenant() === true`). Such a resource clobbers the operator's picked
  mall to the ALL pseudo-asset on create in All-Properties mode; it must opt out via
  `BypassesFilamentTenantAutoScope`. (The gate renders each create form and inspects the `asset_id`
  component's disabled/dehydrated state, so a new such resource fails CI unless it opts out.)

## How to add a new property-owned module safely

1. Give the model an `asset_id` column (or a clean relation chain to one).
2. Classify the model **on the class itself** (2026-08-15) — `#[PropertyOwned]` for a direct
   `asset_id` column, or `#[PropertyOwned(via: 'lease.unit')]` for a relation chain. A model that is
   NOT property-owned still has to say so: `#[PortfolioShared]` (an operator-wide catalogue, config
   or person) or `#[PropertyItself]` (only `Asset`). The attributes live in
   `App\Support\Attributes`; `PropertyIsolation` derives its registers from them, so there is no
   array to append to. Put the reason it is shared/owned in a comment above the attribute — that is
   where the next person will look for it.
3. Scope the resource table: `BypassesScopingOnAll` (direct) or `ScopesViaProperty` (indirect).
4. Scope every cross-property form select via `TenantScope::selectable*` / `visibleAssetIds()`.
   **Build the property field itself with `PropertyField::make()`** — never a bare
   `EntitySelect::make('asset_id')`, which ships a picker whose every other option is refused.
5. If the form exposes/derives an editable `asset_id`: `use GuardsAssetInScope`, call
   `assertAssetInScope(...)` from create **and** edit pages, and add the resource to
   `propertyIsolationMustGuardResources()` in the conformance test.
6. Run `vendor/bin/pest --parallel` — the conformance gate tells you what you missed.

## Per-property CONFIGURATION (CFG-03, 2026-08-12)

Isolation answers "which property's *data* may I see". A separate question is "which property's
*policy* applies", and until CFG-03 the answer was always "the portfolio's": one late-fee rate, one
grace period, one set of payment terms across every mall Eltizam runs. The lease tier above those
numbers already assumed they vary — a negotiated late fee has always beaten the default — so a
single portfolio answer underneath was the odd one out. Yardi configures these per property.

**Three tiers, resolved in one place** (`App\Support\PropertySettings`):

1. the **LEASE**'s own negotiated term, where it has one;
2. the **PROPERTY** — a `property_settings` row;
3. the **PORTFOLIO** — the settings screen, which is always answerable.

Two rules make it safe:

- **The asset is passed EXPLICITLY.** `PropertySettings::get($key, $assetId)` never reads the panel's
  selected property. The callers are billing services that also run from the scheduler and the queue,
  where there is no selected property — a contextual fallback would give one answer in a request and
  another in the nightly run, on money.
- **Absence means inherit, never zero.** The resolver checks for the KEY, not for a falsy value, so a
  property that deliberately waives its late fee keeps that decision when the portfolio default later
  changes. `?:` here would silently re-charge a mall the operator had exempted.

**`OVERRIDABLE` is an allow-list and every entry is wired.** An override nothing reads is worse than
none: the operator changes it, sees "Saved ✓", and nothing happens. `PropertySettingsConformanceTest`
holds the structure; `PropertySettingsReachTheMoneyTest` drives the real services and asserts on the
money, each case paired with a control at the portfolio rate.

**What is deliberately NOT overridable**, because the omissions carry the reasoning:

- **SLA hours** — `sla_policies` is already a per-property override with its own resource and its own
  response-vs-resolution split. A second way to say the same thing would disagree with the first.
- **Tax rates, the seller's registration number, payroll rates, module switches** — not property
  questions at all. An override on any of them would be a way to make one mall file a different return.

Edited at `/admin/property-overrides`, scoped to the selected property. A blank field inherits and
says so twice (placeholder *and* helper text): a blank that reads as zero is the whole risk of an
override screen.

---

## Boundaries (out of scope by design)

- **Tenant portal** (`TenantUser`) and **Mobile API** (`Tenant`, Sanctum) are **tenant-scoped**, not
  asset-scoped: a retailer sees all their own data across every mall they lease in. Cross-tenant API
  returns **404**. This is intentional and unchanged.
- **Scheduled scans** run in console context (no Filament tenant → portfolio-wide) by design.

---

## The second scoping primitive: assignment (FR-USR-04)

`TenantScope` answers **"which properties may you see"**. `App\Support\AssignmentScope` answers
**"which rows within them are yours"**. They are independent and both apply — being *assigned* a job
never grants access to the mall it sits in.

The FRD: *"Every user shall see only the requests/work orders assigned to them, **filtered by role
and assignment**."* "Every user" is not literal — its own role table gives the Admin "full access for
their assigned mall" and the Coordinator "oversight", while the **In-house Technician** "sees only
work assigned to them". **The role decides whether the filter applies.**

- Expressed as a permission (`{module}.view_all`), not a role list — holders oversee the module,
  non-holders see their own work. Every pre-existing role was granted it, so nothing narrowed.
- **Fails closed:** no user, or no permission, means restricted.
- **A query constraint, never a filter.** A filter is a checkbox the user can clear; that is not
  what "sees only work assigned to them" means. It also covers the record page for free, since
  Filament resolves records through the same query.

> ⚠️ **`ScopesViaProperty` IS `getEloquentQuery()`.** A resource using that trait (TenantRequest and
> anything else scoped through a relation rather than its own `asset_id`) gets its property
> isolation *entirely* from that method. Declaring `getEloquentQuery()` in the class **shadows the
> trait** — a class method always beats a trait's — which silently deletes property isolation:
> every restricted user reads every mall.
>
> This is not hypothetical; it happened while building FR-USR-04, and `ResourceScopingTest` +
> `OpsIsolationScenarioTest` caught it. **Alias the trait and wrap it:**
>
> ```php
> use ScopesViaProperty {
>     ScopesViaProperty::getEloquentQuery as scopedViaPropertyQuery;
> }
>
> public static function getEloquentQuery(): Builder
> {
>     return AssignmentScope::apply(static::scopedViaPropertyQuery(), 'maintenance', 'assigned_to');
> }
> ```

**Note also:** FR-USR-04 puts a *permission check in the query layer*, which previously had none.
A fixture that builds `makeUser('super_admin')` without seeding roles now sees nothing — correctly,
since fail-closed — so any test asserting scoping must seed `RolesPermissionsSeeder`.

## Gotcha — "was this user ever scoped?" must not read a soft-deleting relation

`AssignedAssets::idsFor()` returns `null` (= unrestricted) for a user who was
NEVER assigned or an owner — deliberate back-compat for single-mall deployments
— but the fail-closed sentinel `[0]` (= sees nothing) for a user whose scope has
**lapsed**.

That probe must ask about the **assignment**, not the asset. It originally used
`assignedAssets()` / `ownedAssets()`, which are relations to a **soft-deleting**
`Asset`. Archiving a property therefore made them return nothing, so a staff
member assigned to exactly that property fell into the "never scoped" branch and
became **unrestricted** — gaining read access to every other property in the
portfolio. Archiving a mall is an ordinary super_admin action, so this was
reachable, not theoretical.

It now reads the `asset_user` / `asset_owner` pivot rows directly, which are
independent of the asset's soft-delete state.

**If you touch this probe:** any relation you use must survive the related model
being soft-deleted, or the failure mode is silent privilege escalation rather
than an error. Guarded by
`tests/Feature/Regression/AssignedAssetsLapsedScopeTest.php`.

<!-- GENERATED:isolation-classification — do not edit by hand; run `php artisan atriom:dump-registries` -->

## Model classification

Generated from `App\Support\PropertyIsolation`. `PropertyIsolationConformanceTest` fails the
build if a model ships unclassified, so this list is complete by construction.

**Property-owned (82)** — scoped to the selected property:

`AnnouncementRecipient` · `Announcement` · `Area` · `AssetOwner` · `BankAccount` · `BankMatch` · `BankStatementLine` · `BankStatement` · `Bin` · `BudgetLine` · `CamAllocation` · `CamExpensePool` · `Charge` · `CreditNoteApplication` · `CreditNoteItem` · `CreditNote` · `CustodyTransaction` · `Custody` · `Department` · `DepositApplication` · `DepositTransaction` · `DepreciationEntry` · `Disbursement` · `EmployeeAdvanceRepayment` · `EmployeeAdvance` · `Employee` · `Equipment` · `Expense` · `FacilityWorkOrderItem` · `FacilityWorkOrderPart` · `FacilityWorkOrder` · `FixedAssetDisposal` · `FixedAsset` · `Floor` · `InvoiceItem` · `InvoiceWriteOff` · `Invoice` · `JournalEntry` · `JournalLine` · `LeaseCamTerm` · `LeaseClause` · `LeaseEvent` · `LeaseOption` · `LeasePercentageRentTier` · `Lease` · `LowStockAlert` · `MarketingBudget` · `MarketingPost` · `MarketingSpend` · `MeterReading` · `OwnerRequestReply` · `OwnerRequest` · `OwnerStatementRun` · `OwnerStatement` · `Payment` · `PayrollLine` · `Payroll` · `PostDatedCheque` · `PropertySetting` · `PurchaseRequestLine` · `PurchaseRequest` · `RentableItem` · `ServicePlan` · `SlaPenalty` · `SlaPolicy` · `StockMovement` · `StraightLineRentAdjustment` · `TenantCreditApplication` · `TenantRequestComment` · `TenantRequest` · `TenantSalesDeclaration` · `UnitArea` · `UnitOwnership` · `Unit` · `UtilityMeter` · `VendorBillPayment` · `VendorBill` · `VendorContractAmendment` · `VendorContract` · `Violation` · `Warehouse` · `WorkPermit`

**Shared (25)** — portfolio-wide by design:

`AccountMapping` · `AccountingPeriod` · `ApprovalRule` · `ChargeCode` · `DeviceToken` · `FiscalYear` · `InventoryItem` · `LedgerAccount` · `Note` · `RentIndex` · `ReportPreference` · `SavedReport` · `SystemSetting` · `TableView` · `TaxCode` · `TaxRate` · `TenantDocument` · `TenantUser` · `Tenant` · `User` · `UtilityTariffRate` · `UtilityTariff` · `VendorContact` · `VendorDocument` · `Vendor`

**Self (1)** — the property record itself:

`Asset`
<!-- /GENERATED:isolation-classification -->
