# 34 · Search (global + per-list)

How an operator **finds a record**. Cross-cutting rather than a domain module: it touches every resource in
both panels, so it has its own registry and its own conformance gate, in the same shape as
`PropertyIsolation` and `DeletionPolicy`.

> **The problem it replaces.** Search was 47 resources left on whatever Filament's defaults happened to give
> them. That is not "basic search" — it is a lottery whose failures are all **silent**, because an empty
> result set looks exactly like *no such record*. Nobody reports a search that finds nothing; they retype it,
> then open the list and page through. Concretely, before this: **7 resources** had no globally searchable
> attribute at all (`UtilityMeter` has a unique `meter_number` and was one of them); **3** searched an integer
> or an enum (typing `1` returned accounting periods 1, 10, 11 and 12); `ViolationResource` pointed
> `$recordTitleAttribute` at `reference`, which is a **PHP accessor, not a column**; **5 tables** rendered no
> search box; **17 more** searched exactly one column — `VendorsTable` searched `name` while global search
> covered `legal_name`, `tax_id`, `email`, so the top bar could find a vendor by tax ID that the vendor *list*
> could not. No resource had overridden Filament's 50-rows-per-resource limit, so one keystroke could hydrate
> ~1,750 models across every category. There were **zero** search tests.

## 1. The fold — one comparable form for both sides

`App\Support\Search\SearchText` is the single place a searchable string is normalized. Both sides must go
through it: the stored value and the operator's query. Folding one side matches nothing.

An Egyptian operator types the same name several ways, and these are the same word to a human and different
strings to `LIKE`:

| Typed | Also typed | Folds to |
|---|---|---|
| أحمد | احمد | `احمد` (hamza carriers → bare letter) |
| شركة الفتح | شركه الفتح | `شركه الفتح` (teh marbuta → heh) |
| مصطفى | مصطفي | `مصطفي` (alef maqsura → yeh) |
| مُحَمَّد | محمد | `محمد` (tashkeel stripped) |
| ٢٠٢٦ | 2026 | `2026` (Arabic-Indic digits → ASCII) |
| `INV-AW-202607-0110` | `INVAW2026070110` | `invaw2026070110` (punctuation stripped) |

Punctuation is stripped **without** leaving a space — that is what makes `INV2026` match `INV-2026`.
Whitespace is preserved, so multi-word names still match word by word (terms are split and **ANDed**, so more
words narrow rather than widen). A query that folds to nothing (`---`) yields `[]`, which callers must read as
*do not search* — never as *match everything*.

Deliberately **not** done: stemming or Arabic root extraction. An ERP searches proper nouns and document
numbers, not prose.

## 2. `search_text` — the denormalized blob

Every searchable model carries a `search_text` column maintained by `App\Models\Concerns\HasSearchText`. The
model declares `searchTextSources()`; the trait folds and stores.

**The one invariant: the blob is a pure function of the row's OWN attributes.** It must never reach through a
relation. Putting the tenant's name into the invoice's blob would let one column answer "invoice for Zara"
with no join — and would make renaming a tenant silently strand every invoice blob quoting the old name. A
save is then the only thing that can invalidate a blob, so the trait's own hook is a complete guarantee.
Cross-record search stays a relation search against the **related model's** blob (`tenant.search_text`).

**"Pure function of the row" includes the ORDER, and a JSON source breaks it (2026-08-23).** Custom-field
answers reach the blob through `HasCustomFields::customFieldSearchValues()`, which iterated `metadata` in
whatever order the array arrived in. `metadata` is a native `json` column on MySQL, and **MySQL does not
preserve object key order** — it normalises, shortest key first — so the order PHP wrote was not the order the
row read back, and every `atriom:rebuild-search` rewrote every blob with the same answers resequenced
(`… 235264657 americana group fandb` → `… 235264657 fandb americana group`). Harmless for substring matching
and wrong twice: it breaks the invariant this section states, and **a rebuild that rewrites every blob buries
a real change in the churn** — which is the whole reason the command is documented as idempotent below.
`ksort` before folding. **The Pest suite is structurally blind to it**: on SQLite `metadata` is TEXT and keeps
insertion order, so both paths agree there whatever the code does. The regression test pins **order
independence** rather than a golden string, because a golden string passes on SQLite while the bug runs on
MySQL — which is exactly what let it ship. Any future source that is a JSON column, not a scalar column, owes
the same treatment.

**Two hooks, not one** — `saving` is genuinely too early for the two most-searched values in the system:

- **Document numbers.** Eloquent fires `saving` → `creating` → INSERT, and `AllocatesDocumentNumber` assigns
  `number` in `creating`. A `saving`-only fold stores a blob with **no invoice number in it**. Every numbered
  document has this shape (invoice, credit note, vendor bill, expense, journal entry, payroll, deposit
  transaction).
- **Id-derived references.** `Violation::$reference` is `'VIO-'.str_pad($this->id)` — no id exists before the
  INSERT, so the first fold would bake in `VIO-00000` forever, on the only identifier printed on the notice
  handed to the tenant.

So `created` re-folds and writes **only if the value changed** — a comparison, not a write, on every model
whose sources are plain columns.

**No index, on purpose.** Both Filament search paths build `LIKE '%term%'`, and a leading wildcard cannot use
a B-tree index under any circumstance. The blob buys **correctness** (Arabic folding, accessor values,
punctuation-insensitivity) and one column scan in place of five ORed ones — not index usage. The column is
also `$hidden` on every model (via the trait's `initialize` hook) so it never rides along in `/api/v1`
responses or Livewire payloads, and is never `$fillable`.

Adding a model to `SearchPolicy::INDEXED` does **not** add the column: the migration is a fixed historical
snapshot. The gate fails, naming the table, until a new migration adds it.

## 3. Global search

`App\Support\Search\AtriomGlobalSearchProvider` replaces Filament's stock provider on **both** panels, for
three things that are about the result set as a whole:

1. **A floor on the query** (`SearchPolicy::MIN_QUERY_LENGTH`, measured on the *fold*). Every keystroke fans
   out one query per resource; one character matches most of the database, so the expensive answer is also the
   useless one.
2. **Deterministic category order** from one ordered list (`SearchPolicy::PRIORITY`) instead of a magic
   integer scattered across 35 classes.
3. **An exact hit outranks the standing order.** Pasting `INV-AW-202607-0110` surfaces that invoice first.
   Detected by comparing *folds*, so it works for a number typed without dashes and for an Arabic name spelled
   the other way — and self-maintaining, unlike a prefix→resource map that would drift from the
   document-number formats.

Panel config adds **⌘K / Ctrl+K** with the binding rendered inside the field (a shortcut nobody can see is a
shortcut nobody uses) and a 400 ms debounce.

**Authorization and property isolation are inherited, not re-implemented.** `canGloballySearch()` calls
`canAccess()`, and `getGlobalSearchResults()` runs through `getGlobalSearchEloquentQuery()` →
`getEloquentQuery()` — exactly where `ScopesViaProperty` and the hand-rolled `asset_id` clauses live. A second
copy in the provider would be a second thing to keep in sync, and the one that drifted would be the untested
one. Filament additionally drops any result whose URL is blank, so a record the role cannot open never appears.

> **Gotcha for tests:** that last rule means a search test without the permission catalogue seeded returns
> **zero for everything** — so every refusal assertion passes for the wrong reason. Pair each refusal with a
> control that must find something. `SearchIsolationTest` does, and it caught exactly this.

## 4. List (table) search

`App\Support\TableDefaults` gives **every** table the blob search as an extra searchable column, ORed with
whatever columns the table marks searchable. Registered centrally because the failure mode is silent and the
list is long — table #48 inherits correct search rather than inheriting whichever column its author remembered.

Filament wraps the whole search in its own nested `where(...)` group, which is what keeps the property scope
outside it. That is load-bearing and **not** something to take on trust: without the wrapper,
`(scope AND blob) OR tenant.name` would bind AND-before-OR and the OR branch would escape isolation entirely.
`TableSearchTest` drives the real Livewire component to prove it, because a hand-built query omits exactly the
wrapper that matters.

Column-level `->searchable()` still matters: it powers per-column search boxes and reaches **through**
relations, which the blob deliberately cannot.

Because the blob is what renders the box, a table whose model has **no** blob and **no** searchable column
must call `->searchable(false)` explicitly — 18 do (14 relation managers + 4 resource tables), each with the
reason at the call site. A search box that always returns nothing is worse than none: it reads as *no such
row*.

## 5. Pickers — the surface the fold never reached

Everything above answers *find me the record*. A **dropdown** asks the same question from inside a form, and
until 2026-08-17 it answered it completely differently: `Select::make('tenant_id')->relationship('tenant',
'name')->searchable()`, 119 times, across both panels.

That one line is wrong four ways and none of them raises an error:

| | What it did | What it should have done |
|---|---|---|
| **Fields** | searched `name` only | search the whole `search_text` blob |
| **Fold** | raw `LIKE` on a raw column | fold both sides, like every other surface |
| **Label** | one column | enough to tell two records apart |
| **Failure** | empty / ambiguous dropdown | — |

Concretely: a tenant's **phone number** has been in the blob since the blob existed and was findable from the
top bar and the tenant list — and not from the tenant picker. «شركه» did not find «شركة». A mall running
«Zara», «Zara Home» and «Zara Kids» offered three identical rows. Every one of those reads as *no such
record*, so none was ever reported; the operator retypes, then leaves the form.

### The one component

`App\Support\Filament\EntitySelect` (+ `EntitySelectFilter`, because a `SelectFilter` is not a `Select`)
takes everything from one registry:

```php
EntitySelect::make('tenant_id')
    ->entity(Tenant::class)                                     // search, label, scope, cost
    ->modifyOptionsQuery(fn ($query) => $query->active())       // this SCREEN's narrowing
    ->preload()                                                 // browse-first pickers only
```

`->entity()` and `->relationship()` compose in either order — `Select::relationship()` installs its own
`getSearchResultsUsing()`/`options()`/`getOptionLabelUsing()`, so `EntitySelect` re-applies after it. Without
that override a chain that ended with `->relationship()` would silently revert to stock behaviour, which is
the exact failure this component exists to remove and the one that looks like it is working.

### `OptionDisplay` — what a record looks like when it is being picked

`App\Support\Search\OptionDisplay` decides **presentation**, **cost** and **reach** for ~25 models; the
match itself is still `HasSearchText`'s folded blob, unchanged. It feeds three surfaces from one definition —
the options in a dropdown, the value shown once one is chosen, and the details under a global-search hit
(which were per-resource before, present on 21 of ~35 and blank on the rest).

- **`presenters()`** → a `RecordOption` of *title · code · subtitle · badge*. Four fields, chosen for one
  question: standing in front of two of these, what tells them apart? A tenant carries its unit and phone; an
  invoice its due date and what is still outstanding; a unit its floor, area and state.
- **`EAGER`** → what each presenter reaches for, so a page of options costs a constant number of queries. The
  gate asserts every relation named here **exists** — a typo silently restores the N+1 and looks like a fix.
- **`PRELOAD`** → sets bounded by the shape of the business (property, zone, floor, department, warehouse).
  Not "small today": a tenant table is small on day one of every deployment. A picker where *browsing* is the
  flow rather than looking up — the lease form's unit pickers — opts in per call site with `->preload()`.
- **`scope()`** → **derived from `PropertyIsolation`**, never re-listed. `#[PropertyOwned(via: 'unit')]`
  already says how a Lease reaches a property. `PICKER_SCOPES` holds the one case that needs more.

> **The tenant picker was scoped three different ways in three files.** `InvoiceForm` used
> `whereHas('leases.unit')` — so a unit OWNER, who holds no lease at all, could be invoiced by the services
> and never picked on the form. `PaymentForm` had the correct lease-or-ownership version.
> `TenantScope::selectableTenantOptions()` used `leases.unit` **or** `doesntHave('leases')`, which offered a
> tenant with no lease but an ownership in **another** property to every property in the portfolio. One
> definition now, and the unaffiliated branch checks both relations.

### Escaping is the reason the label is a value object

A two-line option needs markup, and markup needs `Select::allowHtml()` — which makes Filament emit the label
through `{!! !!}` and hand it to the browser as `innerHTML`. Every value in an option is operator-typed. So
`RecordOption::toHtml()` escapes each part and is the **only** function that builds option markup; the gate
fails an `allowHtml()` select whose label comes from anywhere else. `toText()` exists for filter indicator
chips, native selects and tests, where markup would be wrong rather than merely unnecessary.

### The property scope is a WRITE guard, not a filter

Filament validates a Select by asking it to resolve the submitted value's label, and rejects the value with
`Rule::in([])` if it cannot (`Select::getInValidationRuleValues()`). So **the label lookup is the guard
against a posted foreign key from another property.** `OptionDisplay::label()` therefore resolves through
`pickable()` — scoped — even though an unscoped `find()` reads as the friendlier choice. Making that lookup
unscoped would turn every entity select in the panel into an accepted cross-property FK, silently.

The corollary caught a real mismatch: the owner-request property picker was built from `accessibleAssets()`
while `assertAssetInScope()` measured against `visibleAssetIds()`, so it **offered properties its own guard
would 403**. Picker and guard now read the same source.

### A picker reaches through relations — derived, not re-listed

`HasSearchText`'s invariant is that a blob is a pure function of the row's OWN attributes, so a
lease's blob holds `LSE-AW-2026-0001` and nothing else. Typing a tenant's name into the LEASE picker
therefore found nothing — while typing it into the top search bar found the lease immediately,
because `LeaseResource` declares `['search_text', 'tenant.search_text', 'unit.search_text']`.

Two surfaces, one question, two answers. The resources were right; the pickers were the half that
never read them. `OptionDisplay::searchRelations()` **derives** the paths from that declaration
(17 resources already carry one), so a resource that adds a path tomorrow reaches its picker for
free and the two can no longer disagree.

Semantics match the rest of the system: **words AND, sources OR**. `cilantro a-04` narrows to the
lease matching both — one word through the tenant's blob, one through the unit's.

> **The hazard, and the test that must never be deleted.** Adding an OR to a scoped query is exactly
> how a property leak gets written: `(scope AND ownBlob) OR relationBlob` binds AND-before-OR and the
> OR branch escapes isolation. `PickersReachThroughRelationsTest` puts the SAME tenant in two
> properties, so a leak is not hypothetical — the query that finds one finds the other unless the
> grouping holds. Same trap already recorded for table search in `TableSearchTest`.

### See narrow, find wide — `->suggest()`

What you SEE when a picker opens and what you can FIND when you type are different questions, and
collapsing them is a bug in both directions. Narrowing the options alone shows nothing (a
search-only picker is empty until typed into). Narrowing the SEARCH refuses legitimate values —
Filament rejects a value the picker cannot label.

`EntitySelect::suggest()` narrows the browse list only. The tenant-request form opens on the
reporting tenant's own units and still lets an operator type their way to the unit next door.

### The one exception: pickers that are ABOUT the portfolio

`->acrossProperties()` drops the derived scope for a picker that is not filling in a record's own
field. There is currently **one**: the user form's property-assignment field, which grants access
across malls and defaults to every real property — scope it to the mall the grantor happens to be
working in and the form's own default fails its own validation. Since the scope is also the write
guard, a call site using it owns the question of what the submitted value may be, and states its
reason inline.

(The other candidate went the other way. The owner-request property picker was built from
`accessibleAssets()` while its guard measured `visibleAssetIds()` — it OFFERED properties it would
then 403. That one was a bug, not an exception, and both sides now read the same source.)

### Counterparty codes

A tenant and a vendor now carry a code — `TN-0000042`, `VN-0000018` — allocated by `AllocatesPartyCode`
under the same lock as every document number, configurable from the same `DocumentNumbering::TYPES`, and kept
as-is when an import supplies one. They were the only two records in the system people talk about daily that
had no number: a unit is `A-114`, an employee `EMP-0042`, a lease `LSE-AW-2026-0001`. Yardi, MRI and Entrata
all make the tenant code the primary handle.

`EntitySelectConformanceTest` gates the whole of section 5: every model-backed picker is an `EntitySelect`,
every `EntitySelect` declares an entity, only `RecordOption` builds markup, every eager relation resolves,
every presenter runs, and the picker vocabulary exists in **both** languages. It distinguishes a record
picker from a value picker by what the query keys on (`pluck('name', 'id')` vs `pluck('city', 'city')`)
rather than by a registry of exceptions.

## 6. Registry & gate

`App\Support\SearchPolicy` holds what Filament has no home for: the indexed-model list, exemptions **with a
stated reason**, the category order, the query floor and the per-resource result limit. There is deliberately
**no** table-exemption registry — all three table states are readable from the code itself, so a list would be
a fourth thing to keep in step with three that cannot drift.

`SearchPolicyConformanceTest` enforces:

- every resource is globally searchable **or** exempt with a reason (no third state), and an exempt resource
  really has `$isGloballySearchable = false`;
- every searchable path ends in `search_text`, and every relation path resolves to a **real** relation whose
  model **carries a blob** (a typo throws only at search time; a valid relation without a blob throws nothing
  and matches nothing);
- every registered model has the trait, a non-empty source list, a real column, and keeps the blob hidden and
  non-fillable;
- no table renders a search box it could never answer;
- the fold itself still folds the spellings above.

Verified by mutation: removing a `->searchable(false)`, pointing a path at a raw column, and typo'ing a
relation name each fail the specific assertion that claims to cover them.

## 7. Extension points (how to change safely)

- **New searchable module** → add the model to `SearchPolicy::INDEXED`, add `HasSearchText` +
  `searchTextSources()`, write a migration adding `search_text`, add `SearchesNormalizedText` to the resource
  and return `['search_text', ...]`. The gate names whichever step you skip.
- **Not worth searching?** → `$isGloballySearchable = false` + an entry in `GLOBAL_SEARCH_EXEMPT` saying why.
- **Changing the fold** (`SearchText`) → run **`php artisan atriom:rebuild-search`**. Nothing rewrites stored
  blobs on its own, so until it runs, existing records are searchable only under the OLD rules. Same after
  changing a model's `searchTextSources()`.
- **Reordering results** → `SearchPolicy::PRIORITY`, one list, top to bottom.
- **A new dropdown that picks a record** → `EntitySelect::make(...)->entity(Model::class)`. Nothing else is
  needed; the gate names it if you reach for a plain `Select`. Add a presenter to `OptionDisplay` when the
  model's name alone would not separate two of them, and an `EAGER` entry for whatever that presenter reads.
- **A screen-specific fact on one picker** (the lease form's *⚠ encumbered*) → `->decorateOption()` +
  `->withRelations()`, not a line in the presenter that every other form would also have to read.

## 8. Gotchas

- `search_text` is derived. A mass `Model::query()->update()` fires no model events and leaves it stale —
  `atriom:rebuild-search` is the repair (idempotent; a re-run rewrites nothing and never moves `updated_at`).
- The rebuild is **not** scheduled: the `saving` hook keeps blobs current in normal operation, and a nightly
  full-table rewrite that fixes nothing is exactly the job that gets ignored when it finally does report
  something.
- Two deliberate cross-property visibility surfaces, unchanged by this work and documented here because search
  is where they become visible: a tenant with **no lease anywhere** is searchable from every property
  (`TenantResource::getEloquentQuery()` — unaffiliated tenants belong to no property), and consolidated
  company-level rows (`orWhereNull('asset_id')` on Expense / JournalEntry / Payroll / VendorBill /
  DepositTransaction) surface to a property-pinned user.
- **Global search TITLES are untouched by the picker work, on purpose.** `AtriomGlobalSearchProvider`
  promotes an exact hit by comparing the *fold of the title* to the fold of the query; a rich composite title
  would never match and the promotion would silently stop working. Only the DETAILS come from `OptionDisplay`.
- A `SelectFilter::relationship()` needs its title attribute — `Select::relationship()` does not.
  `EntitySelectFilter` defaults it, because the natural shape once labels come from the registry is
  `->relationship('tenant')`, and the mismatch is a **fatal at class load**: the filter form is built lazily
  inside the table's Blade, so the page 500s on opening the filter popover and every test that never opens one
  stays green.
- **`TenantScope::selectableTenantOptions()` was deleted 2026-08-17**, once every tenant picker moved to `EntitySelect`. It was the third of the three divergent tenant scopes and the one that leaked (`orWhereDoesntHave('leases')` offered a tenant who owned a unit in another mall to every property). `selectableAssetOptions()` REMAINS and is still correct — the ledger reports use it for the asset DIMENSION, which is a posting concept rather than a place.
- `ViolationResource::$recordTitleAttribute` is still `reference`, an accessor. That is safe **because** the
  searchable attributes are explicit — it is used for display only. Do not "simplify" it back into a search key.
