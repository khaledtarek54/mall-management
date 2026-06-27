# Utility Meters & Readings

> Track electric, water, and gas consumption at the asset and unit level; compute consumption as monthly deltas; visualize trends by meter type across a rolling 12-month window.

## 1. Purpose & business context

The Utility Meters module enables energy management for mall properties: operators log monthly meter readings (odometer snapshots), the system auto-calculates consumption as the delta from the prior reading, and a dashboard widget visualizes consumption trends per utility type. Each meter belongs to an asset (property) and optionally to a unit (shop/tenant space); null `unit_id` signals a common-area meter. Meter readings are immutable once created (soft-deletable but not edited). The module supports three utility types (electric, water, gas) and three operational states (active, inactive, faulty) for lifecycle management.

## 2. Domain model

### UtilityMeter
| Column | Type | Constraints | Meaning |
|--------|------|-------------|---------|
| id | bigint | PK | |
| asset_id | bigint | FK→assets, cascade on delete, indexed | Property owner of the meter |
| unit_id | bigint | FK→units, nullable, null on delete | Tenant/shop unit; null = common area |
| meter_number | varchar | UNIQUE | Meter identifier (e.g. "HW-E-001") |
| type | enum('electric','water','gas') | — | Utility category |
| provider | varchar | nullable | Utility company (e.g. "National Grid") |
| status | enum('active','inactive','faulty') | default 'active' | Operational state |
| unit_of_measurement | varchar(16) | nullable | Display unit (kWh, m³, etc.) |
| created_at, updated_at, deleted_at | timestamp | — | Lifecycle timestamps; soft-deletes |

**Key indexes:** `[asset_id, type]`, `[status]`

### MeterReading
| Column | Type | Constraints | Meaning |
|--------|------|-------------|---------|
| id | bigint | PK | |
| utility_meter_id | bigint | FK→utility_meters, cascade on delete, indexed | Parent meter |
| reading_date | date | — | Month-end snapshot date |
| reading_value | decimal(14,2) | — | Odometer value at date |
| consumption | decimal(14,2) | default 0 | Delta: current reading − prior reading |
| cost | decimal(14,2) | default 0, NOT NULL | EGP amount; dehydrated null→0 |
| notes | text | nullable | Operator annotations (meter reset, anomaly, etc.) |
| created_at, updated_at | timestamp | — | |

**Key constraint:** UNIQUE `[utility_meter_id, reading_date]` — enforces one reading per meter per date.

### Relationships
- **UtilityMeter → Asset** (belongsTo): Every meter belongs to exactly one property.
- **UtilityMeter → Unit** (belongsTo, nullable): Optional unit scoping; null for common areas.
- **UtilityMeter → MeterReading** (hasMany, ordered): Readings ordered by `reading_date`.
- **MeterReading → UtilityMeter** (belongsTo): Back-reference to parent meter.

### Helper methods
- `UtilityMeter::isCommonArea()` → `unit_id === null`
- `UtilityMeter::latestReading()` → most recent reading by date, or null

## 3. Business rules & invariants

### Consumption auto-fill
When an operator keys a new reading's `reading_value` via the form (live, onBlur), the system auto-fills `consumption` as the delta from the prior reading **if**:
- A prior reading exists for this meter (looking backward by `reading_date`).
- The new value is **≥** the prior value (no rollback).
- `consumption` is still empty (operator hasn't manually overridden it).

**Formula:** `consumption = current_reading_value − prior_reading_value`

If prior is missing (first-ever reading), delta can't be computed → consumption left blank for the operator.
If current < prior (meter reset/anomaly), delta is negative → auto-fill is skipped; operator must key it manually.

**Test guards:** `UtilityMeterScenarioTest` lines 79–151 cover all three paths (happy delta, manual override kept, no-prior case).

### Cost dehydration
The `cost` field in ReadingsRelationManager is optional (no `required()` validation). Filament dehydrates an omitted numeric field to `null`. The DDL default `0` only applies when no explicit value is provided — explicit `NULL` bypasses it. To prevent "NOT NULL constraint failed" errors:
- **MeterReading::booted()** hook (line 38–41) coerces null → 0 before save.
- This is a workaround; ideally the form should default to 0 or be required.

**Test guard:** `UtilityMeterScenarioTest::saves_a_reading_with_cost_left_blank()` line 209–226 verifies the coercion.

### Per-period uniqueness
The database enforces `UNIQUE [utility_meter_id, reading_date]` — only one reading per meter per calendar date. A second reading on the same date throws a `QueryException`.

**Test guard:** `UtilityMeterScenarioTest` line 234–239.

### Immutability
Readings have no edit/update UI in the relation manager — only create, view, and delete via soft-delete. If an operator needs to correct a reading, they delete it and re-enter. This prevents audit confusion and sneaky post-month corrections.

## 4. Lifecycle / state machine

### Meter statuses
- **active** (default): Operational, readings expected monthly.
- **inactive**: Out of service; no new readings logged (not enforced at DB layer).
- **faulty**: Broken or miscalibrated; readings unreliable pending repair.

No state-transition rules are enforced; status is a free-form label for operators. Only `active` meters appear in the energy consumption widget (implicitly — the widget doesn't filter by status, but the expectation is that active meters drive decisions).

### Reading lifecycle
1. **Created** via the Filament relation manager (ReadingsRelationManager::create).
2. **Visible** in the readings table, sorted descending by date (most recent first).
3. **Editable** via the same form (field-by-field) — though the reading_value→consumption auto-fill only fires on initial insert, not update.
4. **Soft-deletable** via the UI delete button; hard-delete via RestoreAction/ForceDeleteAction (EditUtilityMeter page).
5. **Terminal** when soft-deleted → does not appear in readings list; cost is 0 (default), consumption is whatever was logged.

## 5. Services, jobs & scheduled commands

**No services, jobs, or console commands yet.** The module is query-only at runtime (no background sync or automated reconciliation).

### Future extension points
- **Hourly/daily meter sync** via a third-party API (if integrating with smart meters).
- **Monthly consumption alerts** (e.g., email ops if consumption spikes by >20%).
- **Reporting job** to export consumption by meter type and unit to a CSV/PDF.

All would wrap the MeterReading model and asset/unit relationships.

## 6. Filament resources & key fields

### UtilityMeterResource
- **Model:** `App\Models\UtilityMeter`
- **Navigation:** "Energy & Utilities" (icon: Heroicon::OutlinedBolt), group "Operations", sort 8.
- **Pages:** List, Create, Edit (standard CRUD + soft-delete restore/force-delete).
- **Relation manager:** ReadingsRelationManager (embedded on EditUtilityMeter).

**Scoping:**
- **TenantOwnership:** `$tenantOwnershipRelationshipName = 'asset'` — Filament restricts list to current property.
- **BypassesScopingOnAll trait:** When tenant is the synthetic "All Properties" asset, scoping is bypassed → user sees all meters from all properties they have access to.
- **getEloquentQuery():** Eager-loads `asset` and `unit` to avoid N+1.

### UtilityMeterForm (UtilityMeterForm.php)
| Field | Type | Validation | Notes |
|-------|------|-----------|-------|
| meter_number | TextInput | required, max 50, **unique** | Unique constraint at DB; no form-level uniqueness check → collisions throw 500 |
| asset_id | Select | required, **disabled/locked to currentAssetId()** | Audit-fixed: can't create a meter against a different property by mistake |
| unit_id | Select | optional, placeholder "Common Area" | Populated by asset_id (reactive); null → common-area meter |
| type | Select | required, options from `__('admin.enums.meter_type')` | electric, water, gas |
| status | Select | required, default 'active', options from `__('admin.statuses.meter')` | active, inactive, faulty |
| provider | TextInput | optional, max 100 | Utility company name |
| unit_of_measurement | TextInput | optional, max 16, placeholder "kWh / m³" | Display unit for readings |

**Asset scoping logic:**
```php
$assetId = $get('asset_id') ?: \App\Support\TenantScope::currentAssetId();
```
If no explicit asset_id, falls back to the active tenant (property) to populate the unit dropdown. On Create, the asset_id is forced to `currentAssetId()` and disabled.

### UtilityMetersTable (UtilityMetersTable.php)
| Column | Type | Sortable | Filterable | Notes |
|--------|------|----------|-----------|-------|
| meter_number | TextColumn | ✓ | — | Mono font, searchable, copyable |
| asset.name | TextColumn | — | — | Badge, gray |
| unit.code | TextColumn | — | — | Placeholder if null (common area) |
| type | TextColumn | — | ✓ (SelectFilter) | Badge, color-coded: electric=warning, water=info, gas=danger |
| provider | TextColumn | — | — | Toggleable (hidden by default) |
| status | TextColumn | — | ✓ (SelectFilter) | Badge, color-coded: active=success, inactive=gray, faulty=danger |
| readings_count | TextColumn | — | — | Badge, dynamic count via `->counts('readings')` |

**Default sort:** `meter_number` ascending.

### ReadingsRelationManager (Embedded on meter edit page)
**Title:** Localized from `__('admin.relation_managers.meter_readings')`.

#### Form (ReadingsRelationManager::form)
| Field | Type | Validation | Live | Notes |
|-------|------|-----------|------|-------|
| reading_date | DatePicker | required, native false, default to month start | — | Operator can override; **helperText** explains date aggregates by month in widget |
| reading_value | TextInput | numeric, required, min 0, step 0.01 | **live onBlur** | Triggers consumption auto-fill delta calc; **helperText** warns "override if meter was reset" |
| consumption | TextInput | numeric, required, min 0, step 0.01 | — | Auto-filled by reading_value delta; **required** (enforces manual entry on rollback) |
| cost | TextInput | numeric, optional, min 0, step 0.01, prefix "EGP" | — | Optional in form; coerced to 0 by model if omitted |
| notes | Textarea | optional, rows 2 | — | Operator annotations |

**afterStateUpdated logic (reading_value, line 49–71):**
```php
if ($get('consumption')) { return; }  // Don't clobber manual entry
$prior = MeterReading::query()
    ->where('utility_meter_id', $meterId)
    ->where('reading_date', '<', $candidateDate)
    ->orderByDesc('reading_date')
    ->first();
if (! $prior) { return; }  // No prior reading
$delta = (float) $state - (float) $prior->reading_value;
if ($delta < 0) { return; }  // Meter reset — skip
$set('consumption', round($delta, 2));
```

**Critical:** This hook fires only on live form updates (create action in the mount path). Calling `fillForm()` or `setTableActionData()` bypasses hooks — see UtilityMeterScenarioTest comments for test workarounds.

#### Table (ReadingsRelationManager::table)
| Column | Type | Sortable | Toggleable | Notes |
|--------|------|----------|-----------|-------|
| reading_date | TextColumn | ✓ | — | Formatted 'd/m/Y', **recordTitleAttribute** |
| reading_value | TextColumn | — | — | numeric(2), right-aligned, odometer value |
| consumption | TextColumn | — | — | numeric(2), right-aligned, **bold** |
| cost | TextColumn | — | — | money('EGP'), right-aligned |
| notes | TextColumn | — | ✓ hidden | limit 40 chars, toggleable (hidden by default) |

**Default sort:** `reading_date` descending (most recent first).

**Filters:**
- Year filter (TextInput, dynamic): `whereYear('reading_date', $year)`.

**Actions:**
- **headerAction:** CreateAction labeled "Log Reading".
- **recordActions:** EditAction, DeleteAction (soft-delete).

## 7. Notifications & integrations

**None currently.**

### Future candidates
- **Meter reading reminder** (monthly, to ops email): "Log readings for all active meters before month close."
- **Consumption anomaly alert:** If consumption > prior month by >20%, notify ops.
- **Integration with smart meters:** Sync readings hourly from a telemetry provider (if connected).

## 8. Extension points — how to change/SAFELY

### Adding a new meter type (e.g., steam)
1. **In migration:** Add `steam` to the `type` enum:
   ```php
   $table->enum('type', ['electric', 'water', 'gas', 'steam']);
   ```
2. **In model (UtilityMeter.php):** Update constant:
   ```php
   public const TYPES = ['electric', 'water', 'gas', 'steam'];
   ```
3. **In lang/en/admin.php:** Add translation:
   ```php
   'meter_type' => [
       'steam' => 'Steam',
   ],
   ```
4. **In EnergyConsumptionTrend widget:** Add to palette and dataset loop (line 73–91):
   ```php
   'steam' => '#SOME_COLOR',
   ...
   foreach (['electric', 'water', 'gas', 'steam'] as $type) { ... }
   ```
5. **Test:** UtilityMeterScenarioTest should still pass; add a new scenario for steam readings.
6. **DO NOT:** Hard-code the type list elsewhere; always derive from `TYPES` constant or enum.

### Changing consumption calculation (e.g., delta over rolling average instead of prior)
1. **Edit ReadingsRelationManager::form()** (line 49–71): Replace delta logic with rolling-average logic.
2. **Ensure the hook remains live & onBlur** so operators see the new calculation in real-time.
3. **Add test to UtilityMeterScenarioTest:** Verify new math with prior, current, and future readings.
4. **Validate:** Run `php artisan test tests/Feature/Scenarios/UtilityMeterScenarioTest.php` to confirm.

### Requiring cost to be filled on every reading
1. **In ReadingsRelationManager::form()** (line 79): Add `->required()` to the cost field.
2. **Remove the model hook in MeterReading::booted()** (line 34–43) — no longer needed.
3. **Test:** Update UtilityMeterScenarioTest lines 209–226 to expect validation error if cost is omitted.

### Preventing operators from viewing/editing readings for faulty meters
1. **In ReadingsRelationManager::table()** or form, add a query scope:
   ```php
   ->relationship('meter', fn ($q) => $q->where('status', '!=', 'faulty'))
   ```
   This hides readings whose parent meter is faulty.
2. **Test:** Add scenario to UtilityMeterScenarioTest.

### Exporting consumption to a CSV report
1. **Create a new ExportMeterReadings service/action** in `app/Actions/` or `app/Services/`.
2. **Fetch readings scoped by date range, meter type, and asset** using TenantScope::applyTo().
3. **Format as CSV** (header: meter_number, type, reading_date, consumption, cost).
4. **Add a Filament action** to UtilityMeterResource (e.g., toolbar action) to trigger export.
5. **Test:** Verify CSV shape and scoping in a new Feature test.

### Adding unit-level consumption rollup (SUM per unit per month)
1. **Add a query/scope** to the Unit model:
   ```php
   public function monthlyConsumption($month) {
       return MeterReading::join('utility_meters', ...)
           ->where('units.id', $this->id)
           ->whereMonth('reading_date', $month)
           ->sum('consumption');
   }
   ```
2. **Use in a new widget or unit-detail page** to show tenant their usage.
3. **Ensure TenantScope is applied** so a tenant can only see their own unit's data.
4. **Test:** Verify scoping and aggregation.

### DO NOT break these invariants
- **Don't soft-delete a meter without orphaning its readings** — the cascade is on `UtilityMeter` only, so readings are deleted. If you want to archive a meter, set `status = 'faulty'` or `'inactive'` instead.
- **Don't allow reading_date > today** — add validation `->maxDate(today())` to ReadingsRelationManager if backdating is a risk.
- **Don't recompute consumption on read** — it's immutable once saved. If you need revision, delete and re-enter.
- **Don't scope meters to a unit without scoping the reading list** — ensure filters propagate to the relation manager.
- **Don't skip the per-period uniqueness constraint** — if merging two readings into one, handle the collision at the application layer (don't just leave duplicate dates).

## 9. Gotchas, edge cases & recently-fixed bugs

### Cost NOT NULL + form dehydration (FIXED)
**Issue:** Cost field is optional in form (no `required()`), but DDL default(0) only works for missing columns—explicit NULL (from Filament dehydration) bypasses it → "NOT NULL constraint failed" error on save.

**Fix:** MeterReading::booted() (line 38–41) coerces null → 0 before save. This is a band-aid; long-term should add `->required()` or `->default(0)` to the field itself.

**Test guard:** UtilityMeterScenarioTest::saves_a_reading_with_cost_left_blank (line 209).

### Consumption auto-fill doesn't fire in tests without Livewire context
**Issue:** The `afterStateUpdated` hook (line 49–71) is live and onBlur—it only fires through real Livewire state changes. In tests, calling `fillForm()` or `setTableActionData()` deliberately bypasses hooks to avoid double-processing.

**Workaround:** UtilityMeterScenarioTest uses `Livewire::test(...)->mountTableAction('create')->set('mountedActions.0.data.reading_value', X)` to trigger the hook. See test lines 84–88.

**Implication:** If you add custom form fields tied to reading_value, ensure they also handle the non-Livewire scenario (e.g., direct model instantiation in a service/job).

### Meter reset (rollback detection)
**Issue:** When a meter physically resets (e.g., battery replacement, maintenance), the new reading can be lower than the prior one. The auto-fill delta detects this (`delta < 0`) and skips, leaving consumption blank.

**Operator action:** They must manually key the corrected consumption (e.g., "meter was reset to 0, consumption is 150 since last reset"). Validation requires consumption → this is by design to force awareness of the anomaly.

**Test guard:** UtilityMeterScenarioTest lines 142–175 cover rollback detection and operator override.

### Common-area meter scoping
**Issue:** A meter with `unit_id = null` is a common-area meter, but it's **not** soft-scoped by unit in queries. It still belongs to the asset and appears in the meter list.

**Implication:** If you add a "per-unit consumption" dashboard, explicitly filter OUT nulls:
```php
->whereNotNull('unit_id')
```
Otherwise, common-area consumption will leak into unit totals.

### Energy widget doesn't filter by meter status
**Issue:** EnergyConsumptionTrend (line 40–98) sums **all** meter readings regardless of meter status. A faulty meter's readings still roll into the trend.

**Why:** The widget aggregates by reading date and meter type, not individual meters. Filtering by `utility_meters.status = 'active'` would require a JOIN and WHERE—currently it just joins and sums.

**Operator expectation:** Operators should **not** log readings for faulty meters; if they do, that data will pollute the trend until the meter is soft-deleted.

**Workaround:** Add a scope to the widget query if faulty readings are frequent:
```php
->where('utility_meters.status', 'active')
```

### All-Properties ("ALL") tenant bypasses per-property meter scoping
**Issue:** When the active tenant is the synthetic ALL pseudo-asset, `BypassesScopingOnAll` trait (line 20–29) returns the query unchanged, so the user sees **all** meters from **every** property they have access to.

**Why:** This is intentional—super_admin and portfolio-level roles need to see cross-property trends.

**Implication:** The energy widget correctly applies `TenantScope::applyTo()` (line 59–60), which respects ALL mode and returns null, so the query has no asset filter. A restricted user viewing ALL still gets scoped by `visibleAssetIds()`.

### Unique meter_number lacks form-level duplicate check
**Issue:** The database enforces `UNIQUE meter_number`, but Filament has no form-level async uniqueness validator. If two operators create "HW-E-001" simultaneously, the second one gets a 500 error (integrity constraint violation).

**Workaround (optional):** Add a custom TextInput rule in UtilityMeterForm:
```php
->unique(table: 'utility_meters', column: 'meter_number', ignoreRecord: true)
```
This would provide a friendlier validation message before the database complains.

## 10. Tests & related modules

### Test files
- **UtilityMeterScenarioTest** (`tests/Feature/Scenarios/UtilityMeterScenarioTest.php`): ~440 lines, 15 test cases.
  - Consumption math (delta, manual override, three-reading chain, first reading, rollback).
  - Per-period uniqueness (same date collision, same date on different meter).
  - Consumption rollup into EnergyConsumptionTrend widget (scoped by property, excludes "All Properties" isolation).
  - Asset/unit scoping (per-property list, All-Properties bypass).
  - Asset picker audit-fix (locked to currentAssetId on create).
  - RBAC matrix (operations can view/create but not delete; leasing/accounting blocked; super_admin can delete).

- **MeterReadingsRelationManagerTest** (`tests/Feature/Resources/MeterReadingsRelationManagerTest.php`): 50 lines, 2 test cases.
  - Relation manager renders without error.
  - Saving a reading stores it against parent meter.

### Related modules
- **Assets** (`app/Models/Asset.php`): Property owner of meters. The "All Properties" asset (code='ALL') is special—it bypasses scoping. TenantScope::currentAssetId() checks this.
- **Units** (`app/Models/Unit.php`): Optional owner of meters (unit_id). Null → common area.
- **EnergyConsumptionTrend widget** (`app/Filament/Admin/Widgets/EnergyConsumptionTrend.php`): Visualizes 12-month consumption trends, summed by month & meter type. Scoped by currentAssetId(); respects All-Properties mode.
- **TenantScope** (`app/Support/TenantScope.php`): Central scoping utility. Returns currentAssetId() (active property or null) and visibleAssetIds() (list of properties the user can see). Used by the widget and UtilityMeterResource.
- **RoleGatedActions** (`app/Filament/Admin/Resources/Concerns/RoleGatedActions.php`): Trait that gates CRUD on permissions (utility_meters.view, .create, .edit, .delete). Delete is super_admin only project-wide.
- **BypassesScopingOnAll** (`app/Filament/Admin/Resources/Concerns/BypassesScopingOnAll.php`): Trait for direct-FK resources (UtilityMeter, Unit, CamExpensePool). Bypasses Filament's scoping when the active tenant is the ALL pseudo-asset.
- **RolesPermissionsSeeder** (`database/seeders/RolesPermissionsSeeder.php`): Defines permissions (utility_meters.view/.create/.edit/.delete) and assigns to roles (manager/operations get view/create/edit; super_admin can delete).

### Extension & testing guidelines
- **New features:** Add test to UtilityMeterScenarioTest; follow existing test setup (seed asset/unit, create meter, act as user, asTenant() wrapper).
- **Model changes:** Update the migration, model constants (TYPES, STATUSES), and test for side effects (e.g., consumption calculation, widget aggregation).
- **Filament form changes:** Update ReadingsRelationManager or UtilityMeterForm; test via Livewire::test() to ensure live hooks and validation work.
- **Permission changes:** Update RolesPermissionsSeeder and AuthorizationMatrixTest to reflect new matrix.
- **Widget changes:** Test via `asTenant()` to verify scoping and aggregation logic.

---

**Last updated:** 2026-06-27
**Module status:** Mature (1043 passing tests, production-ready)
**RBAC:** Gated by utility_meters.{view,create,edit,delete}; delete reserved for super_admin.
