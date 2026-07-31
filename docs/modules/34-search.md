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

## 5. Registry & gate

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

## 6. Extension points (how to change safely)

- **New searchable module** → add the model to `SearchPolicy::INDEXED`, add `HasSearchText` +
  `searchTextSources()`, write a migration adding `search_text`, add `SearchesNormalizedText` to the resource
  and return `['search_text', ...]`. The gate names whichever step you skip.
- **Not worth searching?** → `$isGloballySearchable = false` + an entry in `GLOBAL_SEARCH_EXEMPT` saying why.
- **Changing the fold** (`SearchText`) → run **`php artisan atriom:rebuild-search`**. Nothing rewrites stored
  blobs on its own, so until it runs, existing records are searchable only under the OLD rules. Same after
  changing a model's `searchTextSources()`.
- **Reordering results** → `SearchPolicy::PRIORITY`, one list, top to bottom.

## 7. Gotchas

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
- `ViolationResource::$recordTitleAttribute` is still `reference`, an accessor. That is safe **because** the
  searchable attributes are explicit — it is used for display only. Do not "simplify" it back into a search key.
