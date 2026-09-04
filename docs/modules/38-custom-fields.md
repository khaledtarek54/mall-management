# 38 · Custom fields (user-defined fields)

How an operator records **a fact this system never modelled** — without a deploy.

Cross-cutting rather than a domain module: it touches five record types in the admin panel, so it has its
own registry and its own gates, in the same shape as `PropertyIsolation`, `DeletionPolicy` and `SearchPolicy`.

> **The gap it closes.** D-7 in [EGYPT-MARKET-FIT](../EGYPT-MARKET-FIT.md) called this *"the single biggest
> structural gap vs Yardi UDFs / MRI user-defined fields / Odoo Studio"*, and it was right: zero hits for
> `custom_field` anywhere in the codebase. Every operator eventually needs to record the tenant's parent
> buying group, the lease's broker, the shop's landlord-works reference, whether a supplier is on a
> government approved list. With nowhere to put it the fact goes in the notes box — where nothing can filter,
> report or export it — or it costs a deploy, and they pay for one every time.

## 1. The storage was already here, and read by nothing

`tenants`, `leases`, `assets`, `vendors` and `departments` have carried a nullable `metadata` JSON column
**since the first migrations**. All five are `fillable`, all five are cast to `array`, and not one of them
was written or read by any form, table, service, report or export. The audit that raised D-7 counted them as
evidence of the gap. They are also its answer.

A value lives on the record it describes: no value table, no join, no N+1, and an export is a column read
rather than a second query per row.

**`units` gained a column here** — the only host-table change. The shop is the record a mall accumulates the
most physical facts about and was the one master record with nowhere to put them. (`units.features` was a
different thing, a fixed amenity list, dropped as unused by `2026_08_10_170000`.)

## 2. What may be extended is a DECISION, not a query

`App\Support\CustomFields::EXTENSIBLE` names five record types, each with the reason it earns one:
**tenant · lease · unit · vendor · asset**.

It would be possible to derive the list by asking the schema which tables have a `metadata` column. That is
exactly the wrong shape: the day someone adds such a column for an unrelated purpose, that model would
silently start accepting operator-defined keys through its form.

**Money documents are deliberately absent.** An invoice, a payment, a journal entry is *evidence* — an
operator-defined field on one is a place to record something onto a document nobody can reconstruct later,
which is the same reasoning that makes them undeletable. `departments` is absent too despite having the
column: an internal org unit is not what an operator extends, and if that proves wrong it is one line in the
register rather than a migration.

## 3. Only known keys are ever written

`metadata` is a JSON column that accepts anything without complaint, and it *used* to be `fillable` on all
five models. It is not any more.

- `HasCustomFields::fillCustomFields()` writes **only keys the catalogue currently defines** for that model,
  and MERGES into what the column already holds.
- The form binds to `custom_fields.{key}`, a plain form key with **no model attribute behind it** — never
  `metadata.{key}`. Binding the column directly would look tidier and would let a crafted Livewire payload
  put arbitrary keys into it.
- `metadata` was removed from `$fillable` on all five models. The concern assigns the attribute directly,
  which `$fillable` does not govern, so nothing legitimate broke and the mass-assignment surface is gone.

The merge is not only a security property. A form carries only the ACTIVE fields, so replacing rather than
merging would wipe an answer given under a field since retired — quiet data loss on an unrelated save.

## 4. The key is the address; the label is not

`custom_fields.model` and `custom_fields.key` are **immutable once the row exists** (`CustomField::saving()`
refuses the change; the form disables both). Together they say which table's `metadata` holds every answer
and under which JSON key — renaming either strands the data: it stays on the records and nothing reads it
again.

The **label** is what an operator renames, in both languages, and it reaches every record at once because it
is resolved at READ time. Same rule the activity log runs on: *the row stores DATA, the words come later.*

## 5. Retiring is not deleting

| | offered on the form | shown on the record | answers |
|---|---|---|---|
| active | yes | yes | kept |
| `is_active = false` | **no** | **yes** | kept |
| definition deleted | no | yes, labelled by its raw key | **stranded** |

`is_active` stops a field being *asked*; it never blanks an answer, and the display keeps showing it — a
field retired half way through a year still explains what is on the records that carry it, exactly as a
retired charge code still labels the invoice lines it raised.

Deleting is **refused once anyone has answered** (`CustomField::deletionBlockers()` counts records carrying
the key, soft-deleted ones included). A field nobody filled in is a mistake worth clearing.

That count is an override rather than a `blockedBy` relation, because there is no relation to declare — an
answer is a key inside the host record's own JSON. The `DeletionPolicy` gate verifies every relation named
actually exists, and naming one that does not is the exact silent failure that check was written for.

## 6. Where it appears

| Surface | Built by |
|---|---|
| Create/Edit form for all five record types | `CustomFieldsSchema::form($alias)` spread into each `*Form` schema |
| Tenant record page | `CustomFieldsSchema::infolist()` spread into `TenantInfolist` |
| Edit form **loading** existing answers | `FillsCustomFields` on the five Edit pages |
| Managing the definitions | `/admin/custom-fields`, gated on `custom_fields.*` |
| List column, filter, sort on the five lists | `CustomFieldsTable::columns()` / `::filters()` |
| CSV/XLSX export (tenants, leases, units) | `CustomFieldsTable::exportColumns()` |
| CSV import (tenants, leases, units, vendors) | `CustomFieldsTable::importColumns()` |
| Global search (the top bar) | `HasCustomFields::customFieldSearchValues()` in each model's `searchTextSources()` |

The section **hides itself when nothing is defined**, so a fresh install is unchanged and no form grows an
empty "Additional information" heading. `hidden()` is evaluated per render, so it appears the moment the
first definition is saved.

The other four record types have no View page — their Edit form *is* the record page, and the section is
already on it.

### Why `FillsCustomFields` exists

`HasCustomFields` exposes the answers as a virtual `custom_fields` attribute, and that is enough to WRITE
them. Reading is **not symmetric**: Filament fills an Edit form from `$record->attributesToArray()`, which
contains real columns and `$appends` only — a virtual accessor is invisible to it. Without the hook the
section opened **empty on every edit**, and the next save would have looked exactly like the operator
clearing every answer.

Appending it instead would have worked and been wrong: `$appends` reaches `toArray()`, and
`docs/api/openapi.json` is GENERATED from the API resources' `toArray()`. A display concern would have
quietly rewritten the mobile contract.

**Caught by driving the real Create and Edit pages in a test.** Building a schema and asserting its shape
passes whether or not this exists — the same trap that shipped two live 500s in August 2026.

## 6b. Filtering, sorting and export (slice 3)

A value you can type and never group by is the notes box with extra steps. An operator who records a
parent buying group on two hundred tenants wants a **list by parent group** and a **spreadsheet of it** —
that is usually the reason they asked for the field.

**The value is read two different ways, on purpose.** Display goes through the model
(`custom_fields.{key}` resolves against the virtual accessor, so a column shows exactly what the record page
shows). Query goes through SQL (`metadata->{key}`), because filtering and sorting must happen in the
database — a collection filter pages wrongly and a collection sort only orders the rows already fetched.

Laravel compiles the JSON path per driver, and the two differ: SQLite gives
`json_extract("metadata", '$."key"')`, MySQL gives `json_unquote(json_extract(\`metadata\`, '$."key"'))`.
Both were executed against their real driver, not just compiled.

| Type | Filter |
|---|---|
| text · textarea | CONTAINS — an operator looking for "Americana" should not have to remember whether they typed "Americana Group" |
| select | exact match on the STORED value |
| boolean | ternary |
| date | from / until, compared as text (a `Y-m-d` string sorts and compares correctly, and casting per row cannot be pushed into the JSON path) |
| number | min / max |

**A record that never answered is EXCLUDED, not treated as empty.** `NULL` at the JSON path fails every
comparison, which is the right default: *"no parent group recorded"* is not *"parent group is empty"*.

**Columns ship hidden** (`toggleable(isToggledHiddenByDefault: true)`). An operator who defines eight fields
must not find eight new columns on a list they were happy with; they turn on the ones they want, and
EG-32 slice 1 lets them save that as a view and hand it to a colleague.

**Filters are named `cf_{key}`.** A filter name is a query-string key sharing a namespace with every other
filter on the table, so an operator field called `status` would otherwise collide with the resource's own
status filter and silently take it over.

**Export columns come LAST**, and a `select` exports its stored VALUE rather than its label. An export is
read by another system: the value is the stable half of the pair, and appending rather than inserting means
the shipped column positions a colleague's import template depends on never move.

> **The trap this slice hit.** Filament resolves a closure's arguments by **parameter name**. A filter
> written `fn (Builder $q, array $data)` registers, renders and **filters nothing** — the list ignores it
> and everything looks correct. Caught only by driving the real list and counting rows; asserting that the
> filter exists passes either way.

**Vendors and properties have no exporter at all**, so their custom fields are not exportable — that is a
pre-existing gap in those two resources, not something this slice introduced.

## 6c. Import and global search

**Import** completes the round trip: `CustomFieldsTable::importColumns()` adds an optional column
per active field to the tenant, lease, unit and vendor importers, so a migrating operator brings
their own columns in with the records instead of keying them in afterwards. Columns come LAST, so an
existing mapping template is untouched, and a sheet naming none imports exactly as it always did.

Filled through **`fillRecordUsing()`**, never by attribute name: Filament's default fill does
`data_set($record, $name, $state)`, which for a dotted name builds a nested array on the model and
for a bare one sets an attribute that is not a column. Routing through `fillCustomFields()` means an
import gets the same key filtering and type casting a form does — **a CSV cannot introduce a key the
catalogue never defined.** A choice imports by its stored value, matching what the export writes, so
a sheet exported from here re-imports cleanly.

**Global search**: each model spreads `customFieldSearchValues()` into its `searchTextSources()`.
That honours the blob's one hard rule — *never reach through a relation* — because `metadata` is the
row's OWN attribute, so it re-folds whenever the record saves and no other record's edit can strand
it.

Two deliberate limits. **Stored values only, never a choice's label**: a label lives on the
definition, not this row, so indexing it would make a rename silently stale until the next rebuild —
the exact failure the no-relations rule exists to prevent. And **booleans are skipped**, because "1"
is not a search term and would match every record carrying any number.

**The answers are folded in KEY ORDER, and that `ksort` is load-bearing (2026-08-23).** `metadata` is
a native `json` column on MySQL and **MySQL does not preserve object key order** — it normalises,
shortest key first — so the sequence PHP wrote was not the sequence the row read back, and every run
of `atriom:rebuild-search` rewrote every blob with the same answers in a different order. That breaks
the blob's documented promise to be a pure function of the row, and it hides a real change inside a
full-table rewrite. **No Pest test can see it**: on SQLite `metadata` is TEXT and keeps insertion
order, so both paths agree there regardless. It was caught by the MySQL QA harness, and the
regression pins **order independence** rather than a golden string — a golden string would pass on
SQLite while the bug ran on MySQL. See [34 · Search](34-search.md).

## 7. Registrations (what a sixth record type would need)

| Registry | Entry |
|---|---|
| `CustomFields::EXTENSIBLE` | the morph alias + the reason it earns one |
| `MorphMap` | `custom_field` (the model is activity-logged, so it is a `subject_type`) |
| `ValueSets` | `custom_fields.type` → `CustomField::TYPES` |
| `SearchPolicy::GLOBAL_SEARCH_EXEMPT` | definitions are configuration; searching *"parent group"* should find the TENANT |
| `ScreenGuides` | `custom_fields` (+ `lang/{en,ar}/guides.php`) |
| `RolesPermissionsSeeder` | `custom_fields.{view,create,edit,delete}` — **run the seeder, it is a deploy step** |

`custom_fields.model` is deliberately **not** in `ValueSets::UNCLASSIFIED`: the coverage gate only sweeps
columns whose name ends in a classification suffix, so an exemption there would be stale by definition.
`CustomField::saving()` refusing a non-extensible alias is the real guard, and a stronger one.

## 8. Gotchas

- **A field key must be `[a-z][a-z0-9_]*`.** Filament reads a dot as nesting, so `parent.group` would
  silently become a two-level array in the form state and the answer would never reach `metadata` under
  that key — a field that records nothing, quietly. Enforced in `CustomField::saving()` as well as on the
  form, per the codebase's "guard in the model, the form is the UI half" doctrine: an import or a crafted
  payload meets the model, not the form.
- **A boolean is never required.** A required tick is a consent box, not a data field, so
  `CustomFieldsSchema` ignores `is_required` for `boolean` and the definition form hides the toggle.
- **A cleared answer is REMOVED, not stored as null** — otherwise `metadata` accumulates a null for every
  field anybody ever left blank and "recorded as empty" becomes indistinguishable from "never answered".
- **Values are cast on write** (`number` → int/float, `boolean` → bool). A form posts strings; storing `"12"`
  means every later reader has to remember to cast, and the one that forgets compares a string.
- **The catalogue is memoised per REQUEST**, flushed by the model's saved/deleted hooks. Never in a static: a
  `queue:work` daemon outlives the request and would hand a month-old catalogue to every job it ran.

## 9. What is NOT built (and is the rest of EG-32)

Deliberately out of this slice, each stated rather than implied:

- Nothing. **Vendors and properties gained an exporter on 2026-08-23**, which was the last gap: they
  were the two registers with no way OUT of the system, so a custom field on a vendor could be
  defined, filled, filtered and imported and could not be taken away.
- **A choice field is searched by its stored VALUE, not its label** — see §6c. Deliberate, and the
  filter is the right control for a choice.

> **Deploy step.** Adding custom fields to the search blob rewrites nothing on its own. Run
> `php artisan atriom:rebuild-search` so existing records become findable by their answers.
- **A report builder** for the 23 catalogued report pages, whose columns are PHP literals — the other half of
  S-5, and the larger one.

## 10. Tests & related

`tests/Feature/Regression/ACustomFieldIsARowTheOperatorDefinesTest.php` — 26 cases, including the real
Create/Edit/View pages and the crafted-payload case.

Related: [18 · RBAC & scoping](18-rbac-scoping.md) (activity log vocabulary) · [34 · Search](34-search.md)
(why definitions are exempt) · [02 · Tenants](02-tenants.md) (the record type most often extended).

---

## Sweep fixes — 2026-09-04

*Designed by the patch fleet, adversarially reviewed, then applied and tested one at a
time. Each row's full claim and evidence is in [docs/qa/DEEP-SWEEP-2026-09-01.md](../qa/DEEP-SWEEP-2026-09-01.md).*


### SW-118

append to `## 8. Gotchas`:

**A duplicate key was a 500, and the switched-off case could not be explained (SW-118, fixed 2026-09-04).** The table has carried `unique(['model', 'key'])` since it was created — the key is the ADDRESS of every value already recorded under it — and, measured at HEAD 2026-09-04, nothing above the database asked: `CustomFieldForm`'s key field carried `required`, `maxLength(64)` and the shape regex and **no uniqueness rule**, and `CustomField::booted()` checked the key's SHAPE and its immutability and never whether it was taken. So adding a second `parent_group` to Tenants — the example this doc opens with — came back as a raw duplicate-key `QueryException`, i.e. the 500 page, on an ordinary create. The precedent was two files away and unfollowed: `TenantRequestSubcategoryForm` and `DocumentTemplateForm` both guard their own composite unique index at the form, the latter under the comment *"refused here so the operator gets a field error instead of a duplicate-key 500"*. Two layers now, ONE wording: `CustomField::keyConflictRefusal()` returns the refusal KEY plus its replacements, so the form's field rule (`$fail(__(...))` — inline, keeping everything else the operator typed, where a `DomainException` redirects back and loses the form) and the model's `saving` guard (the gate an import, the console or a crafted payload meets — `model` is disabled on the form and still **dehydrated**, so a payload can MOVE a definition onto a taken pair) cannot word it differently. **The refusal branches on the existing field's state, because the ESCAPE does**: while it is live the answer is "give this one a different key"; once it has been switched off the answer is "turn that one back on", since every answer already recorded sits under that key and a duplicate could never read them. The check is `isDirty(['model', 'key'])`, so a rename, a reorder or a deactivation costs no query, and the index stays the backstop for the race neither guard can close. (`ACustomFieldKeyIsTakenOrItIsFreeTest`, three teeth mutation-proved.)


### SW-123

§4 already claims this; append to that section rather than restating it:

That sentence was true of `key` and not of `model` until 2026-09-04 (SW-123). The form disabled both — and both are `->dehydrated()`, so the value still arrives in the Livewire payload, which is why the model is the gate and the form is the UI half. The compounding failure is what makes it worth naming: `deletionBlockers()` counts records of the model the row NOW points at, so re-pointing `tenant` → `lease` both stranded every tenant answer AND emptied the blocker list, turning a recoverable mistake into a deletable definition and permanent orphaning — the one act `#[DeletableWhenUnused]` is on this model to prevent. `CustomField::saving()` refuses `isDirty('model')` on an existing row, and the refusal names the escape: define the field on the other record type and switch this one off, which keeps both sets of answers labelled.

