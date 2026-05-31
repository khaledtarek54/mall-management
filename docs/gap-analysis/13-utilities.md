# Module 13 — Utility Meters & Energy

> Date: 2026-05-31
> Status: 🟡 Yellow — meter registry is clean; meter-reading data-entry surface is missing.
> Surface: [UtilityMeter model](../../app/Models/UtilityMeter.php), [MeterReading model](../../app/Models/MeterReading.php), [Admin UtilityMeters resource](../../app/Filament/Admin/Resources/UtilityMeters/), [EnergyConsumptionTrend widget](../../app/Filament/Admin/Widgets/EnergyConsumptionTrend.php).

## 1. Inventory

### 1.1 Models

**[UtilityMeter.php](../../app/Models/UtilityMeter.php) (53 LOC)**. Traits `HasFactory, SoftDeletes`. Fillable: `asset_id, unit_id, meter_number, type, provider, status, unit_of_measurement`. Type enum 3 values (`electric, water, gas`). Status enum 3 values (`active, inactive, faulty`). Relations: `asset()`, `unit()` (nullable for common-area), `readings()` HasMany ordered by `reading_date`, `latestReading()`. Helper: `isCommonArea()` ⇔ `unit_id === null`.

**[MeterReading.php](../../app/Models/MeterReading.php) (33 LOC)**. Traits `HasFactory` only — no SoftDeletes, no LogsActivity. Fillable: `utility_meter_id, reading_date, reading_value, consumption, cost, notes`. Decimal:2 on monetary cols; date on `reading_date`. **`consumption` is STORED, not computed** — operator enters the delta manually.

### 1.2 Migrations

| File | Effect |
|---|---|
| [2026_05_23_174733_create_utility_meters_table.php](../../database/migrations/2026_05_23_174733_create_utility_meters_table.php) | 8 cols + softDeletes. FKs `asset_id` cascade, `unit_id` nullOnDelete. Indexes `(asset_id, type)`, `status`. |
| [2026_05_23_174734_create_meter_readings_table.php](../../database/migrations/2026_05_23_174734_create_meter_readings_table.php) | 6 cols + timestamps. FK `utility_meter_id` cascade. **Unique `(utility_meter_id, reading_date)`** — one reading per meter per month. Index `reading_date`. |

### 1.3 Admin Resource — `app/Filament/Admin/Resources/UtilityMeters/`

| File | Notes |
|---|---|
| UtilityMeterResource | `BypassesScopingOnAll`, `RoleGatedActions`. Nav icon `OutlinedBolt`, sort 8, group Operations. Module-gated `utility_meters`. **No `getNavigationBadge()` — no F-17 carryover.** |
| Form | 7 fields (`meter_number` unique, `asset_id` required, `unit_id` nullable cascading, `type`, `status`, `provider`, `unit_of_measurement`). Localized enum labels. |
| Table | 7 columns incl. `readings_count` badge (via `->counts('readings')`), type/status colour-coded. Filters: type, status. Standard CRUD actions + bulk delete. |

### 1.4 **No MeterReadings admin surface** — see F-52

No `MeterReadingResource` exists. No `MeterReadingsRelationManager` on `UtilityMeterResource`. Operators have no UI to record monthly readings; the seeded 576 readings came from `HayaWalkSeeder::seedUtilityMeters()`. In production, operators would need to drop into `php artisan tinker` or write raw SQL.

### 1.5 EnergyConsumptionTrend widget (already inventoried at Module 01)

Driver-aware month grouping (`strftime`/`to_char`/`DATE_FORMAT`). 12-month window. 3 series (electric/water/gas) with fixed palette. Locale-aware month labels via Carbon `isoFormat`. Common-area meters included (no unit_id filter). Asset-scoped via `TenantScope::currentAssetId()`.

### 1.6 Owner / Portal

Zero utility visibility in either secondary panel. Admin-only.

### 1.7 Tests

| File | Cases |
|---|---|
| [tests/e2e/14-energy.spec.js](../../tests/e2e/14-energy.spec.js) | 2 cases: page loads, nav link visible |
| Widget coverage via `UncoveredWidgetsTest` | EnergyConsumptionTrend instantiation |
| Dedicated model / service tests | **None** — see F-53 |

## 2. Spec map

| Source | Verbatim | Verified |
|---|---|---|
| FEATURES.md L94 | "Energy: `UtilityMeter` · `MeterReading`" | ✅ both models exist |
| FEATURES.md L113 | "48 utility meters (3 common-area + per-unit electric/water across 20 occupied units + gas on F&B) with 576 monthly readings (12 months × 48 meters)." | ✅ verified via seeder |
| FEATURES.md L135 | "EnergyConsumptionTrend — 12-month stacked bar, 3 series (electric / water / gas). Gated by `Modules::enabled('utility_meters')`." | ✅ |
| FEATURES.md roadmap | "Energy optimization workflows — v1 is monitoring-only (data model + consumption chart). Q3 expansion: anomaly detection, peak-demand alerts, IoT sensor integration via OrionTEK-style hooks, cost-allocation to leases via CAM." | ✅ explicit v1-as-monitoring scope |

## 3. Findings

### 🟡 F-52. No admin UI to enter MeterReadings

The data model + dashboard widget are complete, but there's no surface for an operator to add a reading after seed time. To record May 2026's reading for Meter HW-E-201, an operator must either:

1. Run `php artisan tinker` and `MeterReading::create([...])`
2. Drop into the DB directly
3. Hand-edit the seeder

**Fix scope:** add a `MeterReadingsRelationManager` to `UtilityMeterResource`. Form: `reading_date` (date), `reading_value` (numeric), `consumption` (numeric — could be auto-computed from prior reading_value), `cost` (numeric, optional), `notes` (textarea). Table: reading_date / reading_value / consumption / cost / notes. ~80-120 LOC of declarative Filament. Idempotent via the unique `(utility_meter_id, reading_date)` constraint already in the migration.

This is genuinely small ("fix small" territory) but skipping inline for two reasons: (a) the user said the energy module's v1 is "monitoring-only" per FEATURES.md so this is by-design omission, (b) I want to confirm the per-month/per-meter form UX is what operators expect before building it. **D-38** deferred.

### 🟡 F-53. No dedicated UtilityMeterTest / MeterReadingTest

The widget gets instantiation coverage via `UncoveredWidgetsTest`, but model methods (`isCommonArea()`, `latestReading()`, relation cascade rules) are not unit-tested. Worth adding alongside the F-52 RelationManager work, since both touch the reading flow.

**D-39** deferred — bundle with the test-writing pass after the 20-module sweep.

### 🟡 F-54. Q3 roadmap items (consumption → Charge, anomaly detection)

Per FEATURES.md L411-style roadmap, the meter readings will eventually drive utility-charge generation and anomaly detection. Out of scope for this audit; documented for the production roadmap.

### 🟢 No F-17 carryover

`UtilityMeterResource` has no `getNavigationBadge()`.

### 🟢 Common-area handling is correct

`unit_id` nullable + helper `isCommonArea()` on the model. Widget aggregates across both unit-bound and common-area meters per type — which is what operators actually want (total mall consumption per utility).

### 🟢 Driver-aware SQL is solid

`match()` on `DB::connection()->getDriverName()` for `strftime` / `to_char` / `DATE_FORMAT`. Same pattern used by `MallStats` and `MonthlyRevenueTrend`. Tests pass on sqlite (default test driver); manual verification needed on mysql/pgsql production environments.

## 4. Test sweep

| Filter | Result | Time |
|---|---|---|
| `php artisan test --parallel --filter='Energy|Meter|Utility'` | **4 passed / 0 failed** | 1.01 s |
| `npx playwright test tests/e2e/14-energy.spec.js` | **2 passed / 0 failed** | 4.9 s |
| Full Pest (post-Module 12) | 295/295 | 4.3 s |

## 5. No inline fixes this module

F-52 is the only "real" gap and per FEATURES.md is intentionally a v1 omission. F-53/F-54 are forward work. Catalogued for explicit approval.

## 6. Deferred decisions

| # | Decision | Default |
|---|---|---|
| D-38 | F-52: add a MeterReadingsRelationManager on UtilityMeterResource | Apply — small lift, large operator value; operator UX confirmation appreciated |
| D-39 | F-53: dedicated UtilityMeter / MeterReading test file | Bundle with the post-sweep test pass |
| D-40 | F-54: consumption → Charge billing integration | Q3 roadmap; out of scope |

## 7. Verdict

**🟡 Yellow.** Strong v1 data model + dashboard widget; the missing piece is operator self-service reading entry (F-52). The widget already proves the data layer works end-to-end via seeded data. Once F-52 ships, this module moves to 🟢.

Module ratings: 00 🟢 · 01 🟡 · 02 🟢 · 03 🟡 · 04 🟡 · 05 🟡 · 06 🟢 · 07 🟢 · 08 🟡 · 09 🟡 · 10 🟢 · 11 🟢 · 12 🟡 · 13 🟡.

## Next

Module 14 — Credit Notes. Surface: [CreditNote model](../../app/Models/CreditNote.php), [CreditNoteItem model](../../app/Models/CreditNoteItem.php), [Admin CreditNotes resource](../../app/Filament/Admin/Resources/CreditNotes/), [CreditNoteService](../../app/Services/CreditNoteService.php), and the netting effect on `Tenant::outstandingBalance()`.
