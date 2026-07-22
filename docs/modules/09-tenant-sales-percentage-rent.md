# Tenant Sales Declarations & Percentage Rent

> Tenants on percentage-rent leases submit periodic sales declarations by **uploading their sales report file**; the operator reviews the file, enters the sales figure, and locks the declaration — which **bills the percentage-rent overage immediately as its own invoice**.

> **File-first submission (2026-07):** Tenants no longer type a sales figure. On both the mobile app and the web portal they **attach their sales report** (image/PDF). `declared_sales` is nullable and is **entered by staff** in the admin panel after reviewing the attachment, then Lock bills the percentage rent. The report file lives in the Spatie `sales_report` media collection on a **private** disk (it can carry commercial turnover figures) and is streamed only through authenticated, tenant-scoped endpoints.

## 1. Purpose & business context

In Egyptian malls, tenants (Eltizam = operator retail stores) often pay rent via **two components**:
1. **Base rent** (fixed monthly minimum)
2. **Percentage rent** (a % of actual sales above a threshold, or as a natural breakpoint)

Tenants must declare their **actual sales** for each period so the operator (Jawad = property manager) can audit the figures and bill the percentage-rent portion. The system:
- Lets tenants submit sales declarations via the **Portal**
- Lets managers audit, lock (finalize), or dispute declarations via **Admin**
- Automatically calculates the **percentage rent owed** using the lease's configured formula
- **Bills the overage immediately** when locked as its own issued invoice (see the billing-gap note below)

This is critical to rental compliance and owner cash flow—tenants cannot skip reporting, and the operator has full audit trails (activity logs, audit notes, locked-by user stamps).

## 2. Domain model

| Table | Model | Key columns | Meaning |
|-------|-------|-------------|---------|
| `tenant_sales_declarations` | `TenantSalesDeclaration` | `lease_id` (FK Lease) | Which lease's rent is being reported |
| | | `period_start` date, `period_end` date | Reporting period (e.g., Jan 1–31, 2026) |
| | | `declared_sales` decimal(14,2) **nullable** | Sales figure — **null at submission**, entered by staff after reviewing the uploaded report, in EGP |
| | | `calculated_percentage_rent` decimal(14,2) | Computed owed amount (default 0; stays 0 until staff enter `declared_sales`) |
| | | *(Spatie media)* `sales_report` collection | The tenant's uploaded sales report file(s) (image/PDF), on the private `local` disk |
| | | `status` enum('submitted','locked','disputed') | Workflow state |
| | | `declared_at` timestamp | When tenant submitted |
| | | `declared_by_type`, `declared_by_id` | Polymorphic author (Tenant or User) |
| | | `locked_at` timestamp, `locked_by_user_id` FK | Final approval: who & when |
| | | `audit_notes` text | Reason for locking/disputing/voiding |
| | | Unique index: `(lease_id, period_start)` | One declaration per lease per period |

**Related tables:**
- `leases` — the source of truth for percentage-rent config (see below)
- `charges` — one-off `type='percentage_rent'` **inactive** anchor row created per locked declaration (traceability + the void/re-lock identity key); the money is billed on an immediately-issued `invoices` row linked via an `invoice_items` row of `type='percentage_rent'`

**Lease percentage-rent columns:**
| Column | Type | Meaning |
|--------|------|---------|
| `has_percentage_rent` | boolean (default false) | This lease carries pct-rent terms |
| `percentage_rent_threshold` | decimal(12,2) nullable | Artificial breakpoint: sales amount below which 0% owed |
| `percentage_rent_rate` | decimal(5,2) nullable | Rate, e.g. 5.00 = 5% |
| `percentage_rent_calculation_type` | enum nullable | 'artificial' (default if null) or 'natural_breakpoint' |
| `base_rent_monthly` | decimal(14,2) | Used in natural breakpoint formula only |

**Relationships:**
- `TenantSalesDeclaration.lease()` → `Lease` (BelongsTo)
- `TenantSalesDeclaration.declaredBy()` → polymorphic `Tenant` or `User` (MorphTo)
- `TenantSalesDeclaration.lockedByUser()` → `User` (BelongsTo)
- `Lease.charges()` → `Charge[]` where type='percentage_rent' (implicit via lease_id)

## 3. Business rules & invariants

### Calculation formulas
Two calculation methods, set per lease:

**Artificial breakpoint (default):**
```
percentage_rent = max(0, (declared_sales - threshold) * (rate / 100))
```
Example: Sales 100k, threshold 50k, rate 5% → (100k - 50k) × 0.05 = **2,500 EGP owed**

Test: `PercentageRentCalculationServiceTest::artificial breakpoint: (sales - threshold) * rate`

**Natural breakpoint:**
```
percentage_rent = max(0, (declared_sales * rate / 100) - base_rent_monthly)
```
Example: Sales 200k, rate 8%, base rent 10k → (200k × 0.08) - 10k = 16k - 10k = **6,000 EGP owed**

Test: `PercentageRentCalculationServiceTest::natural breakpoint: sales * rate - base_rent`

### Floor at zero
Both formulas guarantee no **negative charges**. Tenants under-threshold or under-breakpoint owe exactly **0 EGP**.

Test: `PercentageRentCalculationServiceTest::floors percentage rent at zero (no negative charges)`

### Period uniqueness
Only **one declaration per (lease, period_start)** may exist. A tenant cannot submit two declarations for the same month.

Database: Unique index `(lease_id, period_start)`.

Test: Portal form validation enforces this; API `CreateSalesDeclarationAction` rejects duplicates.

### Decimal precision
Calculated amounts are rounded to **2 decimal places** (standard currency precision). A fractional rate (e.g., 2.5%) must round correctly.

Test: `PercentageRentScenarioTest::fractional-rate rounding to 2dp`

### Only active, percentage-rent leases may declare
The API action `CreateSalesDeclarationAction` verifies:
- The lease belongs to the tenant (`tenant_id` match)
- The lease is `status='active'`
- `has_percentage_rent=true`

Test: `CreateSalesDeclarationAction` handles the validation.

### Calculation type defaults to 'artificial'
If `percentage_rent_calculation_type` is null, the service falls back to 'artificial'.

Test: `PercentageRentScenarioTest::defaults to the artificial formula when calculation_type is null`

### Locked declarations are immutable
Once `status='locked'`, the declaration cannot be edited via the normal edit form. Only two actions remain:
- **Void** → flip to 'disputed', reverse the overage (deactivate the anchor charge + cancel its immediate invoice)
- **Delete** (soft-delete only)

### The billing gap — why the overage is billed IMMEDIATELY (not via the monthly run)
> **Invariant (hard-won — do not re-break).** Locking bills the overage as its **own immediately-issued invoice**, mirroring the CAM positive true-up (`billChargeImmediately`).
>
> The old design created an **active** one_time `percentage_rent` Charge dated to the (past) sales month and relied on the monthly billing run to pick it up. But the monthly engine only bills charges whose period **overlaps the run month**, so a charge dated to a bygone month was **never billed** — the locked overage silently vanished (revenue leak). The `start_date = period_start` (a past date) is exactly what strands it.

When locked with `calculated_percentage_rent > 0`, `lock()`:
1. Creates an **inactive anchor** Charge (`type='percentage_rent'`, `frequency='one_time'`, `vat_applicable=false`, **`is_active=false`**, `start_date=period_start`, `end_date=period_end`, amount = the calculated figure). It is *never* billed by the monthly run — it's a traceability record + the void/re-lock identity key.
2. Creates an **issued** `Invoice` for the same amount, `issue_date=now()`, with `period_start`/`period_end` = the **sales period** (the truthful period the overage covers).
3. Links them with an `InvoiceItem` of `type='percentage_rent'` → the GL journalizer posts it as `percentage_rent_revenue`.

If `calculated_percentage_rent == 0`, **nothing is created** (no charge, no invoice — tenant was under-threshold).

> **Monthly-run exclusion (the second half of the fix).** The overage invoice's period is a real single month, so — unlike the CAM annual recovery invoice — it *does* fall inside `MonthlyBillingService`'s "already billed" window. If left unguarded, a back-filled / late monthly run for that same month would see it, think the lease is already billed, and silently skip the base rent (a second revenue leak). So both idempotency probes in `MonthlyBillingService` (`runForPeriod` + `generateForLease`) now exclude pure percentage-rent overage invoices (`whereDoesntHave('items', type='percentage_rent')`). See module 05 § "Idempotency & lifecycle". Test: `PercentageRentImmediateBillingTest::the immediate overage invoice does not suppress that month's regular rent invoice`.

> **Concurrency.** `lock()` and `voidLocked()` re-read the declaration with `lockForUpdate()` and re-check its status **inside** the transaction (mirroring `VoidInvoiceService` / `CamReconciliationService`). Without it, two racing locks (a double-clicked Filament action, two staff) would each bill their own overage invoice — a double-bill + double GL posting. Test: `PercentageRentImmediateBillingTest::does not re-bill or churn the overage on a stale-snapshot double-lock`.

Test: `PercentageRentImmediateBillingTest::lock bills the overage as an immediate issued invoice`, `PercentageRentScenarioTest::lock writes a one-off, VAT-free percentage_rent charge bounded to the declaration period`

### Charge period scoping (the void/re-lock identity key)
Each anchor Charge's `start_date`/`end_date` are set to the declaration's period. Void and re-lock match the charge by `(lease_id, type='percentage_rent', start_date=period_start)` — so reversing one period never touches a sibling period's charge or its immediate invoice.

Test: `PercentageRentScenarioTest::locks two periods independently, each charge bounded to its own period` and `PercentageRentVoidLockedTest::voidLocked only reverses the period-specific overage`

### Dispute workflow
A submitted declaration can be **disputed** without locking (flips status to 'disputed', no charge created). This is used when the tenant figure is clearly wrong before audit.

Test: Filament table action `dispute` in `TenantSalesDeclarationsTable`.

## 4. Lifecycle / state machine

```
submitted ──[lock]─→ locked ──[voidLocked]─→ disputed
  ↓                      ↓
  └─[dispute]──────────→ disputed
                            ↓ [re-lock]
                           locked (fresh overage invoice)
```

| Status | Meaning | Transitions | Terminal? |
|--------|---------|-------------|-----------|
| `submitted` | Tenant or admin created; pending audit | → `locked` (lock action) or → `disputed` (dispute action) | No |
| `locked` | Admin approved; overage invoiced immediately if owed>0 | → `disputed` (voidLocked action) | No (can re-lock) |
| `disputed` | Flagged for audit or voided; overage invoice cancelled | → `locked` (re-lock action) | No |

### State transitions

**submitted → locked** (lock action)
- Triggered by: Admin "Lock" button in Filament table
- Guard: `status === 'submitted'` (any non-`locked` status locks afresh — a `disputed` declaration can be re-locked)
- Side effects: Recalculate, stamp `locked_at` and `locked_by_user_id`; reverse any prior overage for this lease+period (re-lock safety), then bill the overage immediately (anchor charge + issued invoice) if owed>0; fire `SalesDeclarationLockedNotification` to tenant
- Idempotent: Calling lock on an already-`locked` declaration returns the same record (no re-bill)
- Audit: Optional `audit_notes` parameter

Test: `PercentageRentCalculationServiceTest::locks a declaration and creates the percentage-rent charge`, `PercentageRentCalculationServiceTest::locking is idempotent`

**submitted → disputed** (dispute action)
- Triggered by: Admin "Dispute" button in Filament table (with required reason)
- Guard: `status === 'submitted'`
- Side effects: Flip status, store `audit_notes` with reason; no charge created
- No notification to tenant (internal flag)

**locked → disputed** (voidLocked action)
- Triggered by: Admin "Void Locked" button (after declaring locked declaration incorrect)
- Guard: `status === 'locked'`
- Side effects: Reverse the overage — deactivate the period-matching anchor Charge (`is_active=false`, `end_date=now()`) **and cancel its immediate invoice** via `VoidInvoiceService` (so the GL entry is voided by the sweep) — then flip status to 'disputed' and append an audit note with operator + reason + date
- **Refused if the overage invoice was already PAID**: `VoidInvoiceService` throws `DomainException`, the whole transaction rolls back, and the Filament action shows a persistent error telling the operator to refund the payment first
- Idempotent: Calling voidLocked on a non-locked declaration is a no-op (belt-and-braces, though the UI gates it)

Test: `PercentageRentVoidLockedTest::voidLocked cancels the immediate overage invoice and flips status to disputed`, `PercentageRentImmediateBillingTest::refuses to void when the overage invoice has been PAID`, `PercentageRentVoidLockedTest::voidLocked is a no-op on a non-locked declaration`

**disputed → locked** (re-lock action)
- A disputed declaration can be locked again (e.g., after audit correction)
- Re-lock safety: `lock()` first reverses any prior overage for the lease+period (cancels the old invoice), then bills a fresh one — so a re-lock can **never double-bill**
- Result: multiple historical anchor Charge rows (all inactive) + exactly ONE live (non-cancelled) overage invoice

Test: `PercentageRentScenarioTest::re-locking a voided declaration voids the old overage invoice and bills exactly one fresh one`, `SalesDeclarationLockGuardTest::never leaves two live overage invoices when a declaration is re-locked`

## 5. Services, jobs & scheduled commands

### `PercentageRentCalculationService`
File: `app/Services/PercentageRentCalculationService.php`

**`calculate(TenantSalesDeclaration): float`**
- Pure read: returns the owed amount given the lease's config and declared sales
- No side effects; no DB mutation
- Applies the formula (artificial or natural)
- Returns 0.0 if lease has no percentage rent
- Decimal precision: 2dp, floored to 0

**`recalculate(TenantSalesDeclaration): TenantSalesDeclaration`**
- Recalculates `calculated_percentage_rent` and persists to DB
- Does NOT lock or create a Charge
- Used during Portal submission and Admin edit to show the tenant the live estimate
- Returns the refreshed model

**`lock(TenantSalesDeclaration, User $lockedBy, ?string $auditNotes): TenantSalesDeclaration`**
- **Idempotent**: Calling lock on an already-locked record is a no-op (returns the record unchanged)
- Guard: Short-circuits if `status === 'locked'`
- Transaction: Wrapped in `DB::transaction()`
- Steps:
  1. Recalculate the owed amount
  2. Update: `status='locked'`, `locked_at=now()`, `locked_by_user_id`, `audit_notes`
  3. Re-lock safety: `reverseOverage()` — reverse any prior overage for this lease+period (deactivate anchor + cancel its immediate invoice) before billing, so a re-lock can never double-bill
  4. If owed > 0, `billOverageImmediately()` — create the inactive anchor Charge + the issued Invoice + the `percentage_rent` InvoiceItem
  5. Fire `SalesDeclarationLockedNotification` to the lease's tenant (wraps in try–catch; logs warning if it fails)
  6. Refresh and return
- Notification: "Your sales declaration for [period] has been locked at [amount] EGP"

**`voidLocked(TenantSalesDeclaration, User $voidedBy, string $reason): TenantSalesDeclaration`**
- **Idempotent**: Calling voidLocked on a non-locked record is a no-op
- Guard: Short-circuits if `status !== 'locked'`
- Transaction: Wrapped in `DB::transaction()`
- Steps:
  1. `reverseOverage()` — find the period-specific anchor Charge(s) `where('type','percentage_rent') and whereDate('start_date', period_start)`; deactivate each (`is_active=false`, `end_date=now()`) and cancel its immediate invoice via `VoidInvoiceService` (unless already cancelled/credited)
  2. **A PAID overage invoice throws `DomainException`** — the transaction rolls back and the void is refused (refund the invoice first)
  3. Append audit note: "Voided on [date] by [user]: [reason]"
  4. Update: `status='disputed'`
  5. Refresh and return
- No notification to tenant (this is internal dispute resolution)

---

### `CreateSalesDeclarationAction` (API)
File: `app/Actions/Api/V1/Sales/CreateSalesDeclarationAction.php`

**`handle(Tenant $tenant, array $data, array $attachments = []): TenantSalesDeclaration`**
- Invoked by the mobile API `POST /me/sales-declarations` (multipart)
- Input: `lease_id`, `period_start`, `period_end`, plus `attachments` (1–5 image/PDF files, required at the request layer)
- Validation:
  - Lease must belong to the tenant
  - Lease must have `has_percentage_rent=true`
  - No duplicate (lease, period_start) pair
- Steps:
  1. Create the declaration with `status='submitted'`, `declared_at=now()`, `declared_by={Tenant}`, **`declared_sales=null`**
  2. Push each uploaded file into the `sales_report` media collection (after the row saves — media moves files on disk)
- Returns the saved declaration with `lease` + `media` loaded
- Does **not** calculate anything (no figure yet) and does **not** lock — the operator enters `declared_sales` and locks in the admin panel
- Note: the mobile API path does not fan out the submitted-notification; the **Portal** create page (`CreateTenantSalesDeclaration`) fires `SalesDeclarationSubmittedNotification` to managers + leasing users on the asset

---

## 6. Filament resources & key fields

### Admin Resource
File: `app/Filament/Admin/Resources/TenantSalesDeclarations/TenantSalesDeclarationResource.php`

**Routes:**
- Index: List all declarations (with property/asset scoping via `ScopesViaProperty`)
- Create: Admin can manually create a declaration for any lease on their asset
- Edit: Admin can edit details of submitted declarations; recalculation runs on save if not locked

**Key form fields:**
| Field | Type | Validation | Scoped | Notes |
|-------|------|-----------|--------|-------|
| `lease_id` | Select (searchable) | Required; filtered to `status='active'` leases on the asset | Yes (TenantScope) | Shows "reference — tenant (unit)" |
| `period_start` | Date | Required; unique per lease | No | Defaults to first of previous month |
| `period_end` | Date | Required | No | Defaults to last of previous month |
| `sales_report` | SpatieMediaLibraryFileUpload | Optional (admin), image/PDF, ≤5 files | No | The tenant's uploaded report — review it, download/open, then enter the figure |
| `declared_sales` | Decimal | **Optional**, ≥0 | No | In EGP, step 0.01. Staff read this off the uploaded report; Lock with no figure owes 0 |
| `calculated_percentage_rent` | Decimal | Disabled (read-only) | No | Auto-calculated on save; shown for reference |
| `status` | Select | Required, default 'submitted' | No | Options: submitted, locked, disputed |
| `audit_notes` | Textarea (3 rows) | Optional | No | For admin reasoning |

**Table columns:**
- Tenant name, Unit code (badge), Period (MMM YYYY), Declared Sales, Calculated Rent, Status (badge with color), Declared At, Locked At (toggleable)
- Navigation badge: Count of submitted declarations awaiting review
- Default sort: `period_start` DESC

**Record actions:**
- **Lock** (visible if `status='submitted'`)
  - Confirmation modal with optional `audit_notes` field
  - Calls `PercentageRentCalculationService::lock()`
  - Shows success toast with final amount
  - Permission: `tenant_sales.lock`

- **Dispute** (visible if `status='submitted'`)
  - Confirmation modal with required `audit_notes` field
  - Calls `update(['status'=>'disputed', 'audit_notes'=>...])`
  - Permission: `tenant_sales.dispute`

- **Void Locked** (visible if `status='locked'`)
  - Heading: "Void locked declaration"
  - Description: Warns this will reverse the overage (cancel its invoice)
  - Required `reason` field
  - Calls `PercentageRentCalculationService::voidLocked()`, wrapped in a `try/catch (DomainException)`
  - Warning notification on success; **persistent danger notification** if the overage invoice was already paid (void refused — refund first)
  - Permission: `tenant_sales.lock` (implicit, same as lock)

- **Edit** (if not locked)
- **Delete** (soft-delete only)

**Permissions (from RolesPermissionsSeeder):**
```php
'tenant_sales' => [
    'tenant_sales.view'    => 'View tenant sales declarations',
    'tenant_sales.create'  => 'Create tenant sales declarations',
    'tenant_sales.edit'    => 'Edit tenant sales declarations',
    'tenant_sales.delete'  => 'Delete tenant sales declarations',
    'tenant_sales.lock'    => 'Lock a declaration + generate percentage-rent charge',
    'tenant_sales.dispute' => 'Mark a declaration as disputed',
],
```

Manager role includes: `view`, `lock`, `dispute`

---

### Portal Resource
File: `app/Filament/Portal/Resources/TenantSalesDeclarations/TenantSalesDeclarationResource.php`

**Routes:**
- Index: List all declarations for the current tenant
- Create: Tenant submits a new declaration
- View: Tenant views a submitted declaration (read-only)

**Constraints:**
- `canCreate()` = true only if `Portal::isAdmin()` (tenant-admin can submit; other tenant users are read-only)
- `canEdit()` = false (no edits once submitted)
- `canDelete()` = false (no deletion)

**Key form fields (Create):**
| Field | Type | Validation | Notes |
|-------|------|-----------|-------|
| `lease_id` | Select (searchable) | Required; filtered to tenant's own active leases with `has_percentage_rent=true` | "reference — unit code" |
| Period info | Placeholder | (informational) | Shows "MMMM YYYY" of previous month |
| `period_start` | Date | Required; unique per lease | Defaults to first of previous month |
| `period_end` | Date | Required | Defaults to last of previous month |
| `sales_report` | SpatieMediaLibraryFileUpload | **Required**, image/PDF, ≤5 files, full width | The tenant uploads their sales report here instead of typing a figure |

**View page (infolist):**
- Lease reference, Unit code, Period, Declared Sales, Calculated Rent (color: green if >0), Status (badge), Declared At, Locked At

**Table columns (Index):**
- Unit code (badge), Period, Declared Sales, Calculated Rent (green if >0), Status (badge), Declared At
- Default sort: `period_start` DESC
- Record action: **View** only

---

## 7. Notifications & integrations

### `SalesDeclarationSubmittedNotification`
File: `app/Notifications/SalesDeclarationSubmittedNotification.php`

**Triggered:** When a tenant submits a declaration via Portal create form
**Recipients:** All managers + leasing users assigned to the lease's asset (via `AssetStaffRecipients`)
**Channels:** Database only (no email; high frequency at scale)
**Payload:**
```json
{
  "type": "sales_declaration_submitted",
  "tenant": "...",
  "unit": "...",
  "period": "Jan 2026",
  "declared_sales": 150000,
  "title": "Sales declaration received",
  "icon": "heroicon-o-presentation-chart-line",
  "color": "warning"
}
```
**UI:** Appears as a bell notification in Filament admin (persistent).

---

### `SalesDeclarationLockedNotification`
File: `app/Notifications/SalesDeclarationLockedNotification.php`

**Triggered:** When admin locks a declaration (via `PercentageRentCalculationService::lock()`)
**Recipients:** The lease's tenant (via `$tenant->notifyPortal()`)
**Channels:** Mail + Database
**Email subject:** "Your sales declaration for [period] has been locked"
**Email body:** "Your declared sales for [period] resulted in EGP [amount] in percentage rent. This will be billed in the next monthly cycle."
**Database payload:**
```json
{
  "type": "sales_declaration_locked",
  "period": "Jan 2026",
  "amount": 2500,
  "title": "Sales declaration locked",
  "icon": "heroicon-o-lock-closed",
  "color": "warning"
}
```
**UI:** Appears as persistent bell notification in Portal (tenant sees it).

---

### Error handling
- `SalesDeclarationSubmittedNotification` fan-out failures are caught and logged (warning level); the declaration is saved regardless
- `SalesDeclarationLockedNotification` failures are caught and logged (warning level); the declaration is locked regardless
- Rationale: Notifications are user-facing niceties; core billing state must persist

---

## 8. Extension points — how to change/extend SAFELY

### Adding a new calculation type

**To add a 3rd formula (e.g., 'tiered_rate'):**

1. **Update the migration** to add the new enum value:
   ```php
   // database/migrations/YYYY_MM_DD_extend_percentage_rent_calculation.php
   $table->enum('percentage_rent_calculation_type', [
       'natural_breakpoint', 'artificial', 'tiered_rate'
   ])->nullable()->change();
   ```

2. **Extend `PercentageRentCalculationService::calculate()`:**
   ```php
   if ($type === 'tiered_rate') {
       // Implement your logic
       $owed = calculateTiered($sales, $leaseConfig);
   }
   ```

3. **Add tests** in `tests/Feature/Services/PercentageRentCalculationServiceTest.php` to cover:
   - The formula at boundaries (below, at, above breakpoints)
   - Flooring to zero
   - Rounding to 2dp

4. **Do NOT**:
   - Modify the Charge-creation logic in `lock()` — it is generic
   - Change the `declared_sales` input validation — that's lease-agnostic
   - Add new columns to `tenant_sales_declarations` table

---

### Adding a new status or state transition

**To add a new status (e.g., 'auditing'):**

1. **Update the migration:**
   ```php
   $table->enum('status', ['submitted', 'locked', 'disputed', 'auditing'])->default('submitted')->change();
   ```

2. **Update the Model constant:**
   ```php
   // app/Models/TenantSalesDeclaration.php
   public const STATUSES = ['submitted', 'locked', 'disputed', 'auditing'];
   ```

3. **Add translations** for the new status badge in `resources/lang/en/admin.php`:
   ```php
   'statuses' => [
       'tenant_sales' => [
           'auditing' => 'Under Audit',
       ],
   ],
   ```

4. **Add a Filament action** in `TenantSalesDeclarationsTable` to trigger the transition:
   ```php
   Action::make('audit')
       ->visible(fn ($record) => $record->status === 'submitted')
       ->action(fn ($record) => $record->update(['status' => 'auditing']))
   ```

5. **Add tests** to ensure the new transition doesn't break charge creation or notification logic.

---

### Changing the billing logic

**To modify what gets billed (e.g., applying a discount, adding surcharges):**

1. Do NOT mutate the declared amount itself—keep `declared_sales` immutable.

2. Modify the billing inside `PercentageRentCalculationService::billOverageImmediately()` — it creates the inactive anchor Charge, the issued Invoice, and the `percentage_rent` InvoiceItem:
   ```php
   private function billOverageImmediately(TenantSalesDeclaration $declaration, float $amount): Charge
   {
       $finalAmount = $amount; // Apply discount/markup here if needed
       // ... anchor Charge (is_active=false) + Invoice(total=$finalAmount) + InvoiceItem
   }
   ```

3. Update `calculate()` to return the **before-discount** figure (so admin sees the raw owed amount), then adjust in `billOverageImmediately()`.

4. Add a test to verify the final billed amount matches your logic (assert the **invoice** total, not a charge amount).

5. Do NOT:
   - Bill via an **active** monthly Charge — a one_time charge dated to the past sales month is never billed by the monthly run (the billing gap). Keep the immediate-invoice path; the anchor Charge must stay `is_active=false`.
   - Change the anchor Charge's `type`, `frequency`, `vat_applicable`, or `start_date=period_start` — the last is the void/re-lock identity key
   - Leave more than one **live** overage invoice per declaration — `reverseOverage()` (called by both `lock()` and `voidLocked()`) enforces this; manual changes must respect the invariant
   - Bill VAT on the overage — percentage rent, like base rent, is VAT-exempt

---

### Extending to other charge types

**To reuse this "lock then charge" pattern for other declaration types (e.g., service charge reconciliation):**

1. Create a new model `ServiceChargeDeclaration` with the same fields as `TenantSalesDeclaration`
2. Create `ServiceChargeCalculationService` modeled on `PercentageRentCalculationService`
3. Duplicate the Filament resources (admin + portal) and update filters/labels
4. Duplicate the notifications and update copy
5. Add new permissions in `RolesPermissionsSeeder`
6. Add comprehensive tests for each calculation type

Do NOT:
- Reuse `TenantSalesDeclaration` table for other purposes — it has sales-specific semantics
- Share the same Filament resource — scoping and permissions differ

---

## 9. Gotchas, edge cases & recently-fixed bugs

### The billing gap — locked overage was never billed (2026-07)

**The bug:** `lock()` created an **active** one_time `percentage_rent` Charge dated to the (past) sales month and relied on the monthly billing run to bill it. But the monthly engine only bills charges whose period **overlaps the run month**, so a charge dated to a bygone month was silently skipped forever — the locked overage never reached an invoice (revenue leak).

**The fix:** `lock()` now bills the overage **immediately** as its own issued invoice (mirroring the CAM positive true-up, `billChargeImmediately`). The Charge is kept only as an **inactive** anchor (`is_active=false`) for traceability + as the void/re-lock identity key; the money lives on the invoice. `voidLocked()`/re-lock reverse it by cancelling that invoice; a **paid** overage invoice blocks the void until refunded. See §3 "The billing gap" for the full invariant.

**To avoid:** never bill a percentage-rent overage through the monthly run — its charge date is always in the past. Keep the anchor inactive and bill immediately.

Tests: `PercentageRentImmediateBillingTest`, `PercentageRentScenarioTest`, `PercentageRentVoidLockedTest`.

---

### Null `percentage_rent_calculation_type`

**The bug:** When renewing a lease with `percentage_rent_calculation_type='artificial'`, the renewal omitted this field, so it reverted to null (which then defaulted to 'artificial' at calculation time, but persisted null in the DB—subtle inconsistency).

**The fix:** `LeaseRenewalService::renew()` now carries every percentage-rent field forward explicitly.

**To avoid:** When copying lease config (renewal, template cloning, etc.), always include `percentage_rent_calculation_type` in the create payload.

Test: `RenewalPercentageRentTypeTest::carries percentage-rent fields (including calculation_type) into the renewal`

---

### Void vs. Dispute

**Void (voidLocked)** is for locked declarations that turn out wrong post-lock (e.g., "the tenant's accounting was miscalculated"). The charge is deactivated, and the status flips to 'disputed' for audit.

**Dispute** (action on submitted declarations) is for flagging suspicious figures *before* lock. No charge is created; it's a flag.

They both land on status='disputed', but the charging history differs. Always check `locked_at` to know if a charge was ever created.

---

### Charge period matching in voidLocked

**The bug:** If a tenant declared two periods in quick succession and one was locked, then voided, the voidLocked logic needs to deactivate *only* the matching period's charge.

**The fix:** `voidLocked()` matches charges by `(lease_id, type='percentage_rent', start_date=period_start)`. The `start_date` is the discriminator.

**To avoid:** When querying charges for a lease, always filter by date range if multiple periods are involved. The test `PercentageRentVoidLockedTest::voidLocked only deactivates the period-specific Charge, leaving sibling-period charges alone` guards this.

---

### Idempotency of lock

**Pattern:** `lock()` short-circuits if already locked, returning the record unchanged. This is safe because:
1. The status check is first
2. No charge is re-created (you'd add multiple rows otherwise)
3. The tenant gets exactly one notification (the first lock fires it; re-calling lock doesn't)

**Assumption:** The caller does NOT re-calculate before re-locking. If `declared_sales` changed, call `recalculate()` first, then `lock()`. But the normal workflow is: submit → admin verifies → lock (once). Re-locking is rare.

Test: `PercentageRentCalculationServiceTest::locking is idempotent`

---

### Zero-owed declarations still lock

**Pattern:** If a tenant is under-threshold, `calculated_percentage_rent=0`, but `lock()` still sets `status='locked'` and `locked_at`. No Charge is created (because `> 0` check), but the declaration is finalized.

**Why:** Even a zero amount is an audit result (tenant reported, amount verified as under-threshold). The lock documents this. The tenant still gets notified (mail says "amount: EGP 0.00").

Test: `PercentageRentScenarioTest::has_percentage_rent=false: lock is harmless, never charges`

---

### Lease without percentage rent can still be declared

**Guard:** The API `CreateSalesDeclarationAction` checks `has_percentage_rent=true`. The Portal form filters leases to only those with `has_percentage_rent=true`.

**But** the Model and Filament do NOT prevent creating a declaration for a non-pct-rent lease if you:
- Use the Admin "Create" button on a lease without `has_percentage_rent`
- Or call the model directly

If you do, the service will calculate 0.0 and never charge, but the record exists. This is safe (it's just audit noise), but bad UX. Always enforce the API guard or Portal filter.

Test: `PercentageRentCalculationServiceTest::returns zero when the lease has no percentage rent`

---

### Fractional rates and rounding

**Pattern:** Rates like 2.5% (stored as 2.5 in a decimal(5,2) column) must round the owed amount to exactly 2dp. PHP's `round()` function with half-up rounding is used.

**Edge case:** 2.5% of 33,333 = 833.325 → `round(833.325, 2) = 833.33` (banker's rounding in some configs). The test uses `toBe(833.33)` to force exact comparison.

Test: `PercentageRentScenarioTest::fractional-rate rounding to 2dp`

---

### Notification failures don't block locking

**Pattern:** Both `SalesDeclarationSubmittedNotification` (Portal create) and `SalesDeclarationLockedNotification` (lock action) wrap notification sending in try–catch and log warnings if they fail.

**Why:** Notifications are user-facing; the core business (the declaration, the charge) must persist even if email is down or notification DB is overloaded.

**To avoid:** Check logs for notification failures (search for "declaration_locked notification failed"). If a tenant never got the email, resend manually or add to your monitoring dashboard.

---

## 10. Tests & related modules

### Test files

**Core calculation & state:**
- `/tests/Feature/Services/PercentageRentCalculationServiceTest.php` — formulas, floors, locking, recalculation, idempotency
- `/tests/Feature/Services/PercentageRentVoidLockedTest.php` — void behavior, audit notes, period scoping, idempotency of void
- `/tests/Feature/Scenarios/PercentageRentScenarioTest.php` — boundaries, charge attributes, rounding, re-lock after void, state transitions

**Integration:**
- `/tests/Feature/Notifications/AdminTriageNotificationsTest.php` — submitted notification to managers/leasing users
- `/tests/Feature/Notifications/MaintenanceAndSalesNotificationsTest.php` — locked notification to tenant
- `/tests/Feature/Regression/RenewalPercentageRentTypeTest.php` — lease renewal carries calculation_type forward

**API (if tested):**
- `/tests/Feature/Api/V1/SalesDeclarationsControllerTest.php` (if it exists) — tenant portal API submission

### Total test count
~30–40 test cases covering calculation, state, notifications, idempotency, edge cases, and regression scenarios.

---

### Related modules

- **[Leases](./06-leases.md)** — `has_percentage_rent`, rates, thresholds, base rent; renewal must preserve percentage-rent config
- **[Charges](./05-billing-charges.md)** (if documented) — the percentage-rent Charge is a one-off row; billing run must handle its `is_active` flag
- **[Tenant Portal Users](./03-tenant-portal-users.md)** — portal auth scoping; only tenant-admins can submit declarations
- **[Notifications](./02-notifications.md)** (if documented) — `SalesDeclarationSubmittedNotification`, `SalesDeclarationLockedNotification` delivery channels, retry logic
- **[Billing & Invoicing](./04-billing-cycle.md)** (if documented) — percentage-rent Charges must flow into the next billing cycle

---

**Author:** Auto-generated documentation
**Last updated:** 2026-07-10 (file-first submission: tenants upload a sales report; staff enter the figure)
**Confidence:** High (test suite green; code reviewed)


---

## 11. Close-out (2026-07-22) — what changed

The property+facility close-out ([gap-analysis](../gap-analysis/PROPERTY-FACILITY-CLOSURE.md)); plain-language business model: [business-model/09](../business-model/09-tenant-sales-percentage-rent.md). The core (immediate-overage billing, void/re-lock, file-first submission) was already shipped & correct — these are the layer around it.

### Authz double-gate (was: dispatch hole)
`lock` / `dispute` / `voidLocked` (`TenantSalesDeclarationsTable`) gated permission + status only in `visible()`. `mountAction()` never checks `isVisible()`, and seeded `viewer` + `owner` hold `tenant_sales.view` (the list renders), so a read-only auditor or owner could Lock (bill an overage invoice + post GL), Dispute, or Void a locked declaration via a crafted call. Now each action re-asserts a **named predicate** — `canLock` / `canDispute` / `canVoid` (permission **and** status) — in **both** `visible()` and `action()` (`abort_unless`). Tested via `mountAction`+`callMountedAction` in `SalesDeclarationActionAuthzTest` (the prior `assertTableActionHidden` test checked only `visible()` and false-passed).

### Non-reporting scan + dashboard card
A percentage-rent tenant who never uploads a report has no declaration → the overage never bills and nothing alerts (the reporting-layer twin of the closed billing gap). `sales:scan-missing-declarations` (`ScanMissingSalesDeclarationsCommand`, scheduled monthly on the 10th) reminds every active `has_percentage_rent` lease that was billable in the closed month (commenced, past `firstBillableMonth()` fit-out grace) and has no declaration for it (`whereDoesntHave('salesDeclarations', period_start=prevMonth)`). Idempotent: `SalesDeclarationReminderNotification` carries `period_key` (YYYY-MM) + `lease_id`, and the scan skips a lease already reminded for that period — so re-runs never re-nag. The `ActionRequired` **"missing sales declarations"** card surfaces the same set live (property-scoped via `visibleAssetIds()`), so the leak is never silent again.

### GL real-sweep tie-out
`PercentageRentGlTieOutTest` drives the real service + `accounting:sync-ledger` and asserts the overage posts Dr AR / Cr `percentage_rent_revenue` (41105001) balanced + tied, and that `voidLocked` reverses it — satisfying the GL invariant ("at least one test per money source must drive the real service + the sweep").

### Deferred (with triggers)
**Annual / cumulative reconciliation** (the biggest gap — Atriom bills per-month against a monthly breakpoint, which over-bills a seasonal tenant; the operator confirmed all current leases use monthly breakpoints — *trigger: the first lease with an annual/cumulative breakpoint*) · structured sales-basis / exclusions · estimated/deemed-sales billing on chronic non-reporting · a tenant-facing % rent statement PDF. The stale "billed next monthly cycle" notification copy was corrected (it's immediate).
