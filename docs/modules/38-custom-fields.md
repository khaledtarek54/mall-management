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
  silently become a two-level array in the form state.
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

- **Filtering and sorting by a custom field.** Both MySQL and SQLite can query JSON, so this is reachable —
  it is a table/filter surface, not a storage question.
- **Export.** A custom field does not appear in a resource's CSV export; each of the seven exporters names
  its columns.
- **Import.** `UnitImporter`/`LeaseImporter` and friends do not map custom fields.
- **A report builder** for the 23 catalogued report pages, whose columns are PHP literals — the other half of
  S-5, and the larger one.

## 10. Tests & related

`tests/Feature/Regression/ACustomFieldIsARowTheOperatorDefinesTest.php` — 16 cases, including the real
Create/Edit/View pages and the crafted-payload case.

Related: [18 · RBAC & scoping](18-rbac-scoping.md) (activity log vocabulary) · [34 · Search](34-search.md)
(why definitions are exempt) · [02 · Tenants](02-tenants.md) (the record type most often extended).
