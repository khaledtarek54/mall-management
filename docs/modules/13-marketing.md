# Marketing Levy, Budgets & Spend

> A per-property, per-year marketing fund accrued from a configurable percentage levy on base rent, spent against recorded marketing activities.

## 1. Purpose & business context

The marketing module captures an operator's promotional/marketing expenditure and how it is funded. A recurring **marketing levy** (5% by default, configurable) is charged on each tenant's base rent **as a VAT-exempt line item on the tenant's invoice** (rewired 2026-06-28 — confirmed business decision); the collected levy is pooled into a per-property marketing budget. The operator then draws from that budget to fund offers, promotions, events, and printed work, tracked as discrete spend items. The Jawad (property owner) can view accrued levies and spends to ensure the operator remains within budget (though overspend is allowed with a warning). This mirrors the CAM/expense-pool model but inverted: income accrual rather than cost allocation.

## 2. Domain model

| Table | Model | Key Columns (type/constraints/default) | Meaning |
|-------|-------|-------|---------|
| `marketing_budgets` | `MarketingBudget` | `id` (PK); `asset_id` (FK, cascadeOnDelete); `period_year` (unsignedSmallInteger); `accrued_amount` (decimal:2, default 0); `spent_amount` (decimal:2, default 0); `status` (enum:'open'/'closed', default 'open'); `notes` (text, nullable); `created_at`, `updated_at`, `deleted_at` | One row per property per year. Accrued levies accumulate here; spends are derived from related MarketingSpend records. Soft-deletes supported. **Unique constraint**: `(asset_id, period_year)`. |
| `marketing_spends` | `MarketingSpend` | `id` (PK); `marketing_budget_id` (FK, cascadeOnDelete); `category` (enum:'offer'/'promotion'/'event'/'printed_work'/'other', default 'other'); `description` (string); `amount` (decimal:2); `paid_from` (string, default 'cash' — `cash`/`bank`, drives the GL credit account, coerced non-null in the model); `spent_on` (date); `receipt_reference` (string, nullable); `created_by_user_id` (FK, nullOnDelete); `created_at`, `updated_at`, `deleted_at` | Each line-item spend against a budget. Indexed by `marketing_budget_id` and `category`. Soft-deletes supported. **Posts to the GL** (see § 7). |
| `charges` | `Charge` | `type` enum includes `'marketing'` (FR MKT-2) | The marketing levy is modeled as a recurring monthly charge on the lease. `vat_applicable = false`, `vat_rate = 0`, `frequency = 'monthly'`. The amount is captured at charge creation and never changes even if the levy rate changes (rate versioning via per-charge capture). |

**Relationships:**
- `MarketingBudget` **belongs_to** `Asset` (property).
- `MarketingBudget` **has_many** `MarketingSpend` (spends).
- `MarketingSpend` **belongs_to** `MarketingBudget` (budget).
- `MarketingSpend` **belongs_to** `User` (created_by, nullable).
- `Charge` with `type = 'marketing'` belongs_to `Lease` (the lease on which the levy is calculated).

## 3. Business rules & invariants

1. **Levy rate (FR MKT-2):** Stored in `MarketingSettings.levy_rate_percent` (default 5.0). The rate is NOT versioned in a separate table; instead, it is captured at the moment a Charge or accrual is created. Changing the setting rate does NOT alter historical accruals or charges — only future operations use the new rate. (See "Rate versioning" in Scenarios, § 9.)

2. **Levy calculation (FR MKT-2):** Levy per lease = `base_rent_monthly × levy_rate_percent / 100`, rounded to 2 decimals.
   - Example: 10,000 EGP base rent × 5% = 500 EGP levy.

3. **Marketing Charge (FR MKT-2/3):** 
   - One and only one `marketing` type charge per lease (idempotent via `updateOrCreate` on `(lease_id, type)`).
   - VAT-exempt (`vat_applicable = false`, `vat_rate = 0`).
   - Monthly frequency.
   - Amount is captured and does NOT change retroactively if the lease's base_rent_monthly changes (the Charge itself is updated separately; only the rate-at-creation-time is versioned).

4. **Budget accrual (FR MKT-5) — DERIVED, rewired 2026-06-28:**
   - The levy is a real **tenant invoice line item** (`invoice_items.type = 'marketing'`, VAT-exempt) — it is **charged to the tenant** (the invoice total includes it), not an internal set-aside.
   - `MarketingBudget::recomputeAccrued()` **derives** `accrued_amount` = SUM of non-cancelled billed marketing items for the asset + year. It is **not** a running increment — it's re-derived from source (mirrors `recomputeSpent` and `Invoice::recomputeTotals`).
   - Triggered reactively by `InvoiceItem`'s saved/deleted hook (billing creates the item → budget re-derives) and by `Invoice` cancellation (status→cancelled re-derives), so the accrual **auto-reverses** when an invoice is cancelled.
   - Reconcilable: the `marketing_budget` check in `billing:reconcile` asserts `accrued == billed levies` and `spent == recorded spends`.
   - The levy charge is seeded on lease creation and **kept in lock-step with base rent** on rent-change + renewal (`MarketingLevyService::createLevyCharge`). A prorated first-month invoice bills the prorated levy.

5. **Spend recording (FR MKT-1/4):** 
   - Any spend > 0 is allowed; negative amounts are rejected at the form layer (`minValue(0)`).
   - A spend decreases the budget's `spent_amount` immediately (via model observer).
   - Receipt reference (`receipt_reference`) is optional and serves as a link to Accounting for reconciliation.

6. **Balance & overspend (FR MKT-5):** 
   - `balance() = accrued_amount - spent_amount` (always computed, never stored).
   - **Overspend is allowed:** a spend can exceed the remaining balance; the balance goes negative.
   - The Filament relation manager warns if the balance becomes negative, but does **not block** the spend (see `warnIfOverBudget()` in MarketingSpendsRelationManager).

7. **Data scoping:** Each property (asset) has its own separate budget per year. Budgets are not shared across properties.

8. **Status enum:** `open` (default, spends can be recorded) or `closed` (read-only; for record-keeping after a period ends). No business rule enforces this; it's advisory.

| Rule | Tested By |
|------|-----------|
| Levy = 5% of base_rent | `MarketingLevyTest::computes the levy as 5% of base rent` |
| Marketing charge is idempotent, VAT-exempt | `MarketingLevyTest::creates an idempotent VAT-exempt marketing levy charge on the lease` |
| Accrual adds to budget | `MarketingLevyTest::accrues the levy into the property marketing budget` |
| Balance = accrued - spent | `MarketingBudgetTest::derives balance as accrued minus spent` |
| One budget per asset + year | `MarketingBudgetTest::gets or creates one budget per asset and period` |
| Billing accrues 5% of billed rent | `MarketingScenarioTest::raises the budget accrued by 5% of billed base rent when a lease is billed` |
| Overspend allowed, balance goes negative | `MarketingScenarioTest::allows a spend greater than the remaining balance — balance goes negative` |
| Rate change doesn't rewrite history | `MarketingScenarioTest::does not rewrite a historical accrual when the levy rate later changes` |
| Negative spend rejected | `MarketingScenarioTest::rejects a negative spend amount at the form layer` |

## 4. Lifecycle / state machine

MarketingBudget has two statuses: `open` and `closed`. No enforced transitions; manually set via the form.

```
[open] ←→ [closed]
  ↑         (no RBAC rule prevents toggling)
  └─ Default at creation.
```

**Immutable aspects:**
- Once created, a budget is soft-deleted but never hard-deleted (except cascading from asset deletion).
- A spend's `spent_on`, `amount`, and `category` can be edited after creation.
- Deletions are allowed; soft-delete is used so audit logs are preserved.

**Terminal state:** `closed` is advisory; there is no enforced terminal state in the current design.

## 5. Services, jobs & scheduled commands

### MarketingLevyService

**File:** `app/Services/MarketingLevyService.php`

**Methods:**

1. **`ratePercent(): float`**
   - Returns the current marketing levy rate from `MarketingSettings.levy_rate_percent`.
   - Falls back to 5.0 if settings are missing (graceful fallback for minimal environments).
   - **Idempotent:** yes. **Transactional:** no (read-only).

2. **`amountFor(Lease $lease): float`**
   - Calculates the monthly levy for a single lease: `base_rent_monthly × ratePercent() / 100`, rounded to 2 decimals.
   - **Idempotent:** yes. **Transactional:** no.

3. **`createLevyCharge(Lease $lease): Charge`**
   - Ensures a marketing Charge exists on the lease via `updateOrCreate` on `(lease_id, type = 'marketing')`.
   - Sets: `amount` (calculated at this moment), `currency`, `frequency = 'monthly'`, `vat_applicable = false`, `vat_rate = 0`, `is_active = true`.
   - **Idempotent:** yes — calling twice does not create a second charge.
   - **Transactional:** no (delegates to Charge model). **Locking:** no.
   - **Rate versioning:** the amount is captured at this call; a later rate change does not retroactively update the charge unless this method is called again.

4. **`accrue(int $assetId, int $year, float $amount): MarketingBudget`**
   - Adds `$amount` to the budget's `accrued_amount` for the given property + year.
   - Uses `MarketingBudget::forPeriod()` to get-or-create the budget row, then increments.
   - **Idempotent:** no — each call adds the amount. (Idempotency is handled at the billing layer, not here.)
   - **Transactional:** no (raw increment). **Locking:** no.
   - **Single entry point:** all accruals go through this method so the running total is always derived.

### MonthlyBillingService → accrueMarketingLevy()

**File:** `app/Services/MonthlyBillingService.php`, lines 232–258.

**Called by:** `generateInvoiceForLease()` after invoice items are created (line 221).

**What it does:**
1. Sums all billed base_rent line items.
2. Computes levy as `rentBilled × ratePercent() / 100`.
3. Calls `MarketingLevyService::accrue()` to add to the budget.
4. If any step fails (e.g., asset not found, lever is ≤ 0, exception in accrue), it logs a warning but does **not** abort invoice generation (wrapped in try-catch).

**Idempotency:** The billing engine ensures each lease+period is billed only once. So this accrual happens exactly once per period per lease.

**Rate versioning:** The rate is read fresh from settings at billing time, so each month's accrual reflects the rate in force at that moment.

### BackfillMarketingBudgetsCommand

**File:** `app/Console/Commands/BackfillMarketingBudgetsCommand.php`

**Signature:** `php artisan marketing:backfill-budgets`

**What it does:**
1. Iterates all invoices with their billed base_rent line items.
2. Sums base rent by (asset, year).
3. For each (asset, year), sets `accrued_amount = rent_sum × ratePercent() / 100`.
4. Outputs count of budgets rebuilt.

**Idempotency:** Uses `update()`, not `increment()`, so it **overwrites** existing accruals rather than adding to them. Safe to re-run.

**Transaction:** Chunked reads (200 invoices at a time) but single writes per budget. No explicit transaction locking.

**When to use:** After enabling the marketing module or if accruals become out of sync with billed invoices.

## 6. Filament resources & key fields

### MarketingBudgetResource

**File:** `app/Filament/Admin/Resources/MarketingBudgets/MarketingBudgetResource.php`

**Pages:** (no Create — budgets are **auto-provisioned**, see below)
- **List** (`ListMarketingBudgets`): Table with property, year, collected levy, spent, available, status.
- **Edit** (`EditMarketingBudget`): read-only fund panel (collected levy / spent / available) + read-only property/year identity; editable status/notes; embedded relation manager for spends (each spend shows the available fund). `canCreate`/`canDelete` = false for everyone.

**Auto-provisioning (2026-06-28):** a budget is one-per-property-per-year and is created automatically — `marketing:ensure-budgets` (scheduled daily, idempotent `firstOrCreate` per property) + lazily via `MarketingBudget::forPeriod()` on the first billed levy. Users never hand-create or delete budgets; they only record spends. `accrued_amount` is derived from billed levy items (see § Business rules 4).

**Permissions (RBAC):**
- Module: `marketing` (see `permissionModule()`).
- Gated actions: view/create/edit/delete guarded by `RoleGatedActions` trait.
- **Roles:**
  - `marketing` role: can view, create, edit budgets and spends; **cannot delete** (only super_admin).
  - `viewer` role: can view only.
  - Other roles: denied.

**TenantScope:**
- Scoped to current property via `tenantOwnershipRelationshipName = 'asset'`.
- `BypassesScopingOnAll` trait allows super_admin to view all properties' budgets (no filtering).
- Form `asset_id` select is restricted to `TenantScope::selectableAssetOptions()` and disabled if already scoped (protecting multi-tenant data).

### MarketingBudgetForm

**File:** `app/Filament/Admin/Resources/MarketingBudgets/Schemas/MarketingBudgetForm.php`

**Fields:**

| Field | Type | Validation | Notes |
|-------|------|-----------|-------|
| `asset_id` | Select | Required | Populated from `TenantScope::selectableAssetOptions()`. Disabled if scoped. Defaults to current tenant. |
| `period_year` | TextInput (numeric) | Required | Defaults to current year. Can be edited to backfill prior years. |
| `status` | Select ('open'/'closed') | Required | Defaults to 'open'. No business rule enforcement. |
| `notes` | Textarea | Optional | Free-text notes (e.g., "Q1 overspend approved by Jawad"). |

### MarketingSpendsRelationManager

**File:** `app/Filament/Admin/Resources/MarketingBudgets/RelationManagers/MarketingSpendsRelationManager.php`

**Relationship:** `spends` (inverse of `MarketingBudget::spends()`).

**Table columns:**

| Column | Format | Notes |
|--------|--------|-------|
| `spent_on` | Date (d/m/Y) | Sortable. Default sort: desc (newest first). |
| `category` | Badge, headline-cased | 'offer', 'promotion', 'event', 'printed_work', 'other'. |
| `description` | Text, truncated (40 chars) | The spend's purpose/memo. |
| `amount` | Money (EGP), bold | In the currency of the property. |
| `receipt_reference` | Text | Link to Accounting; placeholder if null. |

**Form fields (create/edit):**

| Field | Type | Validation | Notes |
|-------|------|-----------|-------|
| `category` | Select | Required | Enum of 5 categories. Defaults to 'other'. Native false (full dropdown). |
| `amount` | TextInput (numeric) | Required, `minValue(0)` | Rejects negative/zero at form layer. Helper text warns of overspend risk. |
| `paid_from` | Select ('cash'/'bank') | Required | Defaults to 'cash'. Determines the GL credit account (Cr Cash \| Bank). Reuses `admin.enums.expense_paid_from`. |
| `spent_on` | DatePicker | Required | Defaults to today. Native false (calendar widget). |
| `receipt_reference` | TextInput | Optional | Max 255 chars. Link to accounting invoice/receipt. |
| `description` | TextInput | Required | Max 255 chars. Spans full width. |

**Actions:**

- **Create** → After create, calls `warnIfOverBudget()` to check if balance < 0. If so, fires a warning notification with the overspend amount.
- **Edit** → Same post-edit warning.
- **Delete** → Soft-delete; no warning (spend is being reversed).

**Critical behavior:** The relation manager allows overspends (no validation rule `maxValue`). Balance may go negative. (FR MKT-5 confirmed 2026-06-25.)

## 7. Notifications & integrations

**No scheduled notifications are sent.**

**In-app warnings:**
- `warnIfOverBudget()` in MarketingSpendsRelationManager fires a non-blocking Filament warning if a spend pushes the balance negative.
- Title: `admin.tables.marketing_spend.overspend_title`.
- Body: `admin.tables.marketing_spend.overspend_body` (includes the overspend amount).

**General Ledger integration (shipped 2026-07-03):**
- Each marketing spend **posts to the double-entry GL** via `MarketingSpendJournalizer`
  (registered in `LedgerPoster`): **Dr Marketing Expense (`51105001`) / Cr Cash \| Bank**
  (per `paid_from`), scoped to the budget's property (`asset_id`).
- Posting is the same **reconciling sweep** used by every other money source:
  `accounting:sync-ledger` (scheduled + `--all` backfill + on-demand "Post to GL now")
  posts new spends, re-derives edited ones, and **voids** the entry when a spend is
  soft-deleted — idempotent and self-healing (see [module 21](21-general-ledger.md)).
- This closes the loop: the marketing **levy** is already recognised as revenue on the
  tenant invoice (`marketing_revenue`), so the fund's **net P&L position** (levy − spend)
  is now fully on the books.
- **No VAT split** on the spend today — the model carries a single gross `amount`, so the
  full amount is expensed (input VAT on marketing spend is a future enhancement; add a
  `vat_amount` column + a VAT-recoverable line to the journalizer, mirroring `Expense`).
- `receipt_reference` remains a free-text pointer to the source receipt for audit.

**Activity logging:**
- `MarketingBudget` logs changes to: `accrued_amount`, `spent_amount`, `status` (via Spatie ActivityLog).
- `MarketingSpend` logs changes to: `marketing_budget_id`, `category`, `amount`, `receipt_reference`.
- Both use `dontLogEmptyChanges()` and `logOnlyDirty()` to avoid noise.

## 8. Extension points — how to change/extend SAFELY

### Add a new spend category

1. **Update the model constant:** `MarketingSpend::CATEGORIES` in `/app/Models/MarketingSpend.php` (line 19).
2. **Update the migration:** Add the new value to the `category` enum in the existing migration or create a new migration with `$table->enum('category', [...])→change()`.
3. **Update the form:** The form in `MarketingSpendsRelationManager.form()` dynamically mapps `CATEGORIES` (line 39), so no change needed if you only touch the model constant.
4. **Test:** Add a test in `tests/Feature/MarketingSpendTest.php` that creates a spend with the new category.

### Change the default levy rate

1. **Edit** `MarketingSettings.levy_rate_percent` (default 5.0) in `/app/Settings/MarketingSettings.php`.
   - This changes the rate for all **future** charges and accruals.
   - **Do NOT change historical accruals or charges** — the rate is captured at creation time.
   - Verify with `MarketingScenarioTest::does not rewrite a historical accrual when the levy rate later changes` that this is correct.

### Allow hard cap on spending (reject overspends)

1. **Add a validator** in `MarketingSpendsRelationManager.form()` on the `amount` field:
   ```php
   ->maxValue(fn (?self $livewire) => $livewire->getOwnerRecord()->balance(), 'Cannot exceed remaining balance')
   ```
2. **Add a test** in `MarketingScenarioTest.php` that verifies the rejection.
3. **Update invariant rule 6** in § 3 to reflect that overspends are now blocked, not warned.

### Add a spend approval workflow

1. **Add a `status` enum column** to `marketing_spends` (e.g., 'draft'/'pending'/'approved').
2. **Add an observer** on `MarketingSpend` to keep `spent_amount` in sync only for approved spends:
   ```php
   protected static function booted() {
       $sync = fn (self $spend) => $spend->budget?->recomputeSpent();
       static::saved($sync);
       static::deleted($sync);
   }
   // Update recomputeSpent() to filter by status = 'approved'.
   ```
3. **Add actions** in the relation manager to approve/reject.
4. **Verify** existing tests still pass (recomputeSpent now filters).

### Auto-generate marketing charges on lease creation

1. **Add a Lease observer** (or use an existing one) to hook `created()` or `updated()`.
2. **Call** `app(MarketingLevyService::class)->createLevyCharge($lease)` when appropriate (e.g., when `status` becomes 'active').
3. **Test:** Add a scenario in `MarketingScenarioTest.php` that verifies the charge is auto-created.
   - **Gotcha:** Do NOT call this on every Lease save; it should be idempotent and only on status change or base_rent change.

### Integrate with accounting export (receipt references)

1. **Query spends with non-null `receipt_reference`** and export to accounting API.
2. **Consider adding a `receipt_status` column** ('pending_export', 'exported', 'reconciled') to track sync state.
3. **Keep marketing spends immutable after export** via an observer that prevents updates if receipt_status is not null.

## 9. Gotchas, edge cases & recently-fixed bugs

### Rate versioning is per-charge/accrual, not per-lease

The 5% rate is **captured at the moment** a Charge is created or an accrual happens. If the operator changes the rate from 5% to 8%:
- **Historical charges stay at 5%.** The Charge row's amount is fixed.
- **Historical accruals stay at 5%.** Already-accrued budgets don't change.
- **Future billing uses 8%.** The next invoice accrues at 8%.
- **Re-syncing an old lease's charge updates it.** Calling `MarketingLevyService::createLevyCharge()` again uses the current rate (updateOrCreate pattern).

**Test guard:** `MarketingScenarioTest::does not rewrite a historical accrual when the levy rate later changes` and `captures the rate on the marketing Charge so a later rate change does not alter it`.

### Pro-ration affects levy accrual

If a lease commences mid-month and is pro-rated, the billed rent is reduced (e.g., 16 of 31 days). The levy is 5% of the **pro-rated rent**, not the full month's rent.

**Example:** Lease commences 2026-03-16; March has 31 days. Base rent 31,000 EGP/month. Pro-rated: ~15,935 EGP. Accrued levy: 5% × 15,935 ≈ 796.75 EGP (not 1,550).

**Test guard:** `MarketingScenarioTest::accrues against the billed (pro-rated) rent, not the full monthly rent`.

### Null asset_id or missing commencement_date skips accrual silently

In `MonthlyBillingService::accrueMarketingLevy()`, if:
- `lease->unit->asset_id` is null, or
- `base_rent <= 0`, or
- Exception in `accrue()` call,

...the method logs a warning and **returns early**. The invoice is created successfully; only the accrual is skipped.

**Implication:** An invoice can exist with no corresponding accrual. The backfill command can partially recover, but manual inspection may be needed.

**Safeguard:** Ensure all leases have a valid unit with an asset before billing. The model constraints should enforce this, but schema gaps can occur.

### Overspend balance is negative, not an error

Spending more than accrued is **allowed.** The balance() method can return negative. This is a deliberate design choice (warn-but-allow, FR MKT-5 2026-06-25).

If a hard cap is ever needed, do NOT modify the model; add a form validator (see § 8, "Allow hard cap on spending").

### Soft-deleted spends are excluded from spent_amount

The `recomputeSpent()` method uses `spends()->sum()`, which **excludes soft-deleted records** (the relation does not load trashed by default).

If a spend is accidentally hard-deleted (via `forceDelete()`), the budget's `spent_amount` will be wrong. Soft-delete is enforced; check your codebase for any `forceDelete()` calls on spends.

### Budget without an asset due to cascade delete

If an Asset is deleted, all its Marketing Budgets cascade-delete. If an external system holds a reference to a marketing_budget_id, that reference becomes orphaned. Soft-deletes are used so audit logs remain queryable, but the row is logically gone.

**Mitigation:** Before deleting an asset, export/archive its budgets if external systems reference them.

### Zero or null accrued_amount at creation

When a MarketingBudget is manually created (via the form), it defaults to `accrued_amount = 0` and `spent_amount = 0`, so `balance() = 0`. No spends can be recorded against a zero-balance budget without overspending.

**Normal flow:** Budgets are **auto-provisioned** — `marketing:ensure-budgets` (daily) creates one per property per year, and `forPeriod()` lazily creates on first billed levy. There is **no manual create/delete** (canCreate/canDelete = false); users only record spends.

### Over-budget visibility + spend register export (UX, 2026-07-23)

Two owner-oversight gaps — the module exists so Jawad can see the operator stayed within the fund, and both were weak:

- **Over-budget was invisible.** The budget list's `balance` column was bold but uncoloured, so a property spent *past* its collected levy (negative balance) looked identical to a healthy one. Now the balance is **red when negative / green otherwise**, and a **"Over budget" filter** (`spent_amount > accrued_amount`) surfaces exactly the properties that need attention.
- **No spend register export.** Where the marketing fund actually went existed only on screen. Added an **Export CSV** header action on the spends relation manager (per budget) via the shared `App\Support\ReportCsv`. `MarketingBudgetResource::spendRegisterCsv($budget)` reads the budget's live spends (soft-deleted excluded, so the total **ties to `spent_amount`**) — date / category / description / amount / paid-from / receipt + a spend total. Gated on `marketing.view` (`visible()` + `authorize()`), shown only when the budget has spends.

## 10. Tests & related modules

### Tests

- **`tests/Feature/MarketingLevyTest.php`** — Service unit tests: rate, amount, charge creation, accrual.
- **`tests/Feature/MarketingBudgetTest.php`** — Budget model: balance derivation, forPeriod(), get-or-create.
- **`tests/Feature/MarketingSpendTest.php`** — Spend model: balance decrement, deletion recomputes, receipt reference.
- **`tests/Feature/Scenarios/MarketingScenarioTest.php`** — End-to-end scenarios: billing accrual, overspend, rate versioning, pro-ration, RBAC, scoping.
- **`tests/Feature/MarketingBudgetResourceTest.php`** — Filament resource: list rendering, relation manager rendering, RBAC, overspend warning.
- **`tests/Feature/MarketingAutowireTest.php`** — Integration with MonthlyBillingService: accrual doesn't add invoice line, backfill command.
- **`tests/Feature/Regression/MarketingSpendRegisterAndOverBudgetTest.php`** — the spend-register CSV total ties to `spent_amount`; the "Over budget" list filter shows only budgets spent past their accrued levy.

### Related modules

- **Charges & Billing** (`docs/modules/[charges doc]`): The marketing Charge is a first-class charge type; billing accrues the levy.
- **Leasing** (`docs/modules/[leasing doc]`): Base rent is the source of the levy calculation.
- **Assets/Properties** (`docs/modules/[assets doc]`): Budgets are scoped to each property.
- **Invoicing** (`docs/modules/[invoicing doc]`): MonthlyBillingService calls `accrueMarketingLevy()` after creating invoice items.
- **RBAC & Roles** (`docs/modules/[permissions doc]`): The `marketing` permission gates resource access.
- **General Ledger** ([module 21](21-general-ledger.md)): the levy posts as revenue via the invoice journalizer; **marketing spend posts as expense** via `MarketingSpendJournalizer` (Dr Marketing Expense / Cr Cash|Bank), swept by `accounting:sync-ledger`.

---

**Document version:** 2026-07-03 | **Tested on:** Laravel 13 + Filament 4 | Marketing spend → GL shipped
