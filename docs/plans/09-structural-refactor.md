# 09 — Structural refactor for maintainability

*Drafted 2026-08-15. Not started. Branch: `worktree-refactor-architecture`.*

## Context

Atriom is 126k LOC of app code across 1,027 files, 939 commits in. It works, and 40 conformance
gates keep it working. The complaint is not that it's broken — it's that **adding or changing a
feature costs far more edits than the change itself warrants.**

This was measured, not guessed. Churn analysis over the last 400 commits plus a structural census
gives four concrete causes:

| Evidence | Measurement |
|---|---|
| `lang/{en,ar}/admin.php` | 5,562 lines each, touched by **161 of the last 400 commits (40%)** — 3× the next file |
| Central registries in `app/Support` | **20 registries, 5,384 LOC**; adding one model edits **7–9** of them (measured: `UnitOwnership` = 9, `MarketingPost` = 8). `DeletionPolicy` (28 commits) and `PropertyIsolation` (26) are top-5 app churn |
| Filament resource layer | All **50** admin resources extend Filament's `Resource` directly: **31** hand-roll the same scoping query, **9** copy `assertAssetInScope()` verbatim, **29** Create/Edit pages are pure boilerplate, **200** label methods restate a convention |
| Fat models | `Lease` 1,389 LOC / ~70 public methods / **top app churn (32)**; `Invoice` 811 LOC with a ~300-line `booted()` of 5 anonymous event closures. 59 of 101 models put logic in `booted()`, yet only **one** observer exists |

The unifying diagnosis is **shotgun surgery**: the system's knowledge about a module is scattered
across ~20 files organised by *concern*, when the thing that actually changes together is the
*module*. The fix is co-location — not new layers.

**Intended outcome:** adding a module goes from ~9 registry edits to 1 file; the two highest-churn
files stop being merge-conflict magnets; `Lease`/`Invoice` become readable. No behaviour changes,
no public API removed, no gate weakened.

## Guardrails

**What protects this refactor.** The 40 conformance gates are the immune system, and they're
unusually well suited here: they assert *structure*, and they enumerate models by
`glob(app_path('Models/*.php'))` — the **filesystem, not the registry**
([PropertyIsolationConformanceTest.php:58](../../tests/Feature/Scenarios/PropertyIsolationConformanceTest.php#L58)).
That is what makes moving a declaration from a central array onto the model safe: an undeclared
model still fails the build, so the completeness property is preserved. The suite runs in ~75–97s
(`pest --parallel`), cheap enough to run at every phase boundary.

**Explicitly not doing** — each of these is a real temptation that would be over-engineering:

- **No repositories, CQRS, hexagonal, or DDD bounded contexts.** Laravel + Eloquent + Filament is
  the stack; wrapping it adds indirection without removing work.
- **No table/form DSL over Filament.** The 813 `TextColumn::make` calls are declarative config, not
  duplication.
- **No deleting or loosening conformance gates.** They are the reason 939 commits landed safely.
- **No service-layer rewrite.** `TenantRequestService` (8 public methods) and friends deviate from
  the single-action idiom, but **325 test files instantiate services directly** — renaming or
  splitting them is churn for its own sake with a real breakage cost. Leave them.
- **No renumbering modules, no mass renames.**
- **Static analysis is out of scope** (owner's call). Filament `Schemas/*` and `Pages/*` stay
  excluded from PHPStan — ~22k LOC of the highest-churn UI layer unanalysed. CI stays off
  throughout; nothing here proposes changing that.

**Integration:** each phase is finished, suite-verified, and merged to `main` before the next
starts, so the worktree stays short-lived. This matters most for Phase 1 — with other sessions
pushing to `main`, a long-lived branch holding the `admin.php` split would conflict badly.

---

## Phase 0 — Worktree setup and baseline

The worktree has no `vendor`, `node_modules`, or `.env`.

- `composer install` — a **real install, never a symlink to the main tree's `vendor`**; symlinking
  makes every test die with "facade root has not been set".
- Copy `.env` from the main tree.
- `npm install && npx vite build` — **required, and easy to skip.** Without `public/build/manifest.json`
  every page-rendering test 500s on `ViteManifestNotFoundException`. That was 31 failures on the
  first Phase 2a run, plus 2 more wearing a disguise: `TranslationKeyConformanceTest` failed with
  *"0 is greater than 150"*, because it asserts its own sweep found something first and every
  screen it swept had 500'd. `npx vite build` is enough — the full `npm run build` also rebuilds
  the handbook, which no test reads.
- Record the baseline: run `pest --parallel` and note the case count and wall time **before**
  changing anything, so "pre-existing" is a measurement rather than an argument.

Run one heavy process at a time — no stacking a suite and a seed.

---

## Phase 1 — Split the translation monolith

**Target:** `lang/en/admin.php` and `lang/ar/admin.php` (5,562 lines each, 113 top-level keys).

**Approach — aggregator, not renaming.** Split each file into per-domain partials under
`lang/{en,ar}/admin/` (`leases.php`, `billing.php`, `cam.php`, `navigation.php`, `fields.php`,
`statuses.php`, `guides.php`, …), and reduce `admin.php` to a loop that requires the directory and
merges it.

This is the deciding constraint: **every key path stays identical**, so not one `__('admin.…')` call
site changes. It also keeps the six test files that do `require lang_path('en/admin.php')` and treat
the result as the full array working untouched — [TranslationCoverageTest.php:24](../../tests/Feature/TranslationCoverageTest.php#L24),
[FieldHelpConformanceTest.php:19](../../tests/Feature/Scenarios/FieldHelpConformanceTest.php#L19),
[NotificationLocaleConformanceTest.php:183](../../tests/Feature/Scenarios/NotificationLocaleConformanceTest.php#L183),
and three others. Renaming keys to per-file namespaces was the alternative; it would touch thousands
of call sites for no gain.

Partition EN and AR identically, file for file, so parity stays reviewable by diffing the two
directory listings.

**Verification:** `TranslationKeyConformanceTest`, `TranslationCoverageTest`,
`NotificationLocaleConformanceTest`, `FieldHelpConformanceTest`, `ManufacturedLabelConformanceTest`
— these already gate EN/AR parity and key existence, and all must stay green with zero edits to
them. Remember `Lang::has()` falls back to English; the existing checks already pass
`fallback: false`.

---

## Phase 2 — Co-locate module policy as attributes

**The big one.** Replace "central array listing every model" with "each model declares its own
policy; the registry derives".

### Step 2a — Decouple call sites from storage (pure refactor, no behaviour change)

The registries expose **public constants**, which cannot be computed — this must be fixed before the
backing store can change. Convert every const read to an accessor:

- `DeletionPolicy::NEVER_DELETABLE` / `WHEN_UNUSED` / `ALLOWED` → **23 read sites**
- `PropertyIsolation::OWNED` / `SHARED` / `SELF` → **13 read sites**

Accessors already exist for most of it (`DeletionPolicy::isNeverDeletable()`,
`blockingRelationsFor()`, `PropertyIsolation::isOwned()`, `ownedModels()`); add the few missing ones
and point the remaining const reads at them. Ship and merge this on its own — it is mechanical,
reviewable, and leaves the arrays in place.

### Step 2b — Move declarations onto the models

Define attributes in `app/Support/Attributes/`, and have each registry derive from a **cached
reflection scan** over `glob(app_path('Models/*.php'))` — the same discovery the gates already use.

```php
#[PropertyScoped(via: 'unit.asset')]
#[Deletion(Tier::WhenUnused, blockedBy: ['allLeases'], instead: 'deactivate')]
#[Searchable]
#[MorphAlias('unit')]
class Unit extends Model { … }
```

**The registry classes keep their names and public method API**, so all 40 gates and every call site
keep working. `DeletionPolicy::for()` still answers; only its backing store changes.

Status, one merge each:

| Registry | Result |
|---|---|
| `DeletionPolicy` | **DONE** — 548 → 240 |
| `PropertyIsolation` | **DONE** — 343 → 173 |
| `PostingDateGuards` | **DONE** — 155 → 93 |
| `SearchPolicy` | **deliberately NOT migrated** — see below (but a live bug it hid is fixed) |
| `ChangeImpact` | **deliberately NOT migrated** — see below |
| `MorphMap` | **deliberately NOT migrated** — see below |
| `DerivedFields` | **deliberately NOT migrated** — see below |

**`MorphMap` stays a central list, and this is a decision rather than a gap.** Two reasons, either
sufficient. First, it is boot-critical: `AppServiceProvider::boot()` calls
`Relation::enforceMorphMap(MorphMap::MAP)` on *every* request, job and command, and needs the map
COMPLETE — so unlike the others there is no cold path, and deriving it would glob and autoload all
101 models on every boot, including requests that touch no model. Second, an alias is **permanent
data stored in years of rows**, not a policy that travels with the model: renaming one is a data
migration, and `2026_08_15_170000_store_morph_aliases_instead_of_class_names` reads the map, so a
historical migration would come to depend on live model files. A single reviewable list is the right
shape for identifiers whose whole point is that they never change.

**`DerivedFields` is not per-model at all.** It is keyed by Filament *form and importer* classes
(`LeaseForm::class`, `LeaseImporter::class`) and its values are per-field metadata including a test
path. There is no model to hang it on.

**`SearchPolicy` splits three ways and none of them wants an attribute.** `PRIORITY` is an ORDERED
list of resources — the order *is* the data, and the file's own docblock records that it exists
precisely to stop "a magic integer scattered across 35 resource classes that nobody can order
relative to each other without opening all 35". Distributing it would rebuild the problem it solved.
`GLOBAL_SEARCH_EXEMPT` is keyed by resource, not model. And `INDEXED` needs no new declaration at
all, because **a model already declares it is searchable by using `HasSearchText` and implementing
`searchTextSources()`** — an attribute would be a third statement of the same fact.

That last point surfaced a live bug rather than a refactor: `RentableItem` carried the trait, the
`search_text` column and a resource that searches the blob, but was never listed in `INDEXED` — and
`atriom:rebuild-search` iterates `INDEXED`. So the one command that re-folds blobs skipped it
entirely: change the fold or its `searchTextSources()`, run the rebuild as the docs instruct, and
every existing rentable item keeps its old blob while newly-saved rows get the new one. Fixed, and
`SearchPolicyConformanceTest` gained the **completeness** direction nothing was asking — every other
check there starts from `INDEXED` and validates what is in it, which passes happily while something
is missing from it. Proven by deleting the fix and watching the new gate fail.

**`ChangeImpact` has nowhere to attach.** Its policy is per-model *and per-COLUMN*, and Eloquent
columns are not PHP properties — there is no member to hang a per-column attribute on. The only
available shape is one class-level attribute carrying the whole nested map, which is the array
relocated rather than knowledge co-located: same content, worse syntax, no maintainability gain.
It is also the one register read **cross-sectionally** — "which fields anywhere may never change
once posted" is a question you answer by reading all of `REFUSED` together, and scattering it across
20+ models destroys exactly that. Its long argued reasons benefit from the grouped, comparable
layout they already have.

**The rule this converged on: migrate a registry only when its data is genuinely a per-model policy,
its enumerating path is cold, and the model is the natural place to ASK the question.** Three of the
seven candidates met all three; the other four are better where they are. The plan originally assumed
all seven would move — that estimate was wrong, and forcing the remaining four would have been motion
rather than progress.

**Two techniques from the pilot, both of which cost a wasted attempt to learn:**

1. **Migrate with a generator that reads the old registry through its Phase 2a accessors** — never
   by hand. 101 models were attributed by the thing that already held the data, so a `blocked_by`
   relation name could not be mistyped in transit, and a mistyped relation blocks nothing while
   looking exactly like a working guard.
2. **Do not run Pint afterwards.** The models are not Pint-clean, so a formatter pass rewrote trait
   order, const spacing and concat style in code the change never touched — 222 lines of unrelated
   churn across 101 files. Insert the `use` in sorted position in the generator instead; the diff
   then lands at exactly two lines per model, which is the only thing that makes a 101-file change
   reviewable.

**Prove parity, don't assert it.** Load the previous registry from git under a second class name
alongside the new one and diff every tier (`scratchpad/verify_deletion_parity.php` is the shape).
The pilot came out 16 / 16 / 69 and 101 classified, byte-equal. Pair that with regenerating the
handbook datasets and checking for zero drift.

**Leave central**, because they are genuinely cross-cutting rather than per-model — moving them
would be co-location for its own sake: `ValueSets`, `DashboardLayout`, `ReportCatalogue`,
`SettingsRegistry`, `ActivityVocabulary`, `NotificationTargets`, `FieldHelp`, `ActionAuthz`,
`QueueJobSafety`, `Modules`.

**The one thing to get right.** A gate that derives from the same source as the thing it checks
cannot see what that source omits. Each gate must keep discovering models by globbing the directory
and *then* asking whether an attribute is present — never iterate the attribute scan itself. Add a
shared `Tests\Support\ModelDiscovery` helper so the ~8 gates that each re-implement
`glob(app_path('Models/*.php'))` share one honest enumeration.

`php artisan atriom:dump-registries` and `atriom:dump-handbook-data` read these registries and must
keep producing byte-identical output — `GeneratedDocsConformanceTest` and
`HandbookDataConformanceTest` will say so.

---

## Phase 3 — Base Filament resource — **DONE**

**Outcome: every remaining `getEloquentQuery()` in the admin panel is a singleton shape.** It began
with 18 resources sharing one copy-pasted query and 5 sharing another; there is no duplicated
scoping logic left, and **29 resources share one path** via `ScopesToProperty`, which reads the rule
off the model's `#[PropertyOwned]`.

| Step | What |
|---|---|
| 3a | 18 strict resources → the trait (−354 lines) |
| 3b | 5 hybrid money resources; `portfolioRowsWhenNull` declared on the model |
| 3c | 6 resources with an aggregate reuse `scopeToProperty()` rather than restating it |

**Five resources deliberately keep bespoke queries**, each checked rather than forced: `Asset` (the
property itself — a different axis), `CreditNote` (ORs two chains), `Department` (deliberately does
not narrow to the *selected* property), `InventoryItem` (shared catalogue, stock scoped by warehouse
subquery), `OwnerRequest` (branches on role, filters on `recipient`).

**The rollout lesson, worth more than the diff.** The first script stripped each method with a
multiline regex that also swallowed any docblock above it; under `/s` the `.*?` matched *across*
earlier methods and deleted two of them. The isolation suite caught it. Rewritten line-based — walk
from the signature to the closing brace, take only comment lines directly above, never past a blank
line or another member. **A regex spanning arbitrary lines has no business editing PHP.**

---

## Phase 3 — original design notes

**Depends on Phase 2, deliberately.** Once `PropertyIsolation` can answer each model's scoping mode
from an attribute, the base resource implements `getEloquentQuery()` **once** for all 50 resources —
direct-column and relation-chain alike — instead of 31 hand-rolled copies plus the
`ScopesViaProperty` / direct-column split. Doing this phase first would mean writing a
`ScopesByAssetColumn` trait that Phase 2 then makes redundant.

Introduce `App\Filament\Admin\Resources\AtriomResource`, composing the existing traits
(`RoleGatedActions`, `BypassesFilamentTenantAutoScope`, `SearchesNormalizedText`,
`GuardsAssetInScope`) and adding convention-over-configuration, exactly as `RoleGatedActions`
already derives `permissionModule()` from the model name
([RoleGatedActions.php:52](../../app/Filament/Admin/Resources/Concerns/RoleGatedActions.php#L52)):

- **Scoping** — one generic `getEloquentQuery()` driven by the model's attribute. Keep the
  `visibleAssetIds()` fallback exactly as written; never fall back to unscoped.

  **Measured, and the earlier "31 identical copies" in this plan was wrong.** Normalising every
  `getEloquentQuery()` body in `app/Filament/Admin/Resources` gives **16 distinct shapes**:

  | Shape | Count | What it is |
  |---|---|---|
  | strict direct column | **18** | `where('asset_id', …)` / `whereIn` — genuinely identical |
  | hybrid nullable | **5** | adds `orWhereNull('asset_id')` — portfolio rows visible to all |
  | bespoke | ~10 | one each |

  So the base needs **two modes**, not one: strict and hybrid-nullable. The hybrid set
  (`Expense`, `VendorBill`, `JournalEntry`, `Payroll`, `DepositTransaction`) is a real behavioural
  difference, not drift — collapsing it into the strict form would hide portfolio-level rows.

  Most of the bespoke ten still benefit: several differ *only* by an eager-load or aggregate
  (`CustodyResource` adds `withSum`, `FacilityWorkOrderResource` adds `withCount`). Once the base
  scopes, those become `return parent::getEloquentQuery()->withCount([…]);` — a one-line override
  instead of a re-implementation. Genuinely different ones (`AssetResource` is the property itself;
  `CreditNoteResource` ORs two chains) keep their own and should say why at the call site.
- **Labels/nav** — derive `getNavigationLabel()`, `getModelLabel()`, `getPluralModelLabel()`,
  `getNavigationGroup()` from the permission module key. Removes ~200 methods; resources override
  only where they genuinely differ.
- **Guide mounting** — mount `GuideAction::for(static::getResource())` in a base List page instead of
  per resource.

Then a `GuardsAssetOnWrite` page trait that wires `assertAssetInScope()` into
`mutateFormDataBeforeCreate`/`BeforeSave` automatically, collapsing the **29** boilerplate
Create/Edit pages ([BankAccounts/Pages/](../../app/Filament/Admin/Resources/BankAccounts/Pages/) is
the representative pair).

Roll out a few resources at a time, not all 50 at once.
[BankAccountResource.php](../../app/Filament/Admin/Resources/BankAccounts/BankAccountResource.php) is
the cleanest pilot.

**Verification:** `PropertyIsolationConformanceTest` (it separately checks the write-guard is wired
on Create *and* Edit), `ActionAuthzConformanceTest`, `ScreenGuideConformanceTest` (checks guides are
**mounted**, not merely written), `AdminSmokeManifestConformanceTest`.

---

## Phase 4 — Decompose `Lease` and `Invoice`

Extract cohesive domain concerns as traits. Traits preserve the public method surface exactly, so
**no call site and none of the 325 service-touching tests change** — that is the whole reason to
prefer them to extracted collaborator classes here.

[app/Models/Lease.php](../../app/Models/Lease.php) (1,389 → ~350 core) into `app/Models/Concerns/Lease/`:

- `HasLeaseUnits` — `units()`, `unitsOn()`, `masterUnit()`, `syncUnits()`, `totalAreaSqm*()`,
  `deriveBaseRentFromRate()`, the two `constrainTo*` scopes (~265 lines)
- `DeterminesBillability` — `firstBillableMonth()`, `rentCommencesOn()`, `inFitOutWindow()`,
  `abatedChargeTypesFor()`, `isBillableForPeriod()`, `isBillableHoldoverFor()`,
  `scopeBillableForPeriod()` (~330 lines)
- `HasCamTerms` — `camTerms()`, `resolveCamCeiling()`, `camCapScope()`,
  `camCapHeadroomBankedBefore()`, `statedCamSharePct()` (~80 lines)
- `HasLeaseLifecycle` — `isActive()`, `isTerminal()`, `isHoldover()`, `daysUntilExpiry()`, the
  holdover scopes
- `ActsAsBillableAgreement` — the `App\Contracts\BillableAgreement` implementation (`assetId()`,
  `paymentTermsDays()`, `billingTenantId()`, `billingCurrency()`, `lateFeeTerms()`)

[app/Models/Invoice.php](../../app/Models/Invoice.php) similarly: `HasInvoiceTotals`
(`recomputeTotals()`, `syncTotalsFromItems()`, `capturedCashPaid()`), `HasPaymentLink`,
`HasInvoiceStatus`.

**Then move the event closures into observers.** `Lease` has 7 anonymous event closures, `VendorBill`
6, `Invoice` 5 (~300 lines) — business logic that no method-name scan reveals and that can't be
tested in isolation. `LeaseObserver` already exists and is registered at
[AppServiceProvider.php:158](../../app/Providers/AppServiceProvider.php#L158), so this extends an
established pattern rather than inventing one. **Only these three models** — converting all 59
`booted()` models would be mass churn; a 10-line inline `creating` hook is clearer where it is.

Two traps this codebase has already been bitten by, both documented in `CLAUDE.md` and both live in
this phase's blast radius: `search_text` needs **both** the `saving` and `created` hooks (document
numbers are assigned in `creating`), and `recomputeTotals()` must keep counting all **four**
settlement channels. Move this code; do not rewrite it.

**Verification:** the money invariants are the risk. Run `tests/Feature/Regression/` and
`tests/Feature/Scenarios/` in full, plus `SearchPolicyConformanceTest`,
`ActivityLogVocabularyConformanceTest`, `ChangeImpactConformanceTest`, `DerivedMoneyConformanceTest`.

---

## Verification

Per phase, before merging to `main`:

1. `vendor/bin/pest --parallel` — fully green, and the **case count must not drop** (a silently
   skipped file reads as a pass).

   **Do not read the exit code — read the JSON `result` field.** The reporter configured here
   exits **0 on a failing run**: Phase 2a's first run reported `exit code 0` with 34 failures and
   1 error. Anything that trusts `$?` — a script, a hook, a habit — will call a red suite green.

   When something does fail, prove whose it is before assuming it's yours: `git checkout -b tmp main`,
   run just those tests, then switch back and delete the branch. Phase 2a's two survivors
   (`DemoSeederTest` 39-vs-33, `FilterCorrectnessTest` on `UnitManagementMode`) reproduced exactly
   at main's tip and were another session's, not the refactor's.
2. The gates named in that phase, run individually so a failure names itself.
3. `php artisan atriom:dump-registries`, `atriom:dump-system-census`, `atriom:dump-handbook-data`,
   `atriom:dump-admin-manifest` — regenerate and confirm no unexplained diff.
4. `php artisan migrate:fresh --seed` then spot-check the panel by hand: a lease, an invoice, the
   GL, and one property-scoped list in both EN and AR.
5. Update the affected `docs/modules/NN-*.md` in the **same commit**, per project convention.

If the suite exits 255 with no output, that's two test files declaring the same file-scope helper —
run `vendor/bin/pest tests/Feature/Scenarios/TestHelperUniquenessConformanceTest.php`, which is the
one command that survives it.

## Expected outcome

- ~5,400 LOC of central registries → ~1,500, and adding a module edits **1 file instead of 9**
- Two 5,562-line translation files → ~20 partials; the file behind 40% of commits stops colliding
- ~1,200 LOC of Filament boilerplate removed; 50 resources get one scoping code path
- `Lease` 1,389 → ~350; `Invoice` 811 → ~400; event logic testable
- Zero public API removed, zero gates weakened, zero behaviour changed
