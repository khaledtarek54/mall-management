# Bilingual Activity Log

> **SHIPPED 2026-08-12.** Kept as the reasoning behind the design. The living reference — including
> the checklist for adding a model or a hand-written `activity()` call — is
> [modules/18 → "The audit trail is bilingual" + Extension points](../modules/18-rbac-scoping.md).
>
> **What the plan did not anticipate, all found by rendering the 1,822 real rows rather than
> trusting the registries:** 24 field vocabularies were missing (not the handful visible on
> screen); foreign keys printing bare ids were the biggest legibility problem and were pulled back
> INTO scope; the RTL cell needed `<bdi>` isolation and a layout gap, because a plain space between
> an Arabic label and a Latin value is absorbed when bidi reorders the run; `Lang::has($key, 'ar')`
> falls back to English, so the first version of the gate could not detect a missing Arabic key;
> and the predecessor gate had been green for a year while sweeping zero models.

## Context

The Activity Log page reads as Arabic **until you reach the column that matters**. Headers, the
"What" badge (`admin.activity.subjects`, 74 keys at EN/AR parity) and the period filters are all
translated. The *Changes* column — the only column that says what actually happened — is
English-only, because [ActivityLogChangeRenderer.php:92-101](app/Support/ActivityLogChangeRenderer.php#L92-L101)
manufactures its field labels with `str_replace('_', ' ')` + `ucfirst` instead of looking them up.
An Arabic operator auditing a lease reads «الإيجار» in the header and `Base rent  45000 → 52000`
in the cell.

Three raw-key leaks were also confirmed, and they affect **both** locales, not just Arabic:

| Written by | Renders today |
|---|---|
| `activity('settings')` — [Settings.php:139](app/Filament/Admin/Pages/Settings.php#L139), [PropertyOverrides.php:194](app/Filament/Admin/Pages/PropertyOverrides.php#L194) | `admin.activity.subjects.settings` in the What badge |
| `->event('voided')` — VoidInvoiceService, VoidPaymentService, VoidVendorBillPaymentService | `admin.activity.events.voided` in the Event badge |
| `->event('reversed')` — RecordAdvanceRepaymentService, SettleCustodyService | `admin.activity.events.reversed` |

And the settings audit trail is **invisible**: the renderer only reads `properties.attributes`, so
`withProperties(['changes' => …])` and the voids' `['reason' => …]` render as `—`. The one place a
late-fee percent can change leaving history shows a dash.

Outcome: every word an operator reads in the audit trail resolves from the lang catalogues at read
time, in either locale, with a conformance gate that fails the build when a new module ships an
untranslated one.

## The rule this establishes

> **The activity log stores data, never prose.** A row records `log_name`, `event`, field keys and
> raw values. Every human-readable word is resolved at *read* time.

Two consequences that justify the shape below: the same historical row reads correctly in both
languages, and fixing a translation retroactively fixes all of history. Five services currently
violate this by storing English sentences (`->log('Invoice voided')`) — no read-time translation
can ever repair those, so they are converted to keys.

## Design

### 1. `App\Support\ActivityVocabulary` — one resolver, four questions

New class. Every word in the audit trail comes from here; nothing else does a `__()` on activity data.

```php
subject(?string $logName): string        // admin.activity.subjects.* → subjects.default
event(?string $event): string            // admin.activity.events.*   → humanised
description(?string $key): string        // admin.activity.descriptions.* → raw string (history)
field(?string $logName, string $f): string
value(?string $logName, ?string $subjectType, string $f, mixed $v): string
```

**`field()` — layered lookup, reuse before invention:**

1. `admin.activity.fields.{log_name}.{field}` — per-model override, only where a field means
   something different on one model. Expected to stay near-empty.
2. **`admin.fields.{field}`** — the existing 323-key catalogue the forms already label from,
   already at EN/AR parity. 71 of the 213 logged fields hit this today. Reusing it means the audit
   trail and the form use the *same word for the same field*, which is a correctness property, not
   just DRY.
3. Humanised fallback — kept deliberately, because history contains columns that no longer exist.
   The gate (§4) ensures no *currently logged* field ever reaches it.

**`value()` — cast-driven, so it self-maintains.** Rather than a hand-kept list of "which fields are
money", ask the subject model for its own `$casts` (`(new $class)->getCasts()[$field]`). A new
module gets correct formatting for free, and a column that changes type can't drift:

| Source | Rendering |
|---|---|
| explicit registry / `status` convention | `admin.statuses.{log_name}.{value}` or `admin.enums.{group}.{value}` |
| `boolean` cast | `admin.activity.bool_true` / `bool_false` |
| `decimal:N`, `float`, `integer` | `number_format` — **Latin digits**, per [LatinNumeralsTest.php](tests/Feature/LatinNumeralsTest.php) |
| `date` / `datetime` | `d/m/Y` / `d/m/Y H:i`, matching the table's existing format |
| `array` / `json` | compact JSON |
| null / `''` | `admin.activity.empty_value` |
| class missing (history) | sniff the raw value's PHP type |

One small registry, `VALUE_VOCABULARY`, holds only the **exceptions** — `'{log_name}.{field}' =>
'admin.enums.{group}'`, e.g. `facility_work_order.priority => admin.enums.maintenance_priority`.
The convention `field === 'status'` → `admin.statuses.{log_name}` covers the common case with no
entry, because `useLogName()` values and `admin.statuses` keys already agree (invoice, lease,
payment, tenant, credit_note, vendor_bill, payroll…). Foreign keys stay as raw ids — resolving them
would need a batched per-page resolver, deliberately out of scope.

### 2. `ActivityLogChangeRenderer` becomes markup-only

It keeps the HTML shape and the `e()` escaping, and delegates every word to the vocabulary. Both
consumers — [ActivityLog.php:130-134](app/Filament/Admin/Pages/ActivityLog.php#L130-L134) and
[ActivitiesRelationManager.php:48-52](app/Filament/Admin/RelationManagers/ActivitiesRelationManager.php#L48-L52) —
inherit the change with no edit. Two additions:

- **A normalizer** that flattens all three payload shapes into one `(field, old, new, hasOld)` list:
  spatie's `attributes`/`old`; the settings pages' `changes[key] = ['from' =>, 'to' =>]`; and scalar
  extras (`reason`, `asset_id`). Settings keys like `late_fee_percent` already resolve through
  `admin.fields.*`, so they render through the identical formatter. This is what makes the settings
  audit visible.
- **The arrow flips in RTL.** `→` (U+2192) is not a bidi-mirrored character, so it keeps pointing
  left-to-right inside an Arabic sentence and reads as "new became old". Select `←` under `ar`.

Render order in the cell: attribute diff → context lines (`reason`) → translated description, the
last shown only when the first two are empty, so it never duplicates the Event badge.

### 3. Retire the stored English prose

Five `->log()` call sites store sentences. Convert to stable keys, translated via
`ActivityVocabulary::description()` with a **fallback to the raw string** so rows already written in
demo/dev DBs still read:

| File | now | becomes |
|---|---|---|
| `app/Services/VoidInvoiceService.php:75` | `'Invoice voided'` | `'invoice.voided'` |
| `app/Services/VoidPaymentService.php:68` | `'Payment voided / refunded'` | `'payment.voided'` |
| `app/Services/VoidVendorBillPaymentService.php:71` | `'Vendor payment voided'` | `'vendor_bill_payment.voided'` |
| `app/Services/RecordAdvanceRepaymentService.php:97` | `'reversed'` | `'employee_advance_repayment.reversed'` |
| `app/Services/SettleCustodyService.php:112` | `'reversed'` | `'custody_transaction.reversed'` |

`Settings.php` / `PropertyOverrides.php` already use key-shaped descriptions (`settings.updated`,
`property_settings.updated`) — they just need the matching lang entries.

### 4. `ActivityLogVocabularyConformanceTest` — the gate

New file in `tests/Feature/Scenarios/`, in the project's registry-gate idiom. **This is the
scalability answer**: without it, the next module silently ships an English-only audit trail, which
is exactly how the `settings` / `voided` / `reversed` leaks got in.

- **A.** Every `useLogName()` value **and every `activity('…')` literal** has an
  `admin.activity.subjects.*` key in EN and AR. The existing
  [ActivityLogSubjectsAndAssetFormTest](tests/Feature/Regression/ActivityLogSubjectsAndAssetFormTest.php)
  covers only the first half — which is precisely why `settings` slipped through. Extend that test
  rather than duplicating its model sweep.
- **B.** Every `->event('…')` literal, plus created/updated/deleted, resolves in both locales.
- **C.** Every field name in every `logOnly([...])` resolves via `field()` **without reaching the
  humanised fallback**, in both locales. Fails today on ~142 of 213 fields.
- **D.** Render a fixture activity under `ar` and assert no string matching the key pattern survives
  and no label is pure ASCII — the "render it, don't grep it" idiom from
  [TranslationKeyConformanceTest](tests/Feature/Scenarios/TranslationKeyConformanceTest.php)'s test D.

**Trap:** [ActivityLogRenderTest.php](tests/Feature/ActivityLogRenderTest.php) declares a
**file-scope** `renderChanges()` helper. A second declaration of that name in the new file is a
fatal redeclaration that `--parallel` hides and that exits 255 with no output. Reuse it via
`Tests\Support`, or name the new one differently.

### 5. Translations

Add to **both** `lang/en/admin.php` and `lang/ar/admin.php`:

- `activity.subjects.settings`
- `activity.events.voided`, `activity.events.reversed`
- `activity.descriptions.*` — the 7 keys from §3
- **~142 new `fields.*` entries** — the logged columns with no key today (`accrued_amount`,
  `earliest_notice_date`, `fault_party`, `withholding_tax_code`, …). These land in the shared
  `fields` catalogue, so forms that later label those columns get them for free.

## Files

**New:** `app/Support/ActivityVocabulary.php` · `tests/Feature/Scenarios/ActivityLogVocabularyConformanceTest.php`

**Modified:** `app/Support/ActivityLogChangeRenderer.php` (delegate + normalize + RTL arrow) ·
`lang/{en,ar}/admin.php` · the 5 services in §3 ·
`tests/Feature/Regression/ActivityLogSubjectsAndAssetFormTest.php` (extend to `activity('…')` literals) ·
`tests/Feature/ActivityLogRenderTest.php` (its assertions `toContain('Status')` / `toContain('draft')`
break by design once values translate — update to assert the resolved label in each locale).

**Docs — same commit, per CLAUDE.md:** `docs/modules/18-rbac-scoping.md` (or wherever the activity
log is documented — locate first) gains the store-data-never-prose rule and the gate; note the new
registry in `docs/OVERVIEW.md` if it lists support registries.

No migration: this is entirely read-time. Existing rows gain translation retroactively — which is
the point of the rule.

## Verification

1. `vendor/bin/pest --parallel tests/Feature/Scenarios/ActivityLogVocabularyConformanceTest.php` —
   must fail on the pre-change catalogue (run it before adding the lang keys to prove the gate has
   teeth), then pass.
2. `vendor/bin/pest --parallel tests/Feature/ActivityLogRenderTest.php tests/Feature/ActivityLogFiltersTest.php tests/Feature/Users/UserActivityLogTest.php tests/Feature/AccessControlAuditTest.php tests/Feature/Regression/ActivityLogRecordsChangesTest.php tests/Feature/Regression/ActivityLogSubjectsAndAssetFormTest.php tests/Feature/TranslationCoverageTest.php tests/Feature/Scenarios/TranslationKeyConformanceTest.php tests/Feature/LatinNumeralsTest.php`
3. `vendor/bin/pest --parallel tests/Feature/Scenarios/SettingsPageConformanceTest.php tests/Feature/Services/` — the 5 void/reverse services changed their `->log()` argument.
4. **Prove the gate, don't trust it** (per project convention): delete one `admin.fields.*` key and
   confirm test C goes red; restore.
5. Manually, on `mall-management.test/admin`: void an invoice, save a Settings change, then read the
   Activity Log in **both** locales. Confirm — no `admin.activity.*` literal in any badge; the
   settings row shows its from→to figures instead of `—`; the arrow points right in EN and left in AR.
6. Targeted runs only — no full suite unless you ask for it.
