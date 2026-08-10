# Properties & Units

> Manage physical mall properties, their subdivisions into units, and track occupancy through lease relationships with automatic status projection.

## 1. Purpose & business context

The Properties & Units module is the spatial foundation of the mall-management ERP. **Assets** represent real-world properties (Haya Walk, Park Avenue, etc.); **Units** are their subdivisions (A-01, A-02, etc.). Every lease occupies one or more units; this module tracks which units are occupied, vacant, reserved (pending lease), or in maintenance. The **All Properties pseudo-asset** (code: `ALL`) enables cross-property views in a single-property-scoped UI, allowing super-admins to switch between property contexts and see portfolio-wide summaries without leaving the Filament panel. Staff assignment (`asset_user`) and ownership (`asset_owner`) pivot tables manage who operates and owns each property.

## 2. Domain model

### Assets table
| Column | Type | Constraints | Meaning |
|--------|------|-------------|---------|
| id | bigint | PK | Auto-increment ID |
| name | varchar | NOT NULL | Display name (e.g., "Haya Walk") |
| code | varchar | UNIQUE NOT NULL | Short code (e.g., "HW"); `ALL` is reserved for the pseudo-asset |
| type | enum | default='mall' | One of: `mall`, `retail_walk`, `mixed_use`, `office`, `residential` |
| address | varchar | nullable | Street address |
| city | varchar | default='Cairo' | City name |
| country | varchar | default='Egypt' | Country name |
| total_area_sqm | decimal(12,2) | nullable | Gross building area (m²). Read by `Asset::leasableEfficiencyPct()` — the load factor shown under GLA on the properties table. Was write-only until 2026-08-10: the form collected it and nothing used it. |
| leasable_area_sqm | decimal(12,2) | nullable | Rentable area (m²) |
| currency | varchar(3) | default='EGP' | ISO 4217 currency code (e.g., "EGP") |
| primary_color | varchar(7) | nullable | Hex color (e.g., "#0F766E") for Filament panel branding when tenant is active |
| metadata | json | nullable | Flexible key–value store for owner info, branding, etc. |
| is_active | boolean | default=true | Soft toggle; does not prevent operations (only soft-deletes hide deactivated assets) |
| created_at, updated_at | timestamp | - | Timestamps |
| deleted_at | timestamp | nullable | Soft-delete marker |

**Models:** `App\Models\Asset`, `App\Models\Unit`, `App\Models\Lease`

**Key Relationships:**
- Asset ↔ Unit (one-to-many via `asset_id`)
- Asset ↔ Lease (one-to-many-through Unit)
- Asset → Staff (many-to-many via `asset_user` pivot; admin panel users assigned to operate this property)
- Asset → Owners (many-to-many via `asset_owner` pivot with `ownership_percentage`, `started_at`, `ended_at`)
- Unit ↔ Lease (many-to-many via `lease_unit` pivot; single-unit leases mirror to `leases.unit_id`)

### Units table
| Column | Type | Constraints | Meaning |
|--------|------|-------------|---------|
| id | bigint | PK | Auto-increment ID |
| asset_id | bigint | FK, cascadeOnDelete | Parent property |
| code | varchar | NOT NULL | Unit code (e.g., "A-01"); unique per asset |
| floor_id | FK floors | nullable | The floor this unit stands on, SELECTED from the property's register (`Asset → Floors`). Replaced a free-text `floor` column and a short-lived `floor_level` ordinal on 2026-08-10 — free text left "G" and "Ground" as two floors to anything that grouped, and an ordinal per unit asked 200 rows to repeat one number. |
| category | enum | default='retail' | One of: `retail`, `food_beverage`, `wellness`, `service`, `kiosk`, `office`, `storage` |
| area_sqm | decimal(10,2) | NOT NULL | Unit area (m²) |
| status | enum | default='vacant' | One of: `vacant`, `reserved`, `occupied`, `maintenance` (see occupancy projection) |
| description | text | nullable | Long-form notes |
| created_at, updated_at | timestamp | - | Timestamps |
| deleted_at | timestamp | nullable | Soft-delete marker |

Unique constraint: `(asset_id, code)`. Indexes: `(asset_id, status)`, `category`.

### Lease Unit pivot table (`lease_unit`)
| Column | Type | Constraints | Meaning |
|--------|------|-------------|---------|
| id | bigint | PK | Auto-increment ID |
| lease_id | bigint | FK, cascadeOnDelete | The lease |
| unit_id | bigint | FK, restrictOnDelete | The unit |
| is_master | boolean | default=false | True for exactly one unit (the lease's primary unit); mirrors `leases.unit_id` |
| created_at, updated_at | timestamp | - | Timestamps |

Unique constraint: `(lease_id, unit_id)`. Index on `unit_id`. **Source of truth for multi-unit leases.**

### Asset Staff pivot table (`asset_user`)
| Column | Type | Constraints | Meaning |
|--------|------|-------------|---------|
| id | bigint | PK | Auto-increment ID |
| user_id | bigint | FK, cascadeOnDelete | Admin panel user |
| asset_id | bigint | FK, cascadeOnDelete | Property |
| role | varchar(100) | nullable | Free-form property-specific role (e.g., "Property Manager", "Site Engineer") |
| assigned_at | date | nullable | Assignment start date |
| ended_at | date | nullable | Assignment end date (soft termination; no cascade) |
| notes | text | nullable | Optional notes |
| created_at, updated_at | timestamp | - | Timestamps |

Unique constraint: `(user_id, asset_id)`. **Distinct from Spatie RBAC roles** (which are global); this pivot records per-property role titles.

### Asset Owner pivot table (`asset_owner`)
| Column | Type | Constraints | Meaning |
|--------|------|-------------|---------|
| id | bigint | PK | Auto-increment ID |
| user_id | bigint | FK, cascadeOnDelete | Owner (Jawad user) |
| asset_id | bigint | FK, cascadeOnDelete | Property |
| ownership_percentage | decimal(5,2) | default=100 | Share percentage (e.g., 50.00 for 50%) |
| started_at | date | nullable | Ownership start date |
| ended_at | date | nullable | Ownership end date |
| created_at, updated_at | timestamp | - | Timestamps |

Unique constraint: `(user_id, asset_id)`. **Captures legal ownership; separate from staff assignment.**

## 3. Business rules & invariants

### Occupancy Projection (Immutable per `Unit::recomputeStatus()`)

Unit status is **never manually authored** in normal flows; it is always **projected from active leases** via `Unit::recomputeStatus()`:

```
If any lease in allLeases() has status ∈ {active}              → 'occupied'
Else if any lease in allLeases() has status ∈ {draft, pending_approval, renewed}  → 'reserved'
Else                                                              → 'vacant'
Exception: If status === 'maintenance', never overwrite (manual override forever)
```

**Guarding test:** `tests/Feature/Scenarios/MultiUnitLeaseDataScenarioTest.php` — exhaustively exercises all status transitions for both master and additional units, maintenance override, and Asset::occupancyRate accuracy.

**Implementation:**
- `Unit::recomputeStatus()` (line 80–97) idempotent query; returns early if status='maintenance'.
- Triggered by `LeaseObserver::created()`, `LeaseObserver::updated()` (on status or unit_id change), **and `LeaseObserver::deleted()` / `restored()`** — the full lifecycle. Soft-delete/restore/force-delete all re-project the affected units, so a deleted lease can't strand its units at `occupied` (`deleting` captures the unit ids into a **static** store first, because a force-delete cascades the pivot away before `deleted` runs, and the observer instance isn't shared across events).
- Also called after `Lease::syncUnits()` recomputes every attached unit, and on **Unit create/edit save** (the pages call `recomputeStatus()`), so an operator-authored `occupied`/`reserved` on a lease-less unit self-heals to `vacant` (`maintenance` is preserved) — making the "status is a projection" invariant true at the write surface, not just when a lease event later fires.

### Multi-unit Leases (via `lease_unit` pivot)

- `leases.unit_id` is **always the MASTER unit** (denormalised for single-unit backward compatibility).
- The **`lease_unit` pivot is the source of truth** for "which units this lease covers"; exactly **one `is_master=true` row**.
- `Lease::syncUnits(array $unitIds, ?int $masterUnitId = null)` is the **canonical way to edit unit membership:**
  - Dedupes and sorts IDs; defaults master to first ID if not supplied or not in the set.
  - Updates the pivot (via `.sync()`) and mirrors master to `leases.unit_id`.
  - Recomputes occupancy for every affected unit (old and new).
  - Idempotent: re-running with the same set produces no duplicate rows.
- Single-unit reassignment via `.update(['unit_id' => ...])` is allowed; `LeaseObserver::updated()` handles pivot consistency.

**Example (tests/Feature/MultiUnitLeaseTest.php:72–90):**
```php
$master = makeUnit($asset, ['code' => 'A-01']);
$extra = makeUnit($asset, ['code' => 'A-02']);
$lease = makeLease($master, null, ['status' => 'active']);
$lease->syncUnits([$master->id, $extra->id], $master->id);
// Both units now occupied; lease.units has 2 rows, exactly one is_master=true
```

### Asset::occupancyRate() Calculation — physical (unit-count) occupancy

```php
occupied_count = count(units where status='occupied')
total_count = count(all units)
return round((occupied_count / total_count) * 100, 1)
```

Returns 0.0 if no units. **Multi-unit leases count each occupied unit separately** (see test `tests/Feature/Scenarios/MultiUnitLeaseDataScenarioTest.php:365–384`).

### Asset::areaOccupancyRate() Calculation — economic (GLA) occupancy

```php
occupied_area = SUM(units.area_sqm where status='occupied')
total_area    = SUM(units.area_sqm)   // all units, bottom-up
return total_area <= 0 ? 0.0 : round((occupied_area / total_area) * 100, 1)
```

Physical occupancy gives every unit one vote; **economic occupancy weights by leasable area**, so it is the figure that tracks the rent roll — leasing the single 2,000 m² anchor moves it far more than leasing five kiosks. The two are surfaced side by side (admin `MallStats`, owner `PortfolioStats` + the property table/infolist), and a wide gap between them is a signal in itself (the vacant space is disproportionately large, or small, units).

Design choices, and why:
- **Denominator is summed bottom-up from the units, NOT the declared `assets.leasable_area_sqm` column.** Numerator and denominator then always share the same scope, so the ratio can never exceed 100% just because the declared GLA and the unit areas disagree.
- **Units with no recorded area contribute nothing to either side** — you can't weight by an area you don't have. So incomplete area data shows up as economic occupancy *drifting* from the unit-count figure; that gap is a real data-quality signal, not a bug.
- **Guarded against divide-by-zero** — a property with no units, or all-zero-area units, reads 0.0% (never NaN).

Companion accessors: `occupiedAreaSqm()`, `totalUnitAreaSqm()`. Guarding test: `tests/Feature/Models/AssetTest.php` (weighted divergence + both zero-denominator paths).

### All Properties Pseudo-Asset (`Asset::ALL_PROPERTIES_CODE = 'ALL'`)

- A real database row with code='ALL'; allows Filament to resolve it from URL slugs and tenant-switching.
- Never editable; hidden from the property list; used **only as a tenant context switcher** for "cross-property view" operations.
- `AssetResource::canCreate()` returns false when inside a specific property context; true only when tenant is the ALL pseudo-asset or unset.
- `AssignedAssets::idsForCurrentUser()` scopes to user's assigned assets (both `asset_user` and `asset_owner` relationships), but always hides the ALL pseudo-asset from the restricted user's visible set.

### Staff vs. Owners

- **Staff** (`asset_user` pivot): Admin panel users assigned to *operate* this property (Property Manager, Leasing Lead, etc.). Role is a free-form label per asset, separate from global RBAC roles.
- **Owners** (`asset_owner` pivot): Users with *legal ownership* stake (Jawad users); ownership_percentage sums to 100.0 across all owners. Both scoped to their owned properties via `AssignedAssets::idsFor()`.

**Scoping:** `AssignedAssets::idsFor(User)` returns the union of `assignedAssets` and `ownedAssets` IDs, excluding the ALL pseudo-asset. Super-admins see everything (null return = no scoping).

## 4. Lifecycle / state machine

### Asset
No explicit state machine; assets are soft-deleted (via `SoftDeletes` trait).
- **Created:** via Filament AssetResource form; can only be created from the ALL Properties context or globally.
- **Active:** toggled via `is_active` boolean; does not block operations (only soft-deletes hide records).
- **Deleted:** soft-deletes via trash menu; `getRecordRouteBindingEloquentQuery()` excludes soft-delete scope for route binding, allowing restoration.

### Unit
No explicit lifecycle; status is a projection of leases (immutable by recomputeStatus logic).
- **Vacant:** Initial default; no active/draft/pending/renewed leases.
- **Reserved:** Any draft/pending_approval/renewed lease (lease waiting approval or future).
- **Occupied:** Any active lease.
- **Maintenance:** Manual override; never auto-recomputed (one-way; only manual status change can lift it).

**Transition guard:** Cannot attach a unit to an active lease if it's already covered by another active lease — checked via **`Unit::isActivelyLeased()`**, which consults the `lease_unit` **pivot** (master OR additional unit), NOT just the `leases.unit_id` master pointer (else a unit held only as an additional unit in a multi-unit lease could be re-booked). Enforced in both `LeaseCreationService` and the lease form's `unit_id` rule.

### Lease (triggers occupancy changes)
- **Draft → Active:** LeaseObserver fires on status change; all attached units recompute → `occupied`.
- **Active → Terminated/Expired/Cancelled:** All attached units recompute → `vacant` (or `reserved` if a draft/pending lease remains).
- **Unit removal via syncUnits:** Old units recompute → `vacant` (unless another active lease covers them).

**No terminal state for units:** A unit can flip between occupied/vacant/reserved any number of times; only `maintenance` is "sticky."

## 5. Services, jobs & scheduled commands

### LeaseCreationService

**Signature:** `create(array $payload): Lease` (line 22)

**Input payload:**
```php
[
    'tenant_mode' => 'existing' | 'new',
    'tenant_id' => int (if existing),
    'tenant' => [name, legal_name, type, email, password, phone, ...] (if new),
    'lease' => [
        'unit_id' => int,
        'commencement_date' => date string,
        'term_months' => int,
        'base_rent_monthly' => float,
        'service_charge_monthly' => float (optional, default 0),
        'security_deposit' => float (optional, default rent * 3),
        'escalation_rate' => float (optional, default 7),
        'payment_terms_days' => int (optional, default 7),
    ]
]
```

**Behavior:**
1. Creates or fetches tenant.
2. Validates no active lease on the unit already (throws ValidationException).
3. Creates lease with status='active', auto-generates reference (LSE-{ASSETCODE}-{YEAR}-{SEQ}).
4. Seeds standard Egypt charges: Base Rent (VAT-exempt) + Service Charge (VAT at the settings-driven standard rate, 14% today).
5. LeaseObserver mirror pivot row + recomputeStatus → unit becomes occupied.
6. **Transaction:** Entire operation wrapped in DB::transaction.
7. **Idempotency:** If lease already has charges, `seedStandardCharges()` skips seeding.

**When it runs:** On lease creation via Filament create form (handled by `CreateLease::handleRecordCreation()`) or via wizard/service.

### LeaseObserver

**Signature:** Registered in `EventServiceProvider` or boot of models (triggered on Lease lifecycle).

**created():** (line 28–31)
- Calls `ensureMasterPivot()` → creates a lease_unit row with `is_master=true` mirroring `leases.unit_id`.
- Calls `recomputeUnits()` → recomputes occupancy for every attached unit.

**updated():** (line 33–50)
- Early return if neither status nor unit_id changed.
- If `unit_id` changed (single-unit reassignment):
  - Detaches the old unit from the pivot.
  - Recomputes old unit's status → likely `vacant`.
  - Ensures new unit has a master pivot row.
- Calls `recomputeUnits()` → recomputes occupancy for all currently attached units.

**private ensureMasterPivot():** (line 52–62)
- Calls `syncWithoutDetaching([lease.unit_id => ['is_master' => true]])` → guarantees exactly one master pivot row, never duplicates.
- Idempotent; safe to re-run.

**private recomputeUnits():** (line 64–68)
- Fetches all units via `units()` (lease_unit pivot) and calls `recomputeStatus()` on each.

**When it runs:** On every Lease create/update lifecycle event.

## 6. Filament resources & key fields

### AssetResource
**Resource:** `App\Filament\Admin\Resources\Assets\AssetResource`

**Scoping:** `isScopedToTenant = false` (Assets are the tenant context itself, not scoped below it).

**Query scoping** (`getEloquentQuery()`):
- Always hides the ALL pseudo-asset from the list.
- If inside a specific property context (TenantScope::currentAssetId() set), restricts to that property only.
- Otherwise (All Properties view), falls back to user's assigned assets via `AssignedAssets::idsForCurrentUser()`.

**canCreate():** Returns false if inside a specific property context; true only from All Properties view or globally (and user has create permission).

**Form Schema** (AssetForm):
- **Property Details Section:**
  - name (text, required, max 120)
  - code (text, required, max 10, alphaDash, unique ignoring record)
  - type (select: mall, retail_walk, mixed_use, office, residential; default mall)
  - address (textarea, 2 rows)
  - city (text, required, default Cairo)
  - country (text, required, default Egypt)
  - currency (text, required, default EGP, max 3)
- **Area Section:**
  - total_area_sqm (numeric, suffix m²) — gross building area; the table shows GLA as a percentage of it
  - leasable_area_sqm (numeric, suffix m²)
- **Status Section:**
  - is_active (toggle, default true)
- **Branding Section:**
  - logo (SpatieMediaLibraryFileUpload, collection='logo', image with editor, max 2 MiB)
  - favicon (SpatieMediaLibraryFileUpload, collection='favicon', image, max 512 KiB)
  - primary_color (ColorPicker for hex #RRGGBB; used as panel accent when tenant active)

**Activity logged:** name, code, type, city, leasable_area_sqm, is_active, primary_color (dirtyOnly, via Spatie LogsActivity).

**Table columns** (AssetsTable):
- code (badge, searchable, sortable)
- name (weight bold, searchable, sortable)
- type (enum label, badge)
- city (searchable, sortable)
- leasable_area_sqm (formatted "X m²", sortable), with the load factor underneath — "of 12,000 m² gross · 70.8% lettable"
- leasable_area_sqm (formatted "X m²", sortable)
- occupancyRate() (badge, color based on %: danger <50%, warning <75%, success ≥75%)
- is_active (boolean badge)

**Relation Managers:**
1. AssetUnitsRelationManager — lists units with filters (status, category), shows tenant, rent, lease expiry.
2. AssetStaffRelationManager — pivot table; attach/detach staff with role, assigned_at, notes.
3. ActivitiesRelationManager — audit log (via Spatie LogsActivity).

---

### UnitResource
**Resource:** `App\Filament\Admin\Resources\Units\UnitResource`

**Scoping:** Uses `BypassesScopingOnAll` (allows listing across all properties when tenant=ALL pseudo-asset; otherwise scoped to current property via `tenantOwnershipRelationshipName='asset'`).

**Navigation badge:** Shows count of vacant units for current property (or portfolio-wide if in All Properties context).

**Form Schema** (UnitForm):
- **Unit Details Section:**
  - asset_id (select relationship 'asset' by name; required; disabled if TenantScope::currentAssetId() set, defaulting to current asset)
  - code (text, required, max 20, placeholder A-01)
  - floor_id (Select, scoped to the property's own floors, ordered by level)
  - category (select enum; required)
  - area_sqm (numeric, required, suffix m²)
  - status (select enum: vacant/reserved/occupied/maintenance; default vacant, required)
  - description (textarea, 2 rows, full-width)

**Table columns:**
- code (badge, searchable, sortable)
- floor.code (toggleable)
- category (badge, enum label)
- area_sqm (formatted "X m²", sortable)
- activeLease.tenant.name (searchable; show current tenant or —)
- activeLease.base_rent_monthly (money EGP; show rent or —)
- activeLease.expiry_date (date d/m/Y; warning color if expiring within 90 days)
- status (badge with color: success=occupied, warning=vacant, danger=maintenance, gray=reserved; sortable)

**Filters:** status (select), category (select).

**RBAC:** Uses `RoleGatedActions` trait; delete and edit actions check `units.delete`, `units.update` permissions.

---

### AssetStaffRelationManager (Detailed)
**Manages:** Asset → Staff (via `asset_user` pivot).

**Form fields** (for AttachAction and EditAction):
- role (TextInput, max 100, helper: "e.g., Property Manager, Site Engineer")
- assigned_at (DatePicker, default=now())
- ended_at (DatePicker)
- notes (Textarea, 2 rows)

**Table columns:**
- name (weight bold, searchable)
- email (copyable)
- roles.name (badge, localized enum label, color gray)
- pivot.role (placeholder —)
- pivot.assigned_at (date d/m/Y, placeholder —)

**Actions:**
- AttachAction: Preloads user select; excludes tenants/owners-only (filters to users with role != 'owner'); allows setting role, assigned_at, notes on attach.
- EditAction: Edits pivot fields inline.
- DetachAction: Removes assignment.
- Default sort: `pivot_assigned_at DESC` (most recent first).

---

### Filament Tenant Scoping
- **Asset:** NOT scoped (is the tenant context).
- **Unit:** Scoped to `asset` (via `tenantOwnershipRelationshipName = 'asset'`); bypasses ALL pseudo-asset via `BypassesScopingOnAll` trait.
- **Lease:** Scoped to tenant asset; displays multi-unit via `additional_unit_ids` field on EditLease form.

## 7. Notifications & integrations

**No direct notifications for Properties & Units module.**

Related modules send notifications:
- **Lease module:** LeaseRenewalService, LeaseTerminationService notify tenants/owners.
- **Maintenance module:** MaintenanceStatusChangedNotification, MaintenanceSlaBreachedNotification.
- **Invoice module:** InvoiceIssuedNotification, InvoiceOverdueOwnerNotification.

Properties & Units are read/queried by these; no outbound notifications originate here.

## 8. Extension points — how to change/extend SAFELY

### Adding a New Asset Field
1. **Add migration:** `php artisan make:migration add_field_to_assets_table`
   ```php
   $table->type('field_name')->after('previous_field');
   ```
2. **Update model** (`Asset.php`):
   - Add to `$fillable` array.
   - Add to `$casts` if non-string type.
   - Add to LogsActivity config if audit-tracked.
3. **Update form** (`AssetForm.php`): Add field component in appropriate section.
4. **Update table** (`AssetsTable.php`): Add column if should be visible in list.
5. **Write test:** `tests/Feature/Models/AssetTest.php` — verify fillable + cast + activity log.

### Adding a New Unit Category
1. **Update migration** (`create_units_table.php` or add new migration):
   ```php
   $table->enum('category', ['retail', ..., 'new_category'])->default('retail');
   ```
2. **Add localization** (resource string file):
   ```php
   'admin.enums.category.new_category' => 'New Category Label'
   ```
3. **No model change** (enum defined in schema, not PHP).
4. **Write test:** Verify the enum value persists and displays correctly.

### Changing Occupancy Projection Logic
**CRITICAL: Must update Unit::recomputeStatus() and all tests.**

1. **Edit Unit::recomputeStatus()** (line 80–97):
   - Adjust the status assignment logic (the match statement).
   - Maintain idempotency: early return if status === 'maintenance'.
   - Always recompute from `$this->allLeases()` (the lease_unit pivot).
2. **Add comprehensive test** in `tests/Feature/Scenarios/MultiUnitLeaseDataScenarioTest.php`:
   - Test the new rule against both master and additional units.
   - Verify Asset::occupancyRate() accuracy after the change.
3. **Verify LeaseObserver hooks:** Ensure `created()` and `updated()` still trigger `recomputeUnits()` correctly.

**Example (adding an 'abandoned' status override):**
```php
// In Unit::recomputeStatus()
if ($this->status === 'maintenance' || $this->status === 'abandoned') {
    return;  // neither is auto-overwritten
}
// ... rest of logic
```

### Extending Multi-unit Leases
If you need to attach metadata to individual unit-lease relationships (e.g., separate rent splits per unit):

1. **Add column to lease_unit migration:**
   ```php
   $table->decimal('allocated_rent_monthly', 10, 2)->nullable();
   ```
2. **Update model relationships** to include withPivot:
   ```php
   // In Lease::units()
   ->withPivot('is_master', 'allocated_rent_monthly');
   ```
3. **Update Lease::syncUnits()** to accept and persist pivot data:
   ```php
   public function syncUnits(array $unitIds, ?int $masterUnitId = null, array $pivotData = []): void
   ```
4. **Add form field** in EditLease for `additional_unit_rental_splits` or similar.
5. **Write scenario test** exercising the new pivot field across master reassignment, unit removal, and recomputeStatus.

### Editing Staff Assignment UI
If you need to add fields to the asset_user pivot (e.g., approval_status, budget_allocation):

1. **Add migration:**
   ```php
   $table->enum('approval_status', ['pending', 'approved', 'rejected'])->default('pending');
   ```
2. **Update AssetStaffRelationManager form** to include the new field:
   ```php
   Select::make('approval_status')->options(['pending' => 'Pending', ...])
   ```
3. **No model change** needed; the pivot relationship already loads via `withPivot()`.
4. **Write test:** Verify the pivot field syncs correctly via attach/edit/detach.

### Adding a NEW Pseudo-Asset Context (beyond ALL)
**Not recommended** (adds complexity), but if needed:

1. Define new constant in Asset:
   ```php
   public const SALES_REPORTS_CODE = 'SALES_RPT';
   ```
2. Seed in migration.
3. Update scoping logic in AssetResource and UnitResource getEloquentQuery():
   ```php
   ->where('assets.code', '!=', Asset::ALL_PROPERTIES_CODE)
   ->where('assets.code', '!=', Asset::SALES_REPORTS_CODE)
   ```
4. Update BypassesScopingOnAll trait to recognize the new pseudo-asset.
5. Comprehensive tests for context-switching and data isolation.

### Changing Asset Branding (Logo, Favicon, Primary Color)
These use Spatie MediaLibrary:

1. **Logo/Favicon:** Edit in AssetForm; re-uploading replaces (singleFile collections). No migration needed; stored in media table.
2. **Primary Color:** Added via `add_primary_color_to_assets_table` migration; edit in AssetForm color picker.
3. **Usage:** `asset.logoUrl()` and `asset.faviconUrl()` in AdminPanelProvider; `asset.primary_color` used by panel config to set accent color.
4. **Test:** Verify MediaLibrary collection registrations in `Asset::registerMediaCollections()`.

## 9. Gotchas, edge cases & recently-fixed bugs

### Gotcha 1: Unit Status Projection is Read-Only
Unit status **must never be manually set** except for `maintenance`. Any direct `.update(['status' => 'occupied'])` will be silently overwritten when a lease status changes. Always edit leases to change occupancy; `recomputeStatus()` is the source of truth.

**Test:** `tests/Feature/Scenarios/MultiUnitLeaseDataScenarioTest.php:49–56` — status is always projected, never user-authored.

### Gotcha 2: Maintenance Override is Sticky
Once `status='maintenance'`, `recomputeStatus()` does not override it, even if an active lease is later attached. This is intentional: maintenance is a manual override for repairs, inspections, etc. Only a direct status update can lift it.

**Test:** `tests/Feature/Scenarios/MultiUnitLeaseDataScenarioTest.php:227–257` — maintenance survives even when a lease activates or terminates.

### Gotcha 3: ALL Pseudo-Asset Hides in Filtered Queries
The ALL pseudo-asset (code='ALL') is filtered out of nearly all queries:
- `AssetResource::getEloquentQuery()` explicitly excludes it.
- `AssignedAssets::idsFor()` hides it from restricted-user queries.
- Seeded once; never shown in the property list; only used for tenant-switching.

**Bug risk:** If you .pluck('assets.id') without filtering, you'll accidentally include ALL. Always use `.where('assets.code', '!=', Asset::ALL_PROPERTIES_CODE)` or rely on `getEloquentQuery()`.

### Gotcha 4: Master Unit Denormalisation in `leases.unit_id`
The `lease_unit.is_master=true` row is mirrored into `leases.unit_id` for backward compatibility and to avoid the pivot lookup on every single-unit code path. **If you edit the pivot without updating `leases.unit_id`, the system will be inconsistent.**

**Safe:** Always use `Lease::syncUnits()` (which handles mirroring) or let `LeaseObserver::ensureMasterPivot()` + `.syncWithoutDetaching()` handle it on `.update(['unit_id' => ...])`.

**Unsafe:** `DB::table('lease_unit')->insert(...)` without updating `leases.unit_id` → occupancy and master lookups will disagree.

### Gotcha 5: Occupancy Calculation Counts Every Unit Once
`Asset::occupancyRate()` counts the number of units with status='occupied', not the sum of occupancy rates per lease. So a multi-unit lease spanning 3 units counts as 3 occupied units toward the denominator.

**Test:** `tests/Feature/Scenarios/MultiUnitLeaseDataScenarioTest.php:365–384` — 3-unit lease over 4 units = 75% occupancy.

### Recently-Fixed Bugs

1. **Multi-unit Lease Occupancy Regression (Feature #14)**
   - **Issue:** Occupancy was projected only from `leases.unit_id` (master), ignoring additional units in the pivot.
   - **Fix:** Unit::recomputeStatus() now queries `allLeases()` (via lease_unit pivot), ensuring additional units' statuses are projected correctly.
   - **Test:** `tests/Feature/Scenarios/MultiUnitLeaseDataScenarioTest.php` added to prevent regression; all lease statuses (draft, active, expired, etc.) tested against both master and additional units.

2. **Duplicate Master Pivot Rows on Lease Status Update**
   - **Issue:** LeaseObserver::updated() calling ensureMasterPivot() multiple times (on different status changes) created duplicate rows.
   - **Fix:** Changed to `.syncWithoutDetaching()` (idempotent; upserts rather than inserts).
   - **Test:** `tests/Feature/Scenarios/MultiUnitLeaseDataScenarioTest.php:280–294` — verifies no duplicates after repeated updates.

3. **Staff Assignment Scoping in AssignedAssets (Staff vs. Owners)**
   - **Issue:** Staff-only users were not being scoped; they could see all assets.
   - **Fix:** `AssignedAssets::idsFor()` now unions both `assignedAssets()` and `ownedAssets()`, excluding the ALL pseudo-asset.
   - **Test:** `tests/Feature/AssignedAssetsTest.php` — verifies restricted users see only assigned + owned assets.

4. **Orphaned Unit Pivot on Single-Unit Reassignment**
   - **Issue:** Changing `leases.unit_id` left old pivot rows (detaching did not happen).
   - **Fix:** LeaseObserver::updated() now calls `units()->detach($original)` when `unit_id` changes.
   - **Test:** `tests/Feature/Scenarios/MultiUnitLeaseDataScenarioTest.php:296–312` — verifies old unit freed and no orphan pivot rows.

## 10. Tests & related modules

### Core Property & Unit Tests
- **`tests/Feature/Models/AssetTest.php`** — occupancy rate, unit counts, pseudo-asset flag, relationships.
- **`tests/Feature/Models/LeaseTest.php`** — lease-specific logic (not occupancy; see Lease module).
- **`tests/Feature/MultiUnitLeaseTest.php`** — multi-unit pivot basics (single→master mirroring, additional units, form integration).
- **`tests/Feature/Scenarios/MultiUnitLeaseDataScenarioTest.php`** — exhaustive scenario: all lease statuses, master reassignment, edge cases, maintenance override, occupancy math.
- **`tests/Feature/AssignedAssetsTest.php`** — staff + owner scoping, super_admin bypass, back-compat unrestricted fallback.
- **`tests/Feature/Tenancy/AssetResourceTest.php`** — resource scoping (within specific property, All Properties context, ALL pseudo-asset hiding).
- **`tests/Feature/Branding/AssetBrandingTest.php`** — logo/favicon/primary_color upload and display.
- **`tests/Feature/Tenancy/SoftDeletedAssetTest.php`** — soft-delete behavior, restoration.

### Related Modules
- **Lease module** — Lease model, LeaseCreationService, status transitions, charges.
- **Tenant module** — Tenant model, used when creating leases.
- **Charge module** — Charges seeded on lease creation (rent, service charge).
- **Maintenance module** — MaintenanceRequest references Unit; operates within property context.
- **Utility Meters module** — UtilityMeter.asset and .unit foreign keys.
- **Invoice/Billing module** — Invoices generated per Lease; roll up to Asset for statements.
- **User/RBAC module** — Spatie Permission roles + asset_user staff assignments.

### Running Tests
```bash
# Full suite
php artisan test

# Properties & Units only
php artisan test tests/Feature/Models/AssetTest.php
php artisan test tests/Feature/MultiUnitLeaseTest.php
php artisan test tests/Feature/Scenarios/MultiUnitLeaseDataScenarioTest.php
php artisan test tests/Feature/AssignedAssetsTest.php

# With coverage
php artisan test --coverage tests/Feature/Models/AssetTest.php

# Specific test
php artisan test tests/Feature/Scenarios/MultiUnitLeaseDataScenarioTest.php --filter "reflects_a_multi_unit_lease"
```

---

## Summary

The Properties & Units module is the spatial and organizational backbone of the mall-management ERP. It maps real-world properties and their subdivisions, projects occupancy from lease lifecycles (via the `lease_unit` pivot and `LeaseObserver`), and scopes all operations to the active property context (or All Properties for cross-property views). Every change to properties, units, or lease assignments must respect the immutability of unit occupancy projection (only manual 'maintenance' override is sticky) and the denormalised master unit pointer in `leases.unit_id`. Comprehensive test coverage in `MultiUnitLeaseDataScenarioTest.php` guards against occupancy regressions; always run the full test suite before modifying asset/unit/occupancy logic.

---

## Deletion policy

Operator decision 2026-07-31, following Yardi/MRI/Entrata: a record that carries history is
**refused**, not warned about — the damage lands on the reports and audit trail that referenced
it, none of which are in front of whoever clicks the button. The single register is
[`App\Support\DeletionPolicy`](../../app/Support/DeletionPolicy.php); `DeletionPolicyConformanceTest` fails the build if a model here ships unclassified or a Delete
button reappears on a money record.

| Model | Rule | Instead / why |
|---|---|---|
| `Unit` | **Only while unreferenced** — blocked by `allLeases`, `maintenanceRequests`, `utilityMeters` | set the unit to maintenance if it is out of service — a unit that has been leased is part of the property record |
| `Asset` | **Only while unreferenced** — blocked by `units`, `leases`, `camPools`, `utilityMeters`, `owners`, `journalEntries`, `expenses`, `vendorBills`, `payrolls`, `disbursements`, `maintenancePenalties`, `depositTransactions`, `postDatedCheques`, `employees`, `fixedAssets`, `marketingBudgets`, `violations` | deactivate the property — deleting one would orphan (or cascade-destroy) every book, payroll, register and penalty that reports on it |
