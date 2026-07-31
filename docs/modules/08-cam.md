# CAM (Common Area Maintenance) Reconciliation

> Allocates annual CAM expenses pro-rata by leased area among tenants in a mall property, reconciles what was estimated vs. actual, and bills the shortfall (or credits overpayment) as one-off charges.

## 1. Purpose & business context

Mall owners (Jawad) collect estimated CAM charges monthly throughout a calendar year from tenants (retailers). Once the year ends, actual CAM expenses are reconciled against what was estimated. The difference (true-up) is allocated fairly to each tenant based on their proportional leased area.

**CAM pools** capture the total actual and estimated amounts per property per year. **CAM allocations** distribute that variance pro-rata to each active lease. The system creates one-time charges on leases for any shortfall (tenant owes more) or credits for overpayment (tenant gets a credit). This ensures fair cost-sharing: a large retail tenant pays more than a small one, proportional to their footprint.

---

## 2. Domain model

### Tables & columns

| Entity | Model | Key columns | Notes |
|--------|-------|-------------|-------|
| **CAM Expense Pool** | `CamExpensePool` | `id`, `asset_id` (FK), `period_year` (YYYY, 2020–2099), `total_actual_expense` (decimal:2), `total_estimated_collected` (decimal:2), `admin_fee_pct` (decimal:6,4, **nullable** — a FRACTION, 0.10 = 10%; null ⇒ no fee), `admin_fee_on_net` (bool, default true), `status` (enum), `notes`, `reconciled_at`, `reconciled_by_user_id`, `created_at`, `updated_at`, `deleted_at` (soft-delete) | A container for one year's CAM data on one property. Unique on `(asset_id, period_year)`. Index on `status`. |
| **CAM Allocation** | `CamAllocation` | `id`, `cam_expense_pool_id` (FK), `lease_id` (FK), `pro_rata_share_pct` (decimal:7,4), `allocated_amount` (decimal:2, **UNCAPPED** — the tie-out basis), `estimated_paid` (decimal:2), `true_up_amount` (decimal:2, = `capped_cost − estimated`), `admin_fee_amount` (decimal:2, default 0), `admin_fee_vat_amount` (decimal:2, default 0), `cap_amount` (decimal:2, nullable — the resolved ceiling that applied), `capped_cost_amount` (decimal:2, nullable — `min(allocated, ceiling)`), `cap_absorbed_amount` (decimal:2, default 0 — landlord-absorbed excess), `exclusions` (JSON), `status` (enum), `billed_charge_id` (FK, nullable), `billed_credit_note_id` (FK, nullable), `billed_admin_fee_charge_id` (FK, nullable), `created_at`, `updated_at`, `deleted_at` | One per active lease per pool. Unique on `(pool_id, lease_id)`. Index on `status`. |
| **Lease CAM Term** | `LeaseCamTerm` | `id`, `lease_id` (FK), `effective_year` (YYYY), `cap_type` (`absolute`/`yoy`/`both`), `cap_absolute_amount` (nullable), `base_year` (nullable), `base_year_amount` (nullable), `yoy_pct` (decimal:6,4, fraction, nullable), `compounding` (bool, default true), `notes`, `created_at`, `updated_at` | Effective-dated per-lease cap config. **HARD-delete** (forward-looking config, not a financial record) so a removed term frees its `(lease_id, effective_year)` unique slot for re-creation. Unique on `(lease_id, effective_year)`. `resolveCeiling(int $year)` returns the ceiling; `Lease::resolveCamCeiling($year)` picks the latest term ≤ that year. |

### Relationships

- **CamExpensePool**
  - `belongsTo(Asset)` — the property to which this pool belongs
  - `hasMany(CamAllocation)` — all allocations in this pool
  - `belongsTo(User, 'reconciled_by_user_id')` — the admin who marked it reconciled

- **CamAllocation**
  - `belongsTo(CamExpensePool, 'cam_expense_pool_id')` — parent pool
  - `belongsTo(Lease)` — the tenant's lease
  - `belongsTo(Charge, 'billed_charge_id')` — the one-time true-up charge created if billed

---

## 3. Business rules & invariants

### Core allocation formula
```
pro_rata_share_pct = (lease_unit.area_sqm / total_leased_sqm) * 100
allocated_amount    = pro_rata_share_pct% * pool.total_actual_expense
estimated_paid      = pro_rata_share_pct% * pool.total_estimated_collected
true_up_amount      = allocated_amount - estimated_paid
```

**In words**: Each lease's share of the pool's actual and estimated amounts is weighted by its unit's leased area (in sqm) as a fraction of the asset's total leased sqm. True-up is the difference: positive (tenant under-paid), negative (tenant over-paid).

**Tests guard**:
- `CamScenarioTest::it('generates one pro-rata allocation per active lease...')` — core math
- `CamScenarioTest::it('allocated amounts and shares sum to the pool total...')` — sums partition 100% and exact pool totals (subject to rounding)

### Rounding & precision
- Pro-rata share is rounded to **4 decimal places** (`decimal:7,4`).
- Allocated and true-up amounts are rounded to **2 decimal places** (`decimal:2`).
- Sub-cent rounding residuals are acceptable (max ±1 cent total per pool).

**Test guard**: `CamScenarioTest::it('leaves only a sub-cent rounding residual...')`.

### Scope — only active leases of the pool's asset
- Only leases with `status = 'active'` are eligible.
- Only leases whose `unit.asset_id` matches the pool's `asset_id` are included.
- Draft or inactive leases are silently skipped (count 0, contribute 0 sqm).
- If total leased area is ≤ 0, the service returns 0 allocations (no-op).

**Test guard**: `CamScenarioTest::it('only allocates to leases of the pools own asset...')`, and `it('generates zero allocations...')`.

### Idempotency & the skip-billed guard
- Calling `generateAllocations()` twice on the same pool **must not duplicate** allocations.
- Used via `firstOrNew(['pool_id', 'lease_id'])` — reuses an existing allocation if found.
- **Critical**: If an allocation exists and its status is not `'pending'` (e.g., already `'billed'`), the generation pass **skips it** — does not touch or re-count it.
  - This prevents resetting a `'billed'` allocation back to `'pending'`, which would allow double-billing the same true-up.

**Test guard**: `CamAllocationDoubleBillTest::it('does not reset an already-billed allocation...')` (regression). `CamScenarioTest::it('re-generating allocations updates in place...')` and `it('re-generating after the pool expense changes...')` (positive).

### Only pending allocations can be billed
- A `CamAllocation` can only transition to `'billed'` if its status is `'pending'`.
- Once billed, `bill()` becomes idempotent — re-calling it returns the same allocation and its backing record, never a second one.

### Positive true-up → charge; negative true-up → credit note
- A **positive** true-up (tenant under-paid) creates a one-off `Charge` on the lease (`billed_charge_id`) and settles it immediately on a dedicated recovery invoice. That invoice's line item is typed **`cam_recovery`**, so the General Ledger books it to **CAM Recovery Revenue** (`cam_recovery_revenue`, إيرادات استرداد المصروفات المشتركة) rather than generic misc income. (The `Charge` itself stays `type='other'` — it is a non-billed, non-journalized traceability anchor; see [module 21](21-general-ledger.md).)
- A **negative** true-up (tenant over-paid) creates an **issued `CreditNote`** on the tenant's account (`billed_credit_note_id`) — *not* a negative charge. A negative charge could drive a January invoice total negative, which `Invoice::recomputeTotals()` floors to 0, silently losing the credit. The credit note preserves it and settles future AR via the normal credit-apply flow.
- The books reconciliation (`BooksReconciliationService`, CAM check) accepts a billed allocation backed by **either** a charge, a credit note, **or** (zero true-up + fee owed) the admin-fee charge.

**Test guards**: `CamScenarioTest::it('re-billing an already-billed allocation is a no-op...')`, `CamScenarioTest::it('billing a negative-true-up allocation issues a credit note...')`, and `Regression/CamNegativeTrueUpCreditTest` (large credit preserved + books tie out).

### Admin fee — a SIBLING of the true-up, never folded into it
The recovery-clause engine adds the routine **management fee** the landlord charges on top of the recovered pool (operator default **10%**, VAT at the settings-driven standard rate — 14% today), the first bookable-revenue clause. It is deliberately built as a *sibling* of the true-up, not a modifier of it:

- **`admin_fee_amount = admin_fee_pct × capped_cost`** (the *net* cost the tenant bears — see the caps rule below; with no cap `capped_cost = allocated_amount`, so the fee is on the full recovered cost share, not the true-up) and **`admin_fee_vat_amount = admin_fee_amount × 0.14`**, both computed in `generateAllocations` and stored on the allocation. `allocated_amount` — the value the books-check ties out against `total_actual_expense` — is **never** touched by the fee, so the pool's cost pass-through still balances exactly.
- The fee bills as its own invoice line, typed **`cam_admin_fee`**, which the GL routes to a **dedicated `cam_admin_fee_revenue` account** (`41108001`, إيرادات رسوم إدارة المصروفات المشتركة) — margin the landlord *sells*, kept distinct from the cost pass-through (`cam_recovery_revenue`). It rides the **existing `Invoice` GL source** (no new `LedgerPoster` journalizer).
- **Where it lands** depends on the true-up sign: a **positive** true-up carries the fee as a *second line on the same recovery invoice*; a **negative** (credit note) or **exactly-zero** true-up bills the fee on its *own fee-only invoice* (a credit note can't carry a positive fee, and the fee is owed regardless of estimate accuracy). Either way an `is_active=false` anchor charge is recorded in `billed_admin_fee_charge_id` for traceability + the books-check.
- **`admin_fee_pct` null ⇒ no fee**, byte-identically to before the clause existed (guarded by `Regression/CamNoClauseParityTest`, the keystone — if it ever needs relaxing, the fee stopped being a no-op for pass-through-only malls). `admin_fee_on_net` (default true) is reserved for the gross-up slice and has no effect until then.

**Test guards**: `Regression/CamAdminFeeTest` (10% + VAT across positive/negative/zero, real `bill()` + real `accounting:sync-ledger` sweep, trial balance ties out, fee lands in `41108001`), `Regression/CamNoClauseParityTest` (no-clause parity).

### Caps — a ceiling on the true-up + fee base, never on the allocation
A lease can carry an effective-dated **`LeaseCamTerm`** capping how much CAM cost its tenant bears in a year — the controllable-expense ceiling anchor tenants negotiate. The cap applies to the COST path only:

- **`capped_cost = min(allocated_amount, ceiling)`**; **`true_up = capped_cost − estimated_paid`**; the admin fee is charged on the **capped** cost (`admin_fee_amount = admin_fee_pct × capped_cost`) so it can never re-breach the cap.
- **`allocated_amount` is NEVER capped** — it stays the tenant's raw share of `total_actual_expense`, which is exactly what the `BooksReconciliationService` CAM check ties out (`Σ allocated = total_actual_expense`). The capped excess is recorded as **`cap_absorbed_amount = allocated − capped_cost`** — the landlord's auditable cost of the cap (revenue it chooses to forgo, not a GL expense).
- **A cap can flip a positive true-up to a credit**: if the ceiling falls below what the tenant already paid in monthly estimates, `true_up` goes negative and settles through the normal credit-note path — while the admin fee (a positive amount on the capped cost) still bills on its own fee-only invoice.
- **The ceiling** comes from `Lease::resolveCamCeiling($year)`, which selects the effective-dated term with the greatest `effective_year ≤` the reconciled year, then `LeaseCamTerm::resolveCeiling()`: **absolute** (a flat EGP ceiling), **yoy** (`base_year_amount` grown by `yoy_pct`, compounding or simple, over `year − base_year`), or **both** (the tighter/`min` of the two). **No term ⇒ null ceiling ⇒ `capped_cost = allocated`**, byte-identical to the no-cap slice.

**Where operators set it**: the *CAM Cap Terms* relation manager on the Lease resource (write-gated on `leases.edit`, delete super_admin-only).

**Test guards**: `Regression/CamCapTest` (absolute / compounding-YoY / both=min ceilings, `allocated` stays uncapped, cap-flips-to-credit, effective-dating, real books tie-out with a cap), plus the no-term parity case.

### Variance definition
```
pool.variance() = total_actual_expense - total_estimated_collected
```
- Positive → under-collected (sum of all true-ups > 0; tenants will owe).
- Negative → over-collected (tenants get credits).
- Zero → perfect match.

**Test guard**: `CamScenarioTest::it('variance() reports actual-minus-collected...')`.

---

## 4. Lifecycle / state machine

### Pool statuses

| Status | Meaning | Allowed transitions | Terminal? |
|--------|---------|-------------------|-----------|
| `draft` | Created, not yet reconciled. May edit expense figures. | → `reconciling` (manual via UI; or auto-generate) | No |
| `reconciling` | Allocations have been generated; awaiting manual review/billing. | → `draft`, → `reconciled` | No |
| `reconciled` | Admin marked it fully reconciled. All allocations are ideally billed. | → `closed` | No (but typically final) |
| `closed` | Archive/final state. | None | Yes |

**Triggers**:
- Create → `draft` (default)
- Admin clicks "Generate Allocations" button → calls `generateAllocations()` and bumps pool to `reconciling`
- Admin clicks "Mark Reconciled" button → flips to `reconciled`, records `reconciled_at` timestamp and `reconciled_by_user_id`
- CLI `cam:reconcile --auto-bill` → auto-generates, auto-bills, and if allocations exist, flips to `reconciled` directly

### Allocation statuses

| Status | Meaning | Transition source | Terminal? |
|--------|---------|-------------------|-----------|
| `pending` | Generated but not yet billed; a Charge has not been created. | `generateAllocations()` | No |
| `billed` | A Charge (`type='other'`) has been created and linked. | `bill()` | No (but typically final) |
| `disputed` | Tenant or owner has disputed the true-up amount. | Manual (admin action, not implemented here) | No |
| `closed` | Archive. | Manual (admin action, not implemented here) | Yes |

---

## 5. Services, jobs & scheduled commands

### `CamReconciliationService`

#### `generateAllocations(CamExpensePool $pool): int`

**Signature**: Generates one pending allocation per active lease in the pool's asset, weighted by leased sqm.

**Returns**: Count of allocations created/updated (not including skipped billed ones).

**Behaviour**:
1. Queries all `Lease` records with `status='active'` and `unit.asset_id = $pool->asset_id`, pre-loads units.
2. Sums leased sqm. If ≤ 0, return 0 (no-op).
3. For each lease (within a DB transaction):
   - Calculate pro-rata share = `(lease.unit.area_sqm / total_sqm) * 100`, round to 4 decimals.
   - Calculate allocated = `round(pool.total_actual_expense * share, 2)`.
   - Calculate estimated = `round(pool.total_estimated_collected * share, 2)`.
   - Calculate true_up = `round(allocated - estimated, 2)`.
   - Use `firstOrNew(['pool_id', 'lease_id'])` to reuse or create.
   - **If allocation exists and status ≠ 'pending', skip (do not touch).**
   - Otherwise, fill and save with these amounts + `status='pending'`.
   - Increment counter.
4. Commit transaction.

**Idempotency**: Yes. Running twice on the same pool updates in-place; never duplicates. Skips non-pending allocations (billed, disputed, closed).

**Locking**: Wrapped in `DB::transaction()` for consistency.

**Common use**: Manual admin action or automated CLI.

#### `bill(CamAllocation $allocation): CamAllocation`

**Signature**: Creates a one-time Charge on the lease for the allocation's true-up amount.

**Returns**: The updated allocation (refreshed from DB).

**Behaviour**:
1. If allocation is already `'billed'`, return it as-is (no-op).
2. Within a DB transaction:
   - Retrieve the pool and year from the allocation.
   - Create a `Charge` record:
     - `lease_id` = allocation's lease
     - `name` = `"CAM Reconciliation — {year}"`
     - `type` = `'other'`
     - `amount` = allocation's `true_up_amount` (positive or negative)
     - `currency` = `'EGP'`
     - `frequency` = `'one_time'`
     - `vat_applicable` = `false` (CAM is typically pre-VAT)
     - `vat_rate` = `0`
     - `start_date` = `{year}-01-01`
     - `end_date` = `{year}-12-31`
     - `is_active` = `true`
   - Update allocation: `status='billed'`, `billed_charge_id=<charge_id>`.
   - Refresh and return.

**Idempotency**: Yes. Re-billing an already-billed allocation is a no-op (returns without creating a second charge).

**Locking**: Wrapped in `DB::transaction()`.

**Notes**: Negative true-ups create negative charges (credits). The charge appears on the next invoice generated for that lease.

#### `autoTrueUpForYear(int $year, bool $autoBill = false): array<int, array{pool_id, asset, allocations, billed, status}>`

**Signature**: Full annual reconciliation lifecycle. Generates allocations for all draft/reconciling pools in a given year, optionally bills all pending allocations, and bumps pool statuses.

**Returns**: Report array. Each entry is a dict with keys: `pool_id`, `asset` (name), `allocations` (count generated/updated), `billed` (count billed, 0 if `$autoBill=false`), `status` (pool's new status).

**Behaviour**:
1. Query all `CamExpensePool` with `period_year=$year` and `status IN ('draft', 'reconciling')`.
2. For each pool:
   - Call `generateAllocations()`, capture count.
   - If `$autoBill=true`:
     - Fetch all pending allocations.
     - Call `bill()` on each, count those billed.
   - Decide next status:
     - If `$autoBill && allocations > 0` → `'reconciled'` (set `reconciled_at=now()`).
     - Else if `allocations > 0` → `'reconciling'` (leaves for manual review).
     - Else → keep current status (no change).
   - Update pool if status changed.
   - Append entry to report.
3. Return full report.

**Idempotency**: Yes. Running twice with the same year/auto-bill setting:
- Already-reconciled pools are filtered out (not re-run).
- Already-billed allocations are skipped during generation.
- Second run returns empty report.

**Locking**: Each pool's state changes within `generateAllocations()` and `bill()` transactions.

**Entry point**: CLI command `cam:reconcile`.

---

### `CamAnnualReconciliationCommand`

**Signature**: `php artisan cam:reconcile {--year=YYYY} {--auto-bill}`

**Description**: Thin CLI wrapper around `autoTrueUpForYear()`. Defaults `--year` to previous calendar year if omitted.

**Options**:
- `--year=YYYY` — Target year (2020–2099). Defaults to `now()->year - 1`.
- `--auto-bill` — If set, runs full lifecycle (generate + bill + mark reconciled). If omitted, only generates allocations and bumps pools to `'reconciling'` for manual review.

**Output**: Prints a line per pool showing allocations generated/billed and new status. Ends with a summary line.

**Exit code**: `0` (success).

**Typical usage**:
```bash
# Generate allocations only; review in admin UI before billing
php artisan cam:reconcile --year=2025

# Fully automated: generate, bill, and mark reconciled
php artisan cam:reconcile --year=2025 --auto-bill
```

---

## 6. Filament resources & key fields

### Admin Resource: `CamExpensePoolResource`

**Class**: `App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource`

**Navigation**: "Accounting" group, icon building-office-2, sort 7.

**Scoping**: Property-scoped — `getEloquentQuery()` filters pools by the current asset (`TenantScope::currentAssetId()` / `visibleAssetIds()`), so a property-restricted operator sees only their malls' pools; only a fully-unrestricted admin sees all. `GuardsAssetInScope::assertAssetInScope()` guards the pool's `asset_id` on create + edit. Gated by module permission `'cam'`. *(The three write actions — generateAllocations / markReconciled / bill — gate their permission + status in **both** `visible()` and `action()` via a named predicate; `viewer`/`owner` hold `cam.view` but can't dispatch them. See `CamActionAuthzTest`.)*

**Permission module**: `'cam'` (overridden via `permissionModule()` from `RoleGatedActions`).

**Permissions required**:
- `cam.view` → `canViewAny()`, `canView()`
- `cam.create` → `canCreate()`
- `cam.edit` → `canEdit()`, `canRestore()`
- `cam.delete` → only `super_admin` (enforced globally)

#### Form fields (Create/Edit)

| Field | Type | Validation | Scope | Notes |
|-------|------|-----------|-------|-------|
| `asset_id` | Select | Required, searchable | TenantScoped | If current asset is scoped, auto-selects and disables. Otherwise, offers all selectable assets. |
| `period_year` | TextInput (numeric) | Required, min 2020, max 2099 | — | Defaults to current year. |
| `status` | Select | Required | — | Options: `['draft', 'reconciling', 'reconciled', 'closed']`. Default `'draft'`. |
| `total_actual_expense` | TextInput (numeric) | Required, min 0, step 0.01 | — | Prefix 'EGP'. Helper text explains this is the actual CAM spend for the year. |
| `total_estimated_collected` | TextInput (numeric) | Required, min 0, step 0.01 | — | Prefix 'EGP'. Helper text explains this is monthly estimates collected throughout the year. |
| `notes` | Textarea | Optional, rows 3 | — | Free-form notes (e.g. adjustments, explanations). |

#### Table columns & filters

| Column | Format | Sortable | Toggleable | Notes |
|--------|--------|----------|-----------|-------|
| `asset.name` | Text | — | — | Searchable. Medium weight. |
| `period_year` | Badge (gray) | ✓ | — | Year badge. |
| `total_actual_expense` | Money (EGP) | ✓ | — | Sum of all actual CAM costs. |
| `total_estimated_collected` | Money (EGP) | — | — | Sum of monthly estimates collected. |
| `variance()` | Money (EGP) | — | — | Computed on-the-fly. Color: warning if >0, success if <0, gray if =0. |
| `status` | Badge + color-coded | — | — | draft=gray, reconciling=warning, reconciled=success, closed=info. |
| `allocations_count` | Badge (gray) | — | — | Lazy-loaded count. |
| `reconciled_at` | Date (d/m/Y) | — | ✓ (hidden by default) | Null if not yet reconciled. |

**Filters**: `status` (select).

**Default sort**: `period_year DESC` (newest first).

#### Record actions

| Action | Visible if | What it does | Requires confirmation |
|--------|-----------|----------------|--------|
| `generateAllocations` | Status in `['draft', 'reconciling']` | Calls `CamReconciliationService::generateAllocations()`, bumps pool to `'reconciling'`, shows success notification with allocation count. | Yes |
| `markReconciled` | Status = `'reconciling'` | Updates pool: `status='reconciled'`, `reconciled_at=now()`, `reconciled_by_user_id=Auth::id()`. Shows success notification. | Yes |
| `EditAction` | Standard | Standard edit page. | — |

**Bulk actions**: Delete (only for super_admin, not enabled by default).

#### Relation Manager: `CamAllocationsRelationManager`

Embedded on the pool's edit page. Shows all allocations in this pool.

**Columns** (same as portal/owner, see below):
- `lease.tenant.name`
- `lease.unit.code`
- `pro_rata_share_pct` (formatted as `%.2f%`)
- `allocated_amount` (money, EGP)
- `estimated_paid` (money, EGP, toggleable)
- `true_up_amount` (money, EGP, semibold, colored by sign)
- `status` (badge)

**Filters**: `status` (select).

**Record actions**:
- `bill` — Visible if status = `'pending'`. Calls `bill()`, shows success notification with amount.

---

### Portal Resource: `CamAllocationResource` (Tenant view)

**Class**: `App\Filament\Portal\Resources\CamAllocations\CamAllocationResource`

**Navigation**: Sort 5. Icon: receipt-percent.

**Read-only**: `canCreate()`, `canEdit()`, `canDelete()` all return `false`.

**Scoping**: `getEloquentQuery()` filters to allocations of leases where `lease.tenant_id = Portal::tenantId()`. Pre-loads `pool.asset`, `lease.unit.asset`.

**List page**:
- **Columns**:
  - `pool.period_year` (badge)
  - `pool.asset.name` (toggleable, hidden by default)
  - `pro_rata_share_pct` (suffix '%', numeric(2), right-aligned)
  - `allocated_amount` (money, EGP, right-aligned)
  - `estimated_paid` (money, EGP, right-aligned, toggleable)
  - `true_up_amount` (money, EGP, right-aligned, bold, colored: danger if >0, success if <0)
  - `status` (badge)
- **Filters**: `status` (select)
- **Record actions**: `ViewAction` only
- **Default sort**: `pool.period_year DESC`
- **Empty state**: Receipt-percent icon + localized heading/description

**View page** (Infolist):
- **Section "Period"** (3 cols):
  - `pool.period_year` (badge)
  - `pool.asset.name`
  - `lease.unit.code` (badge)
- **Section "Breakdown"** (2 cols):
  - `pro_rata_share_pct` (suffix '%', numeric 2)
  - `pool.total_actual_expense` (money, EGP)
  - `allocated_amount` (money, EGP)
  - `estimated_paid` (money, EGP)
  - `true_up_amount` (money, EGP, colored by sign, helper text explaining the meaning)
  - `status` (badge)

---

### Owner Resource: `CamAllocationResource` (Owner view)

**Class**: `App\Filament\Owner\Resources\CamAllocations\CamAllocationResource`

**Navigation**: "Portfolio" group, sort 4. Icon: receipt-percent.

**Read-only**: Same as portal (no create/edit/delete).

**Scoping**: `getEloquentQuery()` filters to allocations of pools whose `pool.asset.owners` include the current user. Pre-loads `pool.asset`, `lease.unit.asset`, `lease.tenant`.

**List & View pages**: Identical to portal version.

---

## 7. Notifications & integrations

**Notifications fired** (Filament in-app only):
- When admin clicks "Generate Allocations": `allocations_generated` (title + body with count)
- When admin clicks "Mark Reconciled": `pool_reconciled` (title only)
- When admin clicks "Bill Allocation" on a row: `allocation_billed` (title + body with amount)

**External integrations**: None currently. CAM charges are created as standard `Charge` records and flow through the normal invoice/billing pipeline (Paymob integration, if applicable, occurs downstream at invoice time).

**Notifications NOT sent to tenants**: Allocations are visible to tenants in their portal once the pool is `'reconciled'` or later. No email/SMS is triggered — tenants see the CAM charge on their next invoice.

---

## 8. Extension points — how to change/extend SAFELY

### To add a new allocation field

1. **Add column to migration**: Edit the `create_cam_allocations_table` migration (or create a new one). Define type and cast.
   ```php
   $table->decimal('new_field', 14, 2)->default(0);
   ```

2. **Update model**: Add to `$fillable` array and `$casts` in `CamAllocation`.

3. **Update generation logic**: In `CamReconciliationService::generateAllocations()`, compute and assign the new field when filling the allocation.

4. **Update UI**: Add a column/field in the Filament admin/portal infolist or form as appropriate.

5. **Test**: Add test cases in `CamScenarioTest.php` to verify the new field's calculation and any new invariants.

**Do NOT**: Break the invariant that `generateAllocations()` skips non-pending allocations.

### To add a new pool field or validation rule

1. **Add column** to migration if it's persisted (or add to `$fillable` if it's just a parameter).

2. **Update model** `$fillable` and `$casts`.

3. **Update form** in `CamExpensePoolForm::configure()`.

4. **Update table** if it should be visible (add column to `CamExpensePoolsTable`).

5. **Test**: Add cases in `CamScenarioTest.php` or a new file.

**Do NOT**: Add fields that would change the historical record (e.g. editing `total_actual_expense` after allocations are billed should be blocked or require de-billing first).

### To change the pro-rata weighting (e.g. weight by revenue instead of area)

1. **Modify allocation formula** in `CamReconciliationService::generateAllocations()`. The key lines are:
   ```php
   $sqm = (float) ($lease->unit?->area_sqm ?? 0);
   if ($sqm <= 0) continue;
   $share = $sqm / $totalSqm;
   ```
   Replace `$sqm` and `$totalSqm` with your new metric.

2. **Update tests**: Rewrite `CamScenarioTest::it('generates one pro-rata allocation...')` and related cases to reflect the new weighting.

3. **Document**: Update this section and the "Domain model" section to explain the new formula.

4. **Verify invariants**: Ensure sums still partition to 100% and that rounding residuals remain bounded.

**Do NOT**: Break backward compatibility with existing allocations (they are immutable once billed).

### The recovery-clause engine (admin fee → caps → basis/gross-up/exclusions)

The admin fee (**slice 1**) and caps (**slice 2**) are shipped; the engine is phased (full design: [`docs/plans/05-cam-recovery-engine.md`](../plans/05-cam-recovery-engine.md)). The keystone constraint across every slice: **`allocated_amount` stays the uncapped cost pass-through** the books-check ties out against — clauses attach as siblings (the admin fee) or adjust only `true_up_amount` (caps), never the allocation. Every clause is off-by-default (`admin_fee_pct` null, no `LeaseCamTerm`, `exclusions` unset ⇒ byte-identical no-clause billing):

- ✅ **Slice 1 — admin fee**: see the admin-fee rule above.
- ✅ **Slice 2 — caps** (`LeaseCamTerm`): see the caps rule above — the cap adjusts the true-up + fee base only.
- **Slice 3 — configurable basis / gross-up / exclusions** (designed, not yet built): alternative pro-rata weightings, a gross-up to a target occupancy, and per-lease exclusion sets (`exclusions` JSON). `admin_fee_on_net` decides whether the fee is charged on the gross or net (post-gross-up) share.

**To add a new clause**: add its column(s) defaulting to a no-op, compute inside `generateAllocations`, apply in the correct order (SOURCE → EXCLUDE → GROSS-UP → ALLOCATE → CAP → ADMIN-FEE → TRUE-UP), and add a parity assertion to `CamNoClauseParityTest` proving the default is still byte-identical.

### Caps and exclusions

**Caps are SHIPPED** (slice 2) — see the *Caps* rule in § 3. `cap_amount` / `capped_cost_amount` /
`cap_absorbed_amount` are populated by `generateAllocations` from the lease's effective-dated
`LeaseCamTerm` (`Lease::resolveCamCeiling`); the cap trims the true-up + admin-fee base only,
**never** `allocated_amount` (the books-check tie-out basis). *(This section previously said caps
were "not used" — stale; corrected in the close-out.)*

**`exclusions`** (the JSON column) belongs to **slice 3** (per-lease non-recoverables) and is still
unused — see the recovery-clause engine note below and the [plan](../plans/05-cam-recovery-engine.md).

**Do NOT**: change `allocated_amount` for a cap/exclusion — only the true-up + fee base (the keystone
invariant).

### To link CAM charges to a tax/VAT regime

1. CAM charges are created with `vat_applicable=false, vat_rate=0`. To change:
   ```php
   $charge = Charge::create([
       // ...
       'vat_applicable' => true,
       'vat_rate' => 0.15, // or from config
       // ...
   ]);
   ```

2. **Test**: Verify invoice generation includes VAT on CAM.

**Do NOT**: Assume all jurisdictions treat CAM the same; consider a config or lease-level flag.

### To disable auto-billing or require approval

1. **Modify `autoTrueUpForYear()`**: Even if `$autoBill=true`, check a permission or flag before calling `bill()`.

2. **Add UI gate**: In `CamAllocationsRelationManager`, wrap the "bill" action in a visibility check.

3. **Test**: Add a case like `it('respects billing approval flag...')`.

**Do NOT**: Leave allocations stuck in 'pending' indefinitely (implement a workflow for approval).

---

## 9. Gotchas, edge cases & recently-fixed bugs

### Double-bill regression (FIXED)

**Bug**: `generateAllocations()` always set `status='pending'`, so re-running it on a pool with a 'billed' allocation would reset the status back to 'pending'. The next billing pass would then create a second charge.

**Fix**: Added the skip-billed guard: if an allocation exists and status ≠ 'pending', skip it entirely.

**Test guards**: `CamAllocationDoubleBillTest::it('does not reset an already-billed allocation...')`.

**Lesson**: Any idempotent operation that reuses state must check state before overwriting.

---

### Share-basis drift on re-run after partial billing (FIXED — clause-review #1)

**Bug**: `generateAllocations()` re-run pinned the participant *set* but recomputed the sqm denominator from *live* unit areas. After partial billing (some allocations frozen at `billed`), correcting a unit's `area_sqm` — or soft-deleting a participant — then re-running recomputed the still-pending shares against the new denominator: `Σ(allocated_amount) ≠ total_actual_expense` (broken tie-out) and mis-billed tenants. The pool freeze guard protected the *expense* basis but had no counterpart for the *area* basis.

**Fix**: On a re-run (existing allocations present), REUSE each allocation's stored `pro_rata_share_pct` instead of recomputing from sqm — the share basis is established on the first run and frozen thereafter. `capped_cost`/`admin_fee` derive from the frozen `allocated`, so they stay consistent too.

**Test guards**: `CamClauseReviewHardeningTest` (unit-area edit + soft-deleted participant between runs both keep `Σ = total_actual_expense`).

---

### Recovery-basis freeze now covers the admin fee (FIXED — clause-review #2)

The pool `updating` freeze guard (can't change the basis after any allocation is billed) now also blocks `admin_fee_pct` / `admin_fee_on_net` — otherwise billed rows keep the old fee while re-generated pending rows recompute on the new rate, charging equal shares different fees. `admin_fee_pct` is also in the activity log now. To change a fee rate mid-cycle, void the billed allocations first.

---

### Cap terms hard-delete (clause-review #3/#4/#5)

`LeaseCamTerm` is **hard-deleted** (not soft) so a removed cap frees its `(lease_id, effective_year)` unique slot — a soft-deleted row would collide with the index and permanently block re-adding that year's cap. Safe because a cap term is forward-looking config; historical allocations already froze the `cap_amount` they applied.

---

### Rounding residuals

When total sqm is not evenly divisible (e.g. three equal units = 1/3 each on 100,000), rounding to 2 decimals can accumulate. The system accepts **up to ±1 cent** residual per pool.

**Example**:
```
100,000 / 3 = 33,333.33 each (per allocation)
Sum = 99,999.99 (1 cent short)
```

If this is unacceptable in your jurisdiction, implement a "penny-pinch" algorithm: allocate the residual to the largest allocation.

**Test guard**: `CamScenarioTest::it('leaves only a sub-cent rounding residual...')`.

---

### Negative true-ups (credits)

When a tenant over-paid their estimate, `true_up_amount` is negative. The charge is created with the negative amount. On invoicing, this nets as a credit. No special handling needed; just ensure downstream invoice generation correctly interprets negative charges.

**Test guard**: `CamScenarioTest::it('produces a negative true-up (credit)...')` and `it('billing a negative-true-up allocation creates a negative (credit) charge')`.

---

### Soft-deleted leases and units

If a lease is soft-deleted after an allocation is billed, the allocation still references it. The allocation persists, the charge remains on the lease. No action needed.

If a unit is soft-deleted, active leases using it are no longer queried in `generateAllocations()` (because `Lease::whereHas('unit', ...)` would skip them). This is safe but unusual; check if soft-deletes are your intended behavior.

---

### Multi-unit leases (not yet relevant, but future-proof)

The current model assumes each lease has one master unit. If multi-unit leases become common, the allocation logic must sum area across all units in the `lease_unit` pivot. Update:
```php
$sqm = (float) $lease->units()->sum('area_sqm');
```

---

### Year boundaries

The pool's `period_year` is a single year (e.g. 2025). CAM collected month-by-month in one calendar year; reconciliation happens in January/February of the next year. No cross-year logic is needed.

---

### Permission scoping gotcha

Portal tenants see only their own allocations (filtered in `getEloquentQuery()`). Owner users see allocations for assets they own. Admin sees all. This is enforced in the resource query, not in the service; the service is permission-agnostic. If you expose the service via an API without applying the same query filters, you'll leak data.

---

### Allocation state transitions are not enforced by the model

A malicious API client could manually set an allocation's status to 'closed' without billing. The code trusts the status. If transitions should be rigid, add a state machine helper or model scope:
```php
public function markBilled() {
    if ($this->status !== 'pending') {
        throw new \Exception('Can only bill pending allocations');
    }
    // ...
}
```

---

## 10. Tests & related modules

### Test files

| File | Coverage |
|------|----------|
| `tests/Feature/Scenarios/CamScenarioTest.php` | Pro-rata math, rounding, scope, idempotency, state transitions, variance. **CORE TEST FILE**. ~337 lines, 17 test cases. |
| `tests/Feature/Regression/CamAllocationDoubleBillTest.php` | Double-bill regression (fixed). 1 test case pinning the fix. |
| `tests/Feature/Services/CamAutoTrueUpTest.php` | `autoTrueUpForYear()` with/without auto-bill, year skipping, empty report, idempotency, CLI command wiring. ~86 lines, 6 test cases. |
| `tests/Feature/Resources/PortalCamAllocationScopingTest.php` | Portal tenant isolation (Tenant A sees only their allocations). 2 test cases. |

**Total coverage**: ~27 test cases, all passing.

### Running tests

```bash
# All CAM tests
php artisan test --filter=Cam

# Specific test file
php artisan test tests/Feature/Scenarios/CamScenarioTest.php

# Single test case
php artisan test --filter='generates one pro-rata allocation'
```

---

### Related modules & docs

| Module | Relationship |
|--------|--------------|
| **Lease** (`docs/modules/03-lease.md`) | CAM allocations bind to active leases. Lease status, unit area, and tenant determine eligibility. |
| **Charge** (`docs/modules/05-charge.md`) | CAM allocations create one-time charges. The charge's amount, currency, dates are controlled by the true-up and pool year. |
| **Invoice & AR** (`docs/modules/06-invoice.md` / `09-ar.md`) | CAM charges flow into the next invoice. Negative charges (credits) offset rent or are refunded. |
| **Asset** (`docs/modules/01-asset.md`) | Pool is scoped to an asset. Asset properties (e.g. name, currency) may influence CAM. |
| **Permissions & Roles** (`docs/modules/11-permissions.md`) | CAM actions are gated by module permissions (`cam.*`). Only users with the right role can view/create/edit pools or bill allocations. |


## 11. Close-out (2026-07-20) — what changed

The property+facility close-out ([gap-analysis](../gap-analysis/PROPERTY-FACILITY-CLOSURE.md)); plain-language business model: [business-model/08](../business-model/08-cam.md). The engine (slices 1–2) was found correct; the fixes are around it.

### Authz double-gate (was: dispatch hole)
`generateAllocations` / `markReconciled` (`CamExpensePoolsTable`) + `bill` + the new `void` (`CamAllocationsRelationManager`) now re-assert a **named predicate** — `canGenerate` / `canMarkReconciled` / `canBill` / `canVoid` (permission **and** status) — in **both** `visible()` and `action()` (`abort_unless`). `mountAction()` never checks `isVisible()`, and seeded `viewer`/`owner` hold `cam.view`, so the old visible()-only gate let them dispatch these (and `generateAllocations` re-opened a reconciled pool via its unconditional `status=reconciling`). Tested via `mountAction`+`callMountedAction` in `CamActionAuthzTest` (not `callAction`, which false-passes).

### Per-pool recovery VAT (`recovery_vat_rate`, default 14%)
The monthly CAM estimate bills at 14% VAT; the year-end recovery used to bill at 0% — an output-VAT under-collection inconsistent with the estimate. `billChargeImmediately` now VATs the recovery line and `billCredit` VATs the over-collection credit note (the credit-note journalizer reverses it: Dr Sales Returns + Dr VAT Payable / Cr AR). VAT rides **on top** — `allocated_amount` / `true_up_amount` are untouched, so `Σ allocated = total_actual_expense` still ties out. Frozen with the rest of the basis (`CamExpensePool::booted`). `recovery_vat_rate = 0` ⇒ byte-identical to the pre-VAT pass-through. Tested through the real sweep in `CamRecoveryVatTest`. *(A tax call for the accountant; the default matches the estimate, 0% available per pool.)*

### `voidAllocation` (un-bill / correction path)
Reverses a billed allocation's documents — the recovery invoice (`VoidInvoiceService`, which also carries the fee line for a positive true-up), the credit note (`CreditNoteService::reverseAllApplications` then `void`), and the fee-only invoice — and resets the allocation to `pending`, which **unfreezes the pool basis** (the freeze guard checks non-pending allocations) for a correct-and-re-bill. A recovery invoice the tenant already **paid** blocks it (`VoidInvoiceService` refuses captured cash — refund first). Lock-safe + idempotent; the whole reversal is one transaction. Surfaced as the `void` action on the allocations relation manager (gated `cam.bill_allocation`). Tested in `CamVoidAllocationTest`. This is the action the freeze guard's error message ("void the billed allocations first") always referenced.

### Operator UX pass (2026-07-22)
A UX audit found the reconciliation numbers weren't verifiable and one modal misinformed. Fixed: **(H1)** the Bill confirm modal said the true-up rides "the next monthly invoice" — it's billed **immediately** as a dedicated recovery invoice (or an auto-applied credit); reworded. **(H2)** editing a reconciled pool's basis fields threw an uncaught `DomainException` → Livewire 500; the four basis fields are now **disabled with a "frozen — void billed allocations first" hint** once an allocation is billed (`CamExpensePoolForm::basisFrozen()`), plus `EditCamExpensePool::handleRecordUpdate()` catches the guard as a clean toast backstop. **(M1)** the cap + VAT legs were invisible and the columns stopped adding up when a cap bit — added `CamReconciliationService::explainAllocation()` + a native-Filament **"View working"** breakdown action (share → allocated → cap ceiling/capped/absorbed → estimate → true-up → recovery VAT → admin fee + VAT → net invoiced/credited) and toggleable `capped_cost`/`cap_absorbed` columns. **(M2)** the billed-allocation notification now branches recover / credit / fee-only (was "true-up added" for all three). **(L1)** allocations relation-manager empty state. Tests: `CamAllocationBreakdownTest`.

### Deferred (with triggers)
Slice 3 (gross-up / GLA basis / alternative bases / per-lease `exclusions`); per-tenant estimate derivation from actual billed service charges; move-in/out occupied-days proration; a tenant reconciliation-statement PDF. See the closure record for the triggers.

---

## Correction — the `visible()` dispatch premise (2026-07-31)

**Correction 2026-07-31 — the "still dispatchable" half of this was WRONG on the version we ship.** `mountAction()` does check `isDisabled()`, and `CanBeDisabled::isDisabled()` returns true when `isHidden()` does (`vendor/filament/actions/src/Concerns/CanBeDisabled.php:24`), so on **Filament v4.11.8 a `visible()`-only action IS refused at dispatch**. Found by mutation-testing the module-08 fix: deleting CAM's `abort_unless` left `CamActionAuthzTest` fully green — those tests never exercised the gate they describe. **Double-gate anyway**, for a reason that survives the correction: `->authorize()` is a stated intent, while hidden-implies-disabled is an upstream implementation detail that can change in a release and would silently reopen every `visible()`-only write at once. `FilamentActionDispatchContractTest` pins that upstream behaviour so an upgrade that changes it turns the build red; `ActionAuthzConformanceTest` enforces the layer we control.

---

## Deletion policy

Operator decision 2026-07-31, following Yardi/MRI/Entrata: a record that carries history is
**refused**, not warned about — the damage lands on the reports and audit trail that referenced
it, none of which are in front of whoever clicks the button. The single register is
[`App\Support\DeletionPolicy`](../../app/Support/DeletionPolicy.php); `DeletionPolicyConformanceTest` fails the build if a model here ships unclassified or a Delete
button reappears on a money record.

| Model | Rule | Instead / why |
|---|---|---|
| `CamExpensePool` | **Only while unreferenced** — blocked by `allocations` | void the allocations first — they are what tenants were billed from |
| `CamAllocation` | Deletable (super_admin) | operational: voided through the pool, not removed |
