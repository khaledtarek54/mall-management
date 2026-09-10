# Property Isolation — how it works & how to extend it safely

> **The living reference** — and now the only one. The separate design/sign-off plan was folded
> away on 2026-08-19: its decisions are settled and restated below, and a plan kept beside a living
> reference is a second answer to the same question. This file is what you read before
> touching a property-owned module.


> **⚠️ A picker dropped every portfolio-wide row (fixed 2026-08-25).** `PropertyIsolation::
> portfolioRowsWhenNull()` records the eight models where a null `asset_id` means *every mall*
> rather than *unassigned* — and `OptionDisplay::scope()` was the one place not asking it. Since
> **`whereIn` never matches NULL**, every one of those rows was invisible to every dropdown.
>
> Measured: `departments` held 5 rows, all portfolio-wide, and the picker offered **zero** on four
> screens (Employee · facility work order · service plan · tenant request) — a field that was
> required on some of them and could not be filled. Same trap as EG-27's financial statements, in a
> different layer.
>
> Found by sweeping all 94 pickers, not from a report: **a picker that offers nothing and a table
> that holds nothing look identical from the panel**, which is why the gate that now guards it asks
> each empty picker's own table for a row count — **unscoped**, or the bug answers on its own
> behalf — and fails only when rows genuinely exist.


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
  Lease/TenantRequest ← unit; Payment ← allocated invoices): the property comes from a client FK, so
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
- `PropertyField::scope()` — a two-option SCOPE control (*"All properties"* / *"&lt;this mall&gt; only"*)
  for the **five** PORTFOLIO-CONFIGURATION screens registered in `PropertyField::PORTFOLIO_LEVEL`
  with a reason: the posting map (the blank row is the global default every property inherits),
  Departments (the one hybrid model — blank is an operator-wide department), Document wording (the
  blank row is the house wording every mall inherits), Holidays (a national holiday is not a fact
  about one mall), and Owner Requests (a general question is about no single mall).

  These screens ask a different question from every other property field: not *"which mall does
  this belong to?"* but *"portfolio, or just this mall?"* — and a null `asset_id` is one of the two
  valid answers, queried as the fallback tier by all four resolvers. They used to render a free
  `EntitySelect`. **That was never an isolation leak** — the picker resolves a submitted value's
  label through the property-scoped `pickable()` query, so on a two-mall install it offered exactly
  the mall in the switcher and refused the other at validation. The defect was that a SCOPE question
  wore a PROPERTY PICKER, so an enabled dropdown read as "choose a mall" and was reported as a leak.
  Stating the two answers means **no screen in the panel offers a property other than the selected
  one**, which is the rule the pinned fields enforce.

  Three of the five scope their list to `null ∪ visible`, so the two options are exhaustive. **Two
  do not** — the posting map has no `getEloquentQuery()` at all and owner requests scope to the
  operator's ASSIGNED set — so an edit page there can open a row filed against a third mall. A
  two-option toggle would render that as "All properties" and silently re-home it on save, so the
  row's own property is added as a third option and the control is **disabled**: shown, not adopted.
  `PropertyScopeControlNeverOffersAnotherMallTest` derives the five from the register and fails on a
  screen that starts offering a third mall, drops the portfolio row, stops disabling a foreign row,
  or loses either refusal layer (Filament's own `In` rule over a Radio's options is **pinned as a
  contract**, because it is upstream behaviour that could change in a release and silently remove a
  gate — the same reasoning as `FilamentActionDispatchContractTest`).
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

## A LINK NAMES THE PROPERTY OF THE RECORD, NOT THE ONE IN THE SWITCHER (2026-09-10)

Isolation had been read as one question — *which rows come back* — and it has a second half nobody
had asked: **which property a link out of a screen names.**

Every `/admin` route carries a `{tenant}` segment (slug = `assets.code`) and `Resource::getUrl()`
fills it from `Filament::getTenant()` — the **switcher**. On most screens that is right by
construction: the row on your screen belongs to the selected mall or you could not have opened the
list. Two kinds of screen break that assumption:

- **the owner record is portfolio-wide.** `AssetResource` lists the operator's whole portfolio on
  purpose (`$isScopedToTenant = false`) — managing the malls themselves sits above the per-property
  context, and a newly created mall is never the active one — so its Units and Rentable-items tabs
  are showing mall B while the switcher says mall A.
- **the ROWS span properties.** A tenant's page IS narrowed to the selected mall, but its TABS are
  not all alike, and the difference is worth stating because a review caught the first version of
  this paragraph getting it wrong. The **violations** and **sales-declaration** tabs are scoped by
  *nothing at all* — a tenant's compliance history is listed wherever they trade — so those rows
  really do span malls. The **invoices** and **requests** tabs narrow with
  `TenantScope::visibleAssetIds()`, and that method answers the **SELECTED** property for any real
  tenant (super_admin included, since All-Properties was removed), so no away row can reach those
  screens. All four are written the same way regardless: the answer to *which mall is this row in*
  should not depend on a scoping decision made in another file, and the gate then needs no
  exemption list of the tabs that happen to be narrow this week.

The targets are `ScopesToProperty`, and Filament resolves a route-bound record through the
resource's own scoped query, so a link naming the wrong mall resolves **no record**: a **404** off a
row on screen, not a refusal anybody can act on. Reported from the panel as
`/admin/VP/units/13/edit`. **Yardi's rule is the one this broke**, and it is already written down
here — `docs/benchmarks/yardi/08` UX-12: *"No dead-end numbers. If a figure can be drilled, it
links"*, under a persistent scope selector where the context follows the record you opened.

**Two right answers, because they are two different questions.** Where the TAB owns the property,
the relation manager already knows it and passes it — `tenant: $this->getOwnerRecord()`, free and
exact. Where the ROW owns it, `App\Support\Filament\PropertyLink::to()` reads it from the record
through this document's own register (`PropertyIsolation::linkageFor()` — `Invoice` is direct,
`TenantRequest` via `unit`, `TenantSalesDeclaration` via `lease.unit`).

**Null rather than a fallback to the switcher** — that fallback *is* the defect. It is
`NotificationLink`'s rule, arrived at independently for the identical reason and written there in
full: *"A link that 404s (wrong property) or 403s (no permission) is worse than no link: it reads as
a broken system rather than as a boundary."* `PropertyLink::assetOf()` is that class's own resolver,
**extracted on its second real call site rather than copied** — two readings of *which property does
this row belong to* are two answers waiting to disagree, and one of them is building a URL where the
other is building a query.

**The OWNER side of the gate is a UNION, and getting it wrong is how its own first version skipped
two of the six resources it most needed to read.** A screen's rows are the selected mall's only when
the resource is BOTH property-owned AND narrows itself to the selected property. Asking only *"is
the model `#[PropertyOwned]`"* skips `DepartmentResource` and `OwnerRequestResource` — both carry
`#[PropertyOwned(portfolioRowsWhenNull: true)]` models and still list the operator's whole assigned
set, because they declare `$isScopedToTenant = false` and scope themselves that way deliberately.
Asking only *"does it use a scoping trait"* skips `TenantResource`, which uses one. **And a scoped
OWNER does not make its TABS scoped** — `TenantResource` is caught here only because a `Tenant` is
`#[PortfolioShared]`, so a future property-owned, trait-scoped resource with a tab reaching across
malls would not be swept. That limit is stated in `Tests\Support\PropertyLinks` rather than implied
away, along with the other one: **admin Pages and Widgets are not swept**, and six of them already
pass `tenant:` explicitly, which is direct evidence the same defect class lives there.

**THE RULE WAS ALREADY WRITTEN DOWN AND STILL MISSED, WHICH IS WHY IT IS A GATE.**
`AssetStaffRelationManager` states it in full — *"The TENANT is passed explicitly … a relation
manager already knows the property it belongs to"* — and the two managers registered beside it in
the same `getRelations()` array did not follow it. A sentence is not a gate.
[`ALinkOffAPortfolioWidePageNamesItsOwnPropertyConformanceTest`](../tests/Feature/Scenarios/ALinkOffAPortfolioWidePageNamesItsOwnPropertyConformanceTest.php)
**derives both halves from this register** — an owner whose model is not `#[PropertyOwned]` can be
showing another mall's rows; a target whose model is `#[PropertyOwned]` 404s under the wrong one —
so screen sixty-seven is covered by being what it is. It **tokenises and drops comments first**,
because the fix's own docblock names `getUrl()` and `tenant`, and a grep-based gate reads the
sentence explaining the rule as a call site obeying it: the prose false-positive that has weakened
three gates in this repo already, pinned by its own probe test.

**An unresolvable row gets no button, and so does one in a mall the reader cannot enter.** `to()`
answers null for both and each `Open` hides itself on that answer — a control that goes nowhere is
worse than no control. The access check is the one `NotificationLink::adminUrl()` makes against its
own reader: `IdentifyTenant` answers 404 for a mall you may not enter, so without it the link is a
dead end wearing the look of a working control, and its href names another mall's slug on the page.

The no-property branch is recorded as **unexercised** rather than implied to be covered:
`visibleAssetIds()` answers the SELECTED property even for a super_admin, so the invoices tab
narrows with `whereIn('asset_id', [id])` and `whereIn` never matches null — a property-less invoice
is not on the screen to be clicked — and the other three resolve through NOT NULL columns
(`violations.asset_id`, `tenant_requests.unit_id`, `leases.unit_id`). It is proved at the seam
instead. **The whole resolution sits inside the guard, not just the URL build** — walking a
`via:` chain is the part that throws, and `NotificationLink` wraps it for that reason; guarding only
the `getUrl()` call would turn a missing parent into a 500 on the tab rather than one dropped
button.

## A RECORD-PAGE TAB IS A THIRD SURFACE, AND IT WAS THE UNSWEPT ONE (2026-09-10)

Isolation was being proved on two surfaces and there are three. `PropertyIsolationConformanceTest`
proves every RESOURCE is classified, scoped and write-guarded; `ARestrictedOperatorSeesOneMallTest`
drives every admin LIST and reads the rows back. **Neither can see a RELATION MANAGER** — a tab
builds its query from `$owner->relation()`, and no resource's `getEloquentQuery()` is involved at any
point.

Sixty-seven exist. Coverage was **five**, across two files and two occasions — four from the 2026-07
adversarial sweep, one from SW-191 — so sixty-two had never been asked.

**A tab can leak when both halves hold**, and both are read off this document's own register:

- the CHILD is `#[PropertyOwned]` — it belongs to one mall, so there is something to leak;
- the OWNER is neither `#[PropertyOwned]` nor `#[PropertyItself]` — a portfolio-shared master whose
  children are spread across malls.

**Both exclusions are DERIVED, and the second matters most.** An owner that is itself property-owned
is only reachable inside the operator's own scope, so its children are too. And `Asset` is
`#[PropertyItself]`: its Units, Floors, Areas and Rentable-items tabs list the property's OWN rows,
and narrowing those to the SELECTED mall would empty the property page of every mall except the
active one — the opposite of what that page is for. Excluding those four by name would have been a
list; excluding them by attribute means the next such tab is right by being what it is.

**Nine tabs qualify. Seven scoped, in FIVE different spellings** — `whereHas('unit', …whereIn)`, a
bare `whereIn('asset_id', …)`, an `->inProperties()` scope, a borrowed
`StockMovementResource::scopeToProperty()`, and a visible-ids argument passed into a service — and
**two were scoped by nothing at all**: a tenant's **violations** and their **declared sales**, both
readable from any mall that tenant traded in by an operator holding one of them. Turnover is the
figure percentage rent is billed on.

**`App\Support\PropertyScope` is that rule extracted on its second real call site.** It is the body
`ScopesToProperty` always had — the trait delegates to it and keeps its own resource-facing name and
refusal wording — so the tenth tab cannot invent a sixth spelling. That trait's docblock records
that it replaced sixteen `getEloquentQuery()` bodies doing one job; the same drift then happened one
layer down where no trait was watching, which is the argument for extracting rather than for a ninth
careful call site.

### Three things on that page leak WITHOUT returning a row

All three were found by an adversarial review of the fix, not by the fix's own tests.

**The BADGE is the same disclosure as a row.** `CountsItsRows` counts the plain relationship, so a
narrowed tab hands back as a NUMBER exactly what its scope withholds — measured `rows=1 badge=2`,
and for a tenant with nothing in the operator's mall, an empty table under a badge saying **1**:
*"this retailer has 1 violation you are not allowed to read"*. The trait's own docblock already
forbade the combination in writing. Each narrowed tab names its predicate ONCE and both the table
and `badgeCount()` read it.

**The TAB'S OWN VISIBILITY.** `TenantSalesDeclarations::canViewForRecord()` asked EVERY lease
portfolio-wide, so the tab appeared for a tenant whose only percentage-rent lease is in a mall the
operator does not hold — a heading over a table that can never fill, which that gate's own reasoning
says reads as *"they have not declared"* rather than *"there is nothing to declare"*.

**The ACTIVITY FEED, which was the real leak.** `ShowsItsChildrensActivity` widens a record's audit
trail to its children's — for a tenant that includes its LEASES — and the subquery named only the
owner. Measured: an operator holding one mall read `subject=lease#2`, in a mall they do not hold.
That is the invariant this repo already states for the activity LOG (*"a feed that spans every mall
is readable only by someone entitled to every mall"*) reached through a different door. The old
comment there argued that narrowing a tenant's leases by the selected property is wrong;
`TenantLeasesRelationManager` has narrowed exactly those leases since 2026-07 with a regression
test, so that argument was already contradicted one tab away — what it was really defending is
dropping FILAMENT's tenancy scope, a different thing and still right. The child branch is scoped by
the CHILD's own declaration, so a `TenantUser` or `TenantDocument` — the tenant's in every mall — is
deliberately left alone.

### The gate is BEHAVIOURAL, and that is the point

Five correct spellings mean a source check proves nothing: it passes on all five and on a sixth that
is subtly wrong. `ARecordPagesTabsShowOneMallTest` derives the at-risk set, puts one row in the
operator's mall and one in a mall they do not hold, mounts each tab as an operator assigned to ONE
mall, and reads the rows AND the badge back. **A tab in the at-risk set with no fixture FAILS**
rather than being skipped, and the set size is PINNED — a floor of "more than nothing" is satisfied
by a derivation that has stopped matching almost everything, which was demonstrated by mutating
`atRisk()` to return one tab and watching the file stay green.

**No mall is selected, deliberately.** `TenantScope::visibleAssetIds()` answers `[the selected
tenant]` without consulting `AssignedAssets` the moment a tenant is set, so selecting a mall would
make every guard answer correctly because of the SELECTION and never because of the ASSIGNMENT — the
file would pass with the assignment deleted. `AdversarialSweepRegressionTest` states the same
premise for the same reason.

**A row is identified by CLASS AND KEY, never by key alone.** The tenant LEDGER tab is fed from
`->records([...])` and numbers its array rows by POSITION while carrying the real record under
`model`, so reading `$row['id']` compares an invoice id against 0 — and because that tab interleaves
invoices and payments, a bare key would let a payment vouch for an invoice of the same id.

**The sweep's one blind spot is recorded, not implied away.** A tab is classified by its declared
`$relationship`, and two managers declare one their table never queries — the activity tab is why
that matters, and it is covered by hand in `ATenantsHistoryStopsAtTheMallYouHoldTest` and listed in
`CrossPropertyTabs::NOT_CLASSIFIABLE_BY_RELATIONSHIP`.

**The other panels are out of scope by derivation, not omission.** The portal has one relation
manager (on a property-owned owner) and the vendor panel none, and neither scopes on PROPERTY: a
tenant is scoped to their own records and a contractor to the jobs dispatched to them
(`VendorScope`). A property scope on either would be the wrong axis.

**Swept and measured elsewhere, so the claim is bounded:** of the admin PAGES that query a
property-owned model, all but one scope, and that one (`Settings`) asks a portfolio-wide `exists()`
to lock a portfolio-wide accounting setting and discloses no row; all fourteen such WIDGETS scope.
There are no `getRelationManagers()` overrides and no `ManageRelatedRecords` pages, so `getRelations()`
is the complete registry.

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

**The gate earns its keep on the resource whose DOCBLOCK says it is scoped (2026-08-23).**
`RecurringExpenseResource` shipped with a docblock stating it was *"tenant-scoped by the panel in
the ordinary way and needs no `BypassesFilamentTenantAutoScope`"*, and that was wrong in both
directions at once: the **table listed every mall's schedules on every mall's screen**, and the form
exposes an editable `asset_id` through `PropertyField`, which is exactly the shape check **D**
exists to catch. Fixed with `ScopesToProperty` + `GuardsAssetInScope`, with `assertAssetInScope` on
**both** Create and Edit — Filament stamps `asset_id` on create only, so the edit form was the
unguarded half, which is the standing rule in [the write-guarding section](#2-write-guarding-which-property-a-createedit-may-target)
and the half most often missed. **A comment asserting a safety property is not the property**; only
the gate reading the built component is, which is why check D renders the form rather than reading
the source.

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

**Property-owned (91)** — scoped to the selected property:

`AnnouncementRecipient` · `Announcement` · `Area` · `AssetOwner` · `AssetUser` · `AssistantQuestion` · `BankAccount` · `BankMatch` · `BankStatementLine` · `BankStatement` · `Bin` · `BudgetLine` · `CamAllocation` · `CamExpensePool` · `Charge` · `CreditNoteApplication` · `CreditNoteItem` · `CreditNote` · `CustodyTransaction` · `Custody` · `Department` · `DepositApplication` · `DepositTransaction` · `DepreciationEntry` · `Disbursement` · `DocumentTemplate` · `EmployeeAdvanceRepayment` · `EmployeeAdvance` · `Employee` · `Equipment` · `Expense` · `FacilityWorkOrderComment` · `FacilityWorkOrderItem` · `FacilityWorkOrderLabour` · `FacilityWorkOrderPart` · `FacilityWorkOrder` · `FixedAssetDisposal` · `FixedAsset` · `Floor` · `Holiday` · `InvoiceItem` · `InvoiceWriteOff` · `Invoice` · `JournalEntry` · `JournalLine` · `LeaseCamTerm` · `LeaseClause` · `LeaseEvent` · `LeaseOption` · `LeasePercentageRentTier` · `Lease` · `LowStockAlert` · `MarketingBudget` · `MarketingPost` · `MarketingSpend` · `MeterReading` · `OwnerRequestReply` · `OwnerRequest` · `OwnerStatementRun` · `OwnerStatement` · `Payment` · `PayrollLine` · `Payroll` · `PostDatedCheque` · `PropertySetting` · `PurchaseRequestLine` · `PurchaseRequest` · `RecurringExpense` · `RentableItem` · `ServicePlanStop` · `ServicePlan` · `SlaPenalty` · `SlaPolicy` · `StockMovement` · `StraightLineRentAdjustment` · `TenantCreditApplication` · `TenantRequestComment` · `TenantRequest` · `TenantSalesDeclaration` · `UnitArea` · `UnitOwnership` · `Unit` · `UtilityMeter` · `VendorBillPayment` · `VendorBill` · `VendorContractAmendment` · `VendorContract` · `Violation` · `Warehouse` · `WorkOrderProposal` · `WorkPermit`

**Shared (37)** — portfolio-wide by design:

`AccountMapping` · `AccountingPeriod` · `ApprovalRule` · `AssistantDocChunk` · `ChargeCode` · `CustomField` · `DeviceToken` · `ExpenseCategory` · `FailureCode` · `FiscalYear` · `InventoryItem` · `LedgerAccount` · `Note` · `PaymentMethod` · `PayrollRate` · `RentIndex` · `ReportPreference` · `RetailCategory` · `SavedReport` · `SystemSetting` · `TableViewDefault` · `TableView` · `TaxCode` · `TaxRate` · `TenantDocument` · `TenantRequestSubcategory` · `TenantUser` · `Tenant` · `Trade` · `User` · `UtilityTariffRate` · `UtilityTariff` · `VendorContact` · `VendorDocumentType` · `VendorDocument` · `Vendor` · `ViolationCategory`

**Self (1)** — the property record itself:

`Asset`
<!-- /GENERATED:isolation-classification -->
