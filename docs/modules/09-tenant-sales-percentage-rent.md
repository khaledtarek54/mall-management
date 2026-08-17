# Tenant Sales Declarations & Percentage Rent

> Tenants on percentage-rent leases submit periodic sales declarations by **uploading their sales report file**; the operator reviews the file, enters the sales figure, and locks the declaration — which **bills the percentage-rent overage immediately as its own invoice**.

> **⚠️ A SHORT percentage-rent year now gets a SHORT breakpoint (2026-08-16).**
> An annual breakpoint is a whole year's figure. Applied unchanged to a year the lease only traded
> part of, it is unreachable: a lease commencing 1 October carried a 12,000,000 breakpoint against
> **three months** of trading, owed no percentage rent at all in its first year, and the clock then
> reset on 1 January. A straight under-bill of the landlord's share, and a silent one — the tenant
> never crosses a line nobody is looking at.
>
> **Decided against the market rule, not invented:** the standard treatment of a short percentage-rent
> year is to pro-rate the sales breakpoint, and the **natural** breakpoint proves it must be — it is
> *defined* as annual base rent ÷ rate, so a tenant occupying three months, who pays three months of
> base rent, reaches it on a quarter of the sales.
>
> Applied by **annualising the sales** rather than scaling each breakpoint —
> `overage_short(S) = f × overage(S ÷ f)` — which is identical arithmetic for artificial and natural
> and is the only form that also works for a **tiered** ladder, which has no single breakpoint to
> scale. One rule at the existing `overage()` choke point, so the cumulative-marginal arithmetic and
> `retrueAnnualYear()` are untouched. `explain()` reports the pro-rated figure, because a screen
> showing 12,000,000 beside a charge computed against 3,000,000 is how a correct invoice comes to look
> like a mistake — and how a wrong one escapes notice.
>
> **Two deviations stated plainly.** Pro-rated by whole **months**, not days (the commoner legal
> wording): sales are declared per month, so a lease commencing on the 20th still files a full October
> declaration, and a day-share breakpoint would measure one grain against another. And the year stays
> the **calendar** year — whether a clause runs the percentage-rent year from the lease anniversary is
> a contract question needing a per-lease setting, and with proration the two readings now agree
> everywhere except the boundary month. Pinned by `PercentageRentShortYearTest`.
>
> **⚠️ SHIPPED 2026-08-17 — billing frequency is now its own lease term.** `percentage_rent_frequency`
> is the calculation BASIS; the new `percentage_rent_billing_frequency` is WHEN the overage is
> charged — **monthly / quarterly / annual, always in arrears**. Billing had not been modelled at all:
> the overage was invoiced the moment a declaration was locked, so every tenancy charged monthly
> whatever its contract said, and a clause reading *"percentage rent payable quarterly in arrears"*
> could not be expressed. Yardi carries the two separately (plus reporting frequency, a third), and
> the benchmark says it in bold: *a system that assumes they are the same cannot express the most
> common retail deal.*
>
> **The split:** the basis decides what each month OWES (`calculated_percentage_rent`, unchanged); the
> new `settleBillingPeriods()` decides when — and how many months share — the invoice. Both bases feed
> it, so a lease can be cumulative-annual in its arithmetic and quarterly in its billing, which is a
> real and common combination.
>
> **In arrears means in arrears.** A period is raised only once **every month of it that the lease
> traded** has been locked — a quarter cannot be settled while a month of it is unknown. A part-traded
> quarter settles on the months the lease actually traded, so a November commencement does not wait
> for an October it never had. The invoice is anchored on the period's FIRST locked month, which is
> what `reverseOverage()` keys on, so voiding any month of a settled period reverses that period's one
> invoice and either re-raises it at the new total or leaves the period un-settled.
>
> **The operational hazard, stated:** with a non-monthly cadence, one missing declaration holds the
> whole period. `sales:scan-missing-declarations` chases it and `sales:estimate-missing` can fill it;
> neither is automatic. **Default is `monthly`, so no existing lease moved** — all 78 percentage-rent
> cases passed unchanged. Recommended default for Egypt: keep monthly even on an annual basis. Billing
> annually means discovering a large receivable eleven months after it was earned, from a tenant who
> may no longer be trading. Pinned by `PercentageRentBillingFrequencyTest`.
>
> **STILL OPEN — the third frequency.** Yardi also carries a *reporting* frequency (when the tenant
> must declare). Atriom assumes monthly declarations throughout. Yardi carries three
> separate settings (`docs/benchmarks/yardi/03`): *reporting* frequency (when the tenant declares),
> *billing* frequency (when overage is charged — monthly, quarterly, or **annually in arrears**), and
> the *calculation basis* (period vs cumulative). Atriom's `percentage_rent_frequency` is the basis
> only, and billing is implicitly "on lock", i.e. monthly. A lease reading *"percentage rent payable
> quarterly in arrears"* cannot be expressed. The benchmark calls this out in bold: *a system that
> assumes they are the same cannot express the most common retail deal.*

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

3. **Add translations** for the new status badge in `lang/en/admin/statuses.php`:
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

### Annual / cumulative percentage rent (built 2026-07-22)
Originally deferred; the operator opted in after confirming annual-breakpoint leases exist / are being signed. A lease now carries **`percentage_rent_frequency`** (`monthly` — the default, unchanged behaviour — or `annual`). For an annual lease the `percentage_rent_threshold` (and, for a natural breakpoint, `base_rent_monthly × 12`) is a **yearly** figure, and each month carries its **canonical chronological marginal** contribution to the year's cumulative overage — `overage(cumulative sales through this month) − overage(through the previous month)`, each floored at 0:

- `PercentageRentCalculationService::calculate()` branches on frequency; annual returns this chronological marginal (`priorLockedSalesYtd` = locked months *before* this one, + this month's sales). It is the **display estimate**. Deterministic (independent of lock order), always ≥ 0 (a seasonal spike is netted against slow months, never over-charged), and the months' marginals sum to `overage(total year sales)`.
- **Whole-year re-true (the correctness core).** In the cumulative model *which* month's invoice carries the overage is an artifact of lock order, so locking/voiding/re-locking one month shifts the cumulative the **other** months were sized against. Every annual `lock`/`voidLocked` therefore calls **`retrueAnnualYear`** inside the txn: it walks the year's locked months chronologically and reconciles each one's live `percentage_rent` invoice to its recomputed marginal (skip if unchanged; else reverse + rebill). So the live invoices **always sum to `overage(cumulative)`** — voiding a month re-trues the survivors (no stranded over-bill), and a down-revision is fixed simply by re-locking the *changed* month (the operator needn't know which month holds the invoice). If a reconciliation must reverse an invoice that has been **PAID**, `VoidInvoiceService` throws and the whole operation rolls back / is refused until it's refunded — the same guard the single-period path has. The billing path (`billOverageImmediately` → `percentage_rent` item → `percentage_rent_revenue`) and `reverseOverage` are unchanged, so the GL posting is identical to the monthly path.
- **Concurrency:** because the year's re-true reads/adjusts the lease's *other* declarations + invoices, two concurrent locks of *different* months of the *same* lease must not interleave. Every annual `lock`/`voidLocked` runs inside a **per-lease mutex** (`runSerializedPerLease` → `Cache::lock('pct-rent:lock:lease:{id}')`, the single-lease-billing-lock pattern); monthly leases are independent per month and skip it. The tenant "locked" notification is sent **after** the transaction commits (out of the txn, so a slow channel can't extend it past the lock TTL). Carried forward on renewal (`LeaseRenewalService`). **Interpretation: calendar-year cumulative** (Jan–Dec); a lease-anniversary % rent year is a future refinement (see Deferred). Tests: `PercentageRentAnnualCumulativeTest` (crossing month, seasonal-vs-monthly contrast, out-of-order deterministic attribution, monthly parity, void re-true, down-revision re-true, natural breakpoint, monthly-not-gated) + `PercentageRentGlTieOutTest` (annual telescoping through the real sweep).

### Annual % rent — operator/tenant UX (built 2026-07-22)
A bare annual figure is unverifiable (it's a share of a running yearly total), so the number is now explainable end-to-end. `PercentageRentCalculationService::explain()` returns the plain-language working (frequency, breakpoint, declared/prior/cumulative YTD sales, YTD overage, this-period share, `is_estimate`); `yearAttribution()` returns how the year's overage is currently spread across locked months. Surfaced as: **(1)** a reactive `LeaseForm` — the threshold's label/helper switch to "Annual Threshold (whole year)" and a soft warning fires if the figure looks monthly (below one month's base rent); the threshold is hidden for a natural breakpoint (unused there). **(2)** an "annual · cumulative" marker on the declarations table + a **"View working"** modal built from **native Filament infolist `TextEntry` entries** (`workingSchema()` — not a Blade view) showing the full breakdown. **(3)** the lock/void success toast shows the re-trued **year attribution** (which months carry what + the total), so the re-attribution isn't silent. **(4)** the tenant's `SalesDeclarationLockedNotification` email spells out the cumulative-YTD-vs-breakpoint context for annual leases. Tests: `PercentageRentAnnualUxTest`.

### Deferred (with triggers)
- **Non-calendar % rent year + stub-year proration** — the annual cumulative is grouped by **Gregorian calendar year** (`whereYear`), and the breakpoint (and natural `base_rent × 12`) is a full-year figure. A lease whose % rent year runs on a **lease anniversary** (e.g. Apr→Mar) or a **fiscal** mall year would reset mid-year and mis-true; a lease **commencing/exiting mid-year** gets the full annual breakpoint against a partial year → under-bills the stub year. *Trigger: the first annual lease with a non-calendar % rent year, or one where the operator expects a prorated stub-year breakpoint.*
- Structured sales-basis / exclusions · estimated/deemed-sales billing on chronic non-reporting · a tenant-facing % rent statement PDF. The stale "billed next monthly cycle" notification copy was corrected (it's immediate).

---

## Correction — the `visible()` dispatch premise (2026-07-31)

**Correction 2026-07-31 — the "still dispatchable" half of this was WRONG on the version we ship.** `mountAction()` does check `isDisabled()`, and `CanBeDisabled::isDisabled()` returns true when `isHidden()` does (`vendor/filament/actions/src/Concerns/CanBeDisabled.php:24`), so on **Filament v4.11.8 a `visible()`-only action IS refused at dispatch**. Found by mutation-testing the module-08 fix: deleting CAM's `abort_unless` left `CamActionAuthzTest` fully green — those tests never exercised the gate they describe. **Double-gate anyway**, for a reason that survives the correction: `->authorize()` is a stated intent, while hidden-implies-disabled is an upstream implementation detail that can change in a release and would silently reopen every `visible()`-only write at once. `FilamentActionDispatchContractTest` pins that upstream behaviour so an upgrade that changes it turns the build red; `ActionAuthzConformanceTest` enforces the layer we control.

---

## Tiers, deductions and estimated sales (2026-08-09)

Three capabilities Yardi has that Atriom did not. Each was **verified absent against the code**
before being built — the same benchmark had already produced one false gap by trusting a stale
line in this very document.

### Tiered breakpoints (PR-02)

`percentage_rent_calculation_type = 'tiered'` plus a per-lease ladder
([`LeasePercentageRentTier`](../../app/Models/LeasePercentageRentTier.php)). Yardi's own example:
0–500K at 0%, 500K–900K at 5%, above 900K at 6%. **The 0% first band IS the breakpoint**, which is
why a ladder subsumes the single-threshold model instead of sitting beside it.

**Each band charges only the sales within it.** A tenant at 1,000,000 owes `400,000 × 5% +
100,000 × 6% = 26,000` — not `1,000,000 × 6% = 60,000`. Charging the top rate on the whole figure
overcharges every large tenant, which is why the arithmetic lives in exactly one method,
`LeasePercentageRentTier::overageFor()`, and is mutation-verified.

**Overlapping bands are refused at write time** (`LeasePercentageRentTier::saving()`). A floor typed
as 400,000 instead of 500,000 puts the 400–500K slice in two bands and bills 31,000 where 26,000 is
owed — silently, every month, for as long as the lease runs. **Gaps are deliberately allowed**: a
gap is semantically identical to a 0%-rate band, "no percentage rent between X and Y" is a real deal
shape, and a gap is the natural intermediate state while a ladder is being typed in.

**Where it was inserted matters:** at `PercentageRentCalculationService::overage()`, the single
choke point. The monthly basis and the annual cumulative-marginal basis are both expressed in terms
of `overage()`, so tiers compose with `retrueAnnualYear()` without either knowing about the other.

### Deductions / offsets (PR-03)

`leases.percentage_rent_deductible_types` (JSON list of invoice-item types) implements the common
clause *"percentage rent is payable to the extent it exceeds CAM and real-estate tax paid in the
same period"*.

- Applied **after** the basis produces its gross figure, so it cannot perturb the cumulative
  marginals that must keep summing to the year's overage.
- Reads the **invoiced** amounts for the period, not the lease's configured monthly figures —
  those diverge the moment a month is prorated, abated or re-billed.
- **Cancelled, draft and written-off invoices are excluded**: a reversed charge was never paid, and
  crediting it would hand the tenant a deduction for money they never spent.
- **Floored at zero.** "Payable to the extent it exceeds X" owes nothing when it does not exceed X;
  it does not become a refund of the tenant's own service charge.
- **Both paths net, and until 2026-08-11 only one did.** `calculate()` — which feeds the declaration
  figure, the "View working" breakdown and the estimate — applied the deduction. `retrueAnnualYear()`
  built its marginal straight from `overage()` and never called `netOfDeductions()`, **and that is
  the path that bills**. So an `annual` lease with a deductible-types clause was charged GROSS while
  every screen showed net, with no discrepancy visible anywhere to catch it. The two are now the
  same arithmetic; `AnnualPercentageRentDeductionsTest` asserts the billed figure equals the shown
  one, and its no-clause control stops the netting being achieved by simply charging less.

> **The shape to watch here:** annual percentage rent has *two* implementations of "what does this
> month owe" — the display estimate in `calculate()` and the authoritative one in
> `retrueAnnualYear()`. That is deliberate (the re-true has to re-attribute the whole year), but it
> means **every clause that modifies the amount owed has to be applied twice**, and the one that
> bills is the one nobody looks at. Deductions were the first such clause; the next one added must
> go into both.

### Estimated sales (PR-04)

`sales:estimate-missing` (monthly, the 8th — a week after the chase) raises an **estimated**
declaration for a percentage-rent tenant who never filed. Until this existed, silence was a
complete and costless way to avoid percentage rent: the scan chased and nothing billed.

- The estimate is the tenant's **own trailing average** (last three locked declarations) — 
  defensible to them, and self-correcting as they trade.
- **Refuses to invent a number** for a tenant with no history; the reminder scan keeps chasing
  instead. Same rule that stops the escalation sweep guessing a CPI figure.
- Marked `is_estimate`, and **not locked**: an estimate is a prompt for a decision, not a fact, so
  it passes the same operator review gate as every other percentage-rent charge.
- Never overwrites a real declaration — re-checked under a lock inside the transaction.

Tests: `tests/Feature/Regression/PercentageRentTiersAndDeductionsTest.php`.
