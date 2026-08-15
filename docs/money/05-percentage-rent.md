# Percentage Rent & Tenant Sales Declarations — exhaustive reference

> **Audience:** finance/business team **and** engineers new to Atriom.
> **Scope:** how a tenant's monthly *sales declaration* turns into a *percentage-rent charge* on an invoice — every input, formula, rounding rule, state transition, lock/idempotency guard, and edge case.
> **Grounding:** every statement below cites the file (and line) that implements it, against the code as it stands today (after this session's money-hardening pass). If a statement and the code ever disagree, the code wins — file a doc fix.

Sibling docs you will want open alongside this one (all under `docs/money/`): `01-invoices-and-recompute.md`, `02-charges-and-billing-run.md`, `03-proration.md`, `04-cam-reconciliation.md`, `06-credit-notes.md`, `07-late-fees.md`, `08-marketing-levy.md`, `09-vat.md`. The canonical *module* doc (UI/permissions/notifications detail) is [`docs/modules/09-tenant-sales-percentage-rent.md`](../modules/09-tenant-sales-percentage-rent.md). Read [`docs/OVERVIEW.md`](../OVERVIEW.md) for the operator/owner/tenant vocabulary (Eltizam = operator, Jawad = owner, tenant = retailer).

---

## Plain-language summary

In Egyptian malls a retail lease often charges rent in **two parts**:

1. **Base rent** — a fixed monthly minimum the tenant always pays.
2. **Percentage rent** ("turnover rent") — an *extra* amount that kicks in only when the shop's monthly sales are high enough. The lease defines a **breakpoint** (a sales level) and a **rate** (a percentage). Sales above the breakpoint generate extra rent at that rate.

Because only the tenant knows their real sales, the workflow is:

1. **Tenant declares** their monthly sales in the portal (a `TenantSalesDeclaration`). The system *immediately* shows an estimated percentage-rent figure, but nothing is billed yet.
2. **Operator audits and locks** the declaration. Locking is the irreversible "we agree this number is final" step. At lock time the system creates a one-off **percentage-rent charge** on the lease.
3. **The next monthly billing run** picks that one-off charge up and puts it on the tenant's invoice for the matching month. Percentage rent is **VAT-exempt** (it is rent, like base rent).

If the operator thinks a number is wrong, they can **dispute** it (before lock — just a flag, nothing billed) or **void** it (after lock — deactivates the charge so it never bills, and flips the record to `disputed`). A voided/disputed declaration can be corrected and **re-locked**, which produces exactly one fresh active charge.

The whole point: rent compliance and owner cash-flow. Tenants cannot silently skip reporting (one declaration per lease per month, enforced by a DB unique key), and every figure carries an audit trail (who locked it, when, with what notes, plus a Spatie activity log).

---

## The exact rule / formula

### Which leases have percentage rent

A lease participates **only if `leases.has_percentage_rent = true`** (`app/Services/PercentageRentCalculationService.php:24`). The column is a non-null boolean defaulting to `false` both in the DB (`database/migrations/2024_01_01_000004_create_leases_table.php:36`) and in the in-memory model (`app/Models/Lease.php:61`, so a service-created lease never propagates `null` into the NOT-NULL column). If `has_percentage_rent` is false (or the lease is missing), `calculate()` returns `0.0` and **never** charges (`PercentageRentCalculationService.php:24-26`).

The three configuration columns on `leases` (all nullable, set on the lease form `app/Filament/Admin/Resources/Leases/Schemas/LeaseForm.php:239-270`):

| Column | DB type | Cast | Meaning | Form field |
|--------|---------|------|---------|-----------|
| `has_percentage_rent` | `boolean` default `false` | `boolean` (`Lease.php:77`) | Master on/off switch | Toggle, `live()` (`LeaseForm.php:245`) |
| `percentage_rent_calculation_type` | `enum('natural_breakpoint','artificial')` **nullable** (`database/migrations/2026_05_23_160331_*`) | — | Which formula. **Null defaults to `artificial`** at calculation time (`PercentageRentCalculationService.php:31`) | Select, form default `'artificial'` (`LeaseForm.php:248-251`) |
| `percentage_rent_threshold` | `decimal(12,2)` nullable | `decimal:2` (`Lease.php:74`) | The *artificial* breakpoint (sales below this owe nothing). Treated as `0` if null (`Service.php:37`) | EGP TextInput, `minValue(0)` (`LeaseForm.php:255-261`) |
| `percentage_rent_rate` | `decimal(5,2)` nullable | `decimal:2` (`Lease.php:75`) | Percentage, e.g. `5.00` = 5%. Form bounds `0–100` (`LeaseForm.php:266-267`) | `%`-suffixed TextInput (`LeaseForm.php:262-269`) |
| `base_rent_monthly` | `decimal(12,2)` | `decimal:2` (`Lease.php:70`) | Used **only** by the natural-breakpoint formula | — |

> **Renewal preservation:** `LeaseRenewalService` copies all four percentage-rent fields forward explicitly — `has_percentage_rent`, `percentage_rent_threshold`, `percentage_rent_rate`, `percentage_rent_calculation_type` (`app/Services/LeaseRenewalService.php:57-60`). This was a deliberate fix: omitting `calculation_type` on renewal silently reverted it to null. When you clone lease config anywhere, copy all four.

### The two formulas

Implemented in `PercentageRentCalculationService::calculate()` (`PercentageRentCalculationService.php:20-42`):

```
rate  = percentage_rent_rate / 100        // e.g. 5.00 → 0.05      (line 28)
sales = declared_sales                                              (line 29)
type  = percentage_rent_calculation_type ?? 'artificial'           (line 31)
```

**Artificial breakpoint** (the default, and the path taken whenever `type` is not exactly the string `'natural_breakpoint'`):

```
owed = (sales − threshold) × rate          // threshold defaults to 0 if null   (lines 37-38)
```

**Natural breakpoint** (`type === 'natural_breakpoint'`):

```
owed = (sales × rate) − base_rent_monthly                                        (lines 34-35)
```

The natural breakpoint is the sales level at which the percentage of sales would exactly equal the base rent (`base_rent / rate`); below it, percentage rent is zero; above it, the tenant pays the percentage of sales *minus* what base rent already covered.

**Final step, both formulas** (`PercentageRentCalculationService.php:41`):

```
return round( max(0.0, owed), 2 )
```

- **Floor at zero:** `max(0.0, owed)` guarantees no negative percentage rent. A tenant below threshold/breakpoint owes exactly **0.00** — never a credit.
- **Rounding:** PHP `round($x, 2)` — half-away-from-zero to 2 decimal places. This is applied to the **owed amount**, not to the rate or to sales.

### Inputs / outputs / sources at a glance

| Input | Source | Notes |
|-------|--------|-------|
| `declared_sales` | `tenant_sales_declarations.declared_sales` `decimal(14,2)` (`migration 2026_05_23_160330:16`) | The tenant's reported monthly trading sales, **VAT-exclusive** (the portal helper text instructs "exclusive of VAT" — `lang/en/admin/help.php` (`helpers.*`)) |
| `rate` | `leases.percentage_rent_rate` | Divided by 100 |
| `threshold` | `leases.percentage_rent_threshold` | Artificial only; `?? 0` |
| `base_rent_monthly` | `leases.base_rent_monthly` | Natural breakpoint only |
| `type` | `leases.percentage_rent_calculation_type` | `?? 'artificial'` |
| **Output** | `tenant_sales_declarations.calculated_percentage_rent` `decimal(14,2)` default `0` | Persisted by `recalculate()`/`lock()` |

No global config keys or settings rows feed this calculation — every input is a column on the lease or the declaration. Currency is hard-coded `EGP` on the generated charge (`PercentageRentCalculationService.php:156`).

### The submit → lock → bill flow (and where `calculated_percentage_rent` is set)

1. **Declare (submit).** Three entry points, all landing on `status = 'submitted'`:
   - **Portal** (tenant self-service): `CreateTenantSalesDeclaration` page sets `declared_by = Tenant`, `declared_at = now()`, `status = 'submitted'` (`app/Filament/Portal/.../Pages/CreateTenantSalesDeclaration.php:18-26`), then in `afterCreate()` calls `recalculate()` to populate `calculated_percentage_rent` immediately and fires `SalesDeclarationSubmittedNotification` to operator staff (`CreateTenantSalesDeclaration.php:28-55`). The lease select is filtered to the tenant's own `active` + `has_percentage_rent = true` leases (`app/Filament/Portal/.../Schemas/TenantSalesDeclarationForm.php:26-37`).
   - **Admin** (operator manually records a number): `TenantSalesDeclarationForm` + `EditTenantSalesDeclaration::afterSave()` re-runs `recalculate()` whenever the record is **not** locked (`app/Filament/Admin/.../Pages/EditTenantSalesDeclaration.php:25-30`).
   - **Mobile API** (`POST` via `CreateSalesDeclarationAction`): guards that the lease belongs to the tenant **and** `has_percentage_rent = true` (`app/Actions/Api/V1/Sales/CreateSalesDeclarationAction.php:30-39`), rejects a duplicate `(lease, period_start)` (`:41-49`), saves with `declared_by = Tenant` (`:51-60`), then `recalculate()` (`:64`). It does **not** lock and does **not** itself fan out the submitted notification (only the portal page does — `SalesDeclarationSubmittedNotification` is dispatched solely from `CreateTenantSalesDeclaration.php:45`).

   `recalculate()` (`PercentageRentCalculationService.php:47-53`) = `calculate()` + `save()`. It is a **preview**: it never locks and never creates a charge. So `calculated_percentage_rent` shown on a `submitted` row is an *estimate* that staff can still change by editing `declared_sales`.

2. **Lock** (operator action, `tenant_sales.lock` permission). `PercentageRentCalculationService::lock()` (`:61-111`), all inside one `DB::transaction`:
   - Idempotency guard: if already `locked`, return unchanged — no re-charge, no second notification (`:63-65`).
   - Recompute `owed = calculate()` and persist `calculated_percentage_rent`, `status='locked'`, `locked_at=now()`, `locked_by_user_id`, `audit_notes` (`:68-76`).
   - **Defense-in-depth de-dupe:** deactivate any prior *active* `percentage_rent` charge for the same `(lease_id, start_date = period_start)` so a re-lock can never leave two active charges (`:81-86`).
   - If `owed > 0`, create the one-off charge (`:88-90`, `createPercentageRentCharge()` at `:150-165`). If `owed == 0`, **no charge** is created but the lock still happens.
   - Notify the tenant via `SalesDeclarationLockedNotification` (mail + database + push), wrapped in try/catch so a notification failure never rolls back the lock (`:95-107`).

3. **Bill.** The one-off charge has `frequency = 'one_time'`, `start_date = period_start`, `end_date = period_end` (`:158-164`). The monthly billing run (`app/Services/MonthlyBillingService.php`) only includes a `one_time` charge in the month where its `start_date` falls between the run's `periodStart` and `periodEnd` (`MonthlyBillingService.php:295-296`). So the percentage-rent charge appears on the invoice for the **same calendar month as the sales period** — e.g. a charge for the May sales period bills on the May invoice (issued when that month's run executes). It is rendered as a `percentage_rent` line item, **VAT 0** because the charge is created with `vat_applicable = false, vat_rate = 0` (`:159-160`), so it adds to the invoice `subtotal` with no VAT (`MonthlyBillingService.php:221-243`). The invoice's money fields are then the single source of truth via `Invoice::recomputeTotals()` (see `docs/money/01-invoices-and-recompute.md`).

> **Timing nuance that matters operationally.** Because the charge bills in the *period_start month* and tenants typically declare the *previous* month's sales (portal defaults `period_start` to the first of last month — `TenantSalesDeclarationForm.php:45`), the operator must **lock before that month's billing run executes** for the charge to land on that month's invoice. If the period's month has already been billed, the period-overlap idempotency guard (`MonthlyBillingService.php:75-83`) means the run will **skip** that lease for that month and the charge will **not** retroactively attach — it would only bill if a later run covers a month whose window includes `start_date`, which for a past-dated `one_time` charge never happens. Practical rule: **lock the prior month's declarations early in the new month, before the monthly run.** (See Edge cases.)

---

## Worked examples (real numbers)

### Example A — Artificial breakpoint, above threshold

Lease config: `has_percentage_rent = true`, `type = 'artificial'`, `threshold = 50,000`, `rate = 5.00`.
Tenant declares May sales of **EGP 100,000**.

```
rate  = 5.00 / 100               = 0.05
owed  = (100,000 − 50,000) × 0.05 = 50,000 × 0.05 = 2,500.00
final = round(max(0, 2,500.00), 2) = 2,500.00 EGP
```

On lock, a one-off charge `amount = 2,500.00`, `vat_applicable = false`, `start_date = 2026-05-01`, `end_date = 2026-05-31` is created. On the May invoice it appears as a line `Percentage Rent — May 2026 - May 2026`, subtotal +2,500.00, VAT +0.00, total +2,500.00.

### Example B — Artificial breakpoint, below threshold (zero, but still locks)

Same lease as A. Tenant declares May sales of **EGP 40,000**.

```
owed  = (40,000 − 50,000) × 0.05 = −10,000 × 0.05 = −500.00
final = round(max(0, −500.00), 2) = 0.00 EGP
```

`calculated_percentage_rent = 0.00`. On lock: `status='locked'`, `locked_at` stamped, **no charge created** (`owed > 0` is false). The tenant is **still notified** — "Percentage rent owed: EGP 0.00" — because a verified zero is itself an audit result (`PercentageRentCalculationService.php:92-107`).

### Example C — Natural breakpoint

Lease config: `type = 'natural_breakpoint'`, `rate = 8.00`, `base_rent_monthly = 10,000`. (Threshold is ignored in this mode.)
Tenant declares **EGP 200,000**.

```
rate  = 8.00 / 100              = 0.08
owed  = (200,000 × 0.08) − 10,000 = 16,000 − 10,000 = 6,000.00
final = 6,000.00 EGP
```

The implied natural breakpoint here is `base_rent / rate = 10,000 / 0.08 = 125,000`; sales of 200,000 are 75,000 above it, and `75,000 × 0.08 = 6,000` — same answer, confirming the formula.

### Example D — Fractional rate, 2-dp rounding

Lease config: `type = 'artificial'`, `threshold = 0`, `rate = 2.50`.
Tenant declares **EGP 33,333**.

```
rate  = 2.50 / 100 = 0.025
owed  = (33,333 − 0) × 0.025 = 833.325
final = round(833.325, 2) = 833.33 EGP
```

### Example E — Re-lock after void

Continue Example A (charge of 2,500 was active). Operator voids it (wrong number): the 2,500 charge is set `is_active=false, end_date=now()`, status → `disputed`. Operator corrects `declared_sales` and locks again. Lock recomputes, the lock-time de-dupe (`:81-86`) finds no *active* prior charge (the voided one is inactive), and creates exactly **one** fresh active charge. Net result: two historical charge rows, **one** active.

---

## Every edge case + how the system handles it

| Edge case | Behaviour | Where |
|-----------|-----------|-------|
| Lease has `has_percentage_rent = false` (or no lease) | `calculate()` returns `0.0`; lock is harmless, no charge ever | `Service.php:24-26` |
| `percentage_rent_calculation_type` is null | Falls back to `'artificial'` | `Service.php:31` |
| `percentage_rent_threshold` is null (artificial) | Treated as `0` (so `owed = sales × rate`) | `Service.php:37` |
| Sales below threshold / breakpoint | `owed` floored to `0.00`; on lock the record locks but **no charge** | `Service.php:41`, `:88-90` |
| `owed` is exactly 0 on lock | Still locks, still notifies tenant (amount EGP 0.00), no charge | `Service.php:88-107` |
| Locking an already-locked declaration | No-op, returns record unchanged; **no second charge, no second notification** | `Service.php:63-65` |
| Re-lock from `disputed` (after a void) | Lock-time de-dupe deactivates any leftover active charge, then creates exactly one fresh active charge | `Service.php:81-90` |
| Voiding a non-`locked` declaration | No-op (belt-and-braces; UI also gates to `status='locked'`) | `Service.php:124-126` |
| Two periods declared on the same lease | Each charge is bounded by its own `start_date`/`end_date`; void/de-dupe match on `whereDate('start_date', period_start)` so a sibling period's charge is never touched | `Service.php:84`, `:132-135` |
| Duplicate `(lease, period_start)` submission | Rejected: DB unique key `tenant_sales_lease_period_unique` (`migration 2026_05_23_160330:27`); API guard (`CreateSalesDeclarationAction.php:41-49`); admin + portal form `->unique(...)` rules (`Admin TenantSalesDeclarationForm.php:41-48`, `Portal TenantSalesDeclarationForm.php:46-52`) |
| Notification (locked or submitted) throws | Caught + logged at warning; the lock/charge/declaration persists regardless | `Service.php:101-106`; `CreateTenantSalesDeclaration.php:49-54` |
| Period month already billed when you lock | The monthly run's period-overlap guard skips an already-billed lease/month, so a past-dated `one_time` charge won't retroactively attach. **Lock before the run.** | `MonthlyBillingService.php:75-83`, `:295-296` |
| Admin edits a `submitted` declaration's sales | `afterSave()` re-runs `recalculate()` so the estimate stays in sync (only while not locked) | `EditTenantSalesDeclaration.php:25-30` |
| Editing a `locked` declaration | The status select and `calculated_percentage_rent` are `disabled()`+`dehydrated(false)` (raw status writes are blocked so billing is never skipped); transitions go only through lock/dispute/void actions | `Admin TenantSalesDeclarationForm.php:62-79` |
| Tenant (portal) tries to lock | Not possible — portal table exposes **View only**, no lock/dispute/void actions (`Portal .../Tables/TenantSalesDeclarationsTable.php:54-56`). Only `Portal::isAdmin()` tenant users may even submit |
| Negative declared sales | Blocked at the form (`minValue(0)`, `numeric()`) on admin, portal, and API validation |
| Declaration created for a non-pct-rent lease directly (bypassing guards) | Service computes `0.0`, never charges — safe but audit noise; the API/portal guards prevent it in practice |

---

## Invariants + gotchas

- **Percentage rent is VAT-exempt.** The generated charge is always `vat_applicable = false, vat_rate = 0` (`Service.php:159-160`). It is rent. Do not "fix" this — base rent and percentage rent are both VAT-exempt; only service charges carry 14% VAT (project invariant; see `docs/money/09-vat.md`).
- **`Invoice::recomputeTotals()` is the only writer of invoice money.** The percentage-rent charge flows in as a line item; never set `paid_amount`/`balance` directly. See `docs/money/01-invoices-and-recompute.md`.
- **`calculate()` is pure.** No DB writes, no side effects, deterministic given lease config + declared sales. `recalculate()` adds a `save()`. `lock()`/`voidLocked()` add the transactional charge mutations. Keep this layering.
- **Exactly one active charge per locked declaration.** Enforced two ways: `voidLocked()` deactivates the period-matched charge before re-lock; and `lock()` itself deactivates any leftover active period charge before creating a new one (`Service.php:81-86`). Never create percentage-rent charges by hand.
- **Lock is the irreversible commitment; void is the only way back.** A locked declaration is immutable via the edit form; `voidLocked()` is the sanctioned reversal (deactivate charge → `is_active=false, end_date=now()`, status → `disputed`, append a dated/operator-stamped audit note — `Service.php:128-147`).
- **Dispute ≠ Void.** `dispute` acts on a `submitted` row (a pre-lock flag; no charge ever existed — `Admin table action :100-118`). `voidLocked` acts on a `locked` row (a real charge gets deactivated). Both land on `status='disputed'`; the discriminator for "was anything ever billed?" is `locked_at`.
- **Idempotency keys are the status guards.** `lock()` short-circuits on `status==='locked'`; `voidLocked()` short-circuits on `status!=='locked'`. The DB unique `(lease_id, period_start)` is the idempotency key for submission. The monthly run's period-overlap check is the idempotency key for billing.
- **Charge period matching uses `whereDate('start_date', period_start)`** — a same-day match, not a range — both for the lock-time de-dupe and for void (`Service.php:84`, `:134`). That is why two periods on one lease stay independent.
- **Audit trail is real.** `TenantSalesDeclaration` logs `status, declared_sales, calculated_percentage_rent, locked_at, audit_notes` via Spatie activity log under log name `tenant_sales` (`TenantSalesDeclaration.php:43-50`); `locked_by_user_id` records the operator; `voidLocked` appends a human-readable, dated note.
- **Soft deletes.** Both `TenantSalesDeclaration` and `Lease` use `SoftDeletes`; declarations are never hard-deleted in normal flow.
- **Gotcha — declared sales are VAT-exclusive.** Tenants are instructed to enter trading sales *exclusive of VAT* (`lang/en/admin/help.php` (`helpers.*`)). If a tenant enters VAT-inclusive sales the percentage rent over-states. This is a data-entry contract, not a code guard.
- **Gotcha — the module doc once claimed the API action notifies staff; it does not.** `CreateSalesDeclarationAction` only recalculates; the submitted-notification fan-out lives in the portal `CreateTenantSalesDeclaration` page (`:44-48`). An API-only submission produces no staff bell entry today.

---

## Where it lives in the code (file:line index)

**Calculation + lifecycle service**
- `app/Services/PercentageRentCalculationService.php`
  - `calculate()` formulas + floor + round — `:20-42`
  - `recalculate()` (preview, persists `calculated_percentage_rent`) — `:47-53`
  - `lock()` (idempotent; recompute, lock, de-dupe, charge, notify) — `:61-111`
  - `voidLocked()` (deactivate charge, status→disputed, audit note) — `:122-148`
  - `createPercentageRentCharge()` (one-off, VAT-free, period-bounded) — `:150-165`

**Model + schema**
- `app/Models/TenantSalesDeclaration.php` — fillable/casts `:19-41`, activity log `:43-50`, `isLocked()`/`isDisputed()`/`periodLabel()` `:67-80`, `STATUSES` `:17`
- `database/migrations/2026_05_23_160330_create_tenant_sales_declarations_table.php` — columns + unique `(lease_id, period_start)` + `(status, period_start)` index
- `database/migrations/2026_05_23_160331_add_percentage_rent_calculation_type_to_leases.php` — the enum column
- `database/migrations/2024_01_01_000004_create_leases_table.php:36-38` — `has_percentage_rent`, `percentage_rent_threshold`, `percentage_rent_rate`
- `app/Models/Lease.php` — fillable `:47-50`, default `has_percentage_rent=false` `:61`, casts `:74-77`, `salesDeclarations()` `:166-169`
- `app/Models/Charge.php` — the percentage-rent charge row; `calculateVat()`/`totalWithVat()` `:52-63`
- `database/migrations/2024_01_01_000005_create_charges_table.php:15-22` — `percentage_rent` charge type

**Billing pickup**
- `app/Services/MonthlyBillingService.php` — `one_time` applicability `:295-296`; line-item build + VAT `:221-243`; period-overlap idempotency `:75-83`, `:136-143`
- `app/Enums/InvoiceItemType.php:18` — `PercentageRent` line-item type

**Entry points**
- `app/Actions/Api/V1/Sales/CreateSalesDeclarationAction.php` — API submit + guards + recalc
- `app/Filament/Portal/Resources/TenantSalesDeclarations/Pages/CreateTenantSalesDeclaration.php` — portal submit + recalc + staff notification
- `app/Filament/Portal/.../Schemas/TenantSalesDeclarationForm.php` — portal form (lease filter, period defaults, unique rule)
- `app/Filament/Admin/.../Schemas/TenantSalesDeclarationForm.php` — admin form (disabled status/calc fields)
- `app/Filament/Admin/.../Pages/EditTenantSalesDeclaration.php:25-30` — recalc on save when not locked
- `app/Filament/Admin/.../Tables/TenantSalesDeclarationsTable.php` — `lock` `:73-99`, `dispute` `:100-118`, `voidLocked` `:124-147` actions + permission gates
- `app/Filament/Portal/.../Tables/TenantSalesDeclarationsTable.php:54-56` — View-only (tenants cannot lock)

**Notifications**
- `app/Notifications/SalesDeclarationLockedNotification.php` — mail + database + push to tenant
- `app/Notifications/SalesDeclarationSubmittedNotification.php` — database to operator staff

**Renewal / permissions**
- `app/Services/LeaseRenewalService.php:57-60` — carries all four pct-rent fields forward
- `database/seeders/RolesPermissionsSeeder.php:106-112` — `tenant_sales.*` permissions; manager grant `:246`

**Lease form config**
- `app/Filament/Admin/Resources/Leases/Schemas/LeaseForm.php:239-270` — the percentage-rent section

**Tests** (regression/scenario suites)
- `tests/Feature/Services/PercentageRentCalculationServiceTest.php`
- `tests/Feature/Services/PercentageRentVoidLockedTest.php`
- `tests/Feature/Scenarios/PercentageRentScenarioTest.php`
- `tests/Feature/Regression/RenewalPercentageRentTypeTest.php`

---

## Related

- [`docs/money/01-invoices-and-recompute.md`](01-invoices-and-recompute.md) — `Invoice::recomputeTotals()`, the single source of truth for invoice money.
- [`docs/money/02-charges-and-billing-run.md`](02-charges-and-billing-run.md) — how `Charge` rows (including the `one_time` percentage-rent charge) flow into invoices; the run lock.
- [`docs/money/03-proration.md`](03-proration.md) — mid-month commencement proration (does **not** apply to the percentage-rent one-off charge, which always bills at full `amount`).
- [`docs/money/04-cam-reconciliation.md`](04-cam-reconciliation.md) — the sibling "lock then true-up" pattern (positive true-up → charge; negative → credit note).
- [`docs/money/06-credit-notes.md`](06-credit-notes.md) — credit-note locking/reversal/auto-apply mechanics.
- [`docs/money/07-late-fees.md`](07-late-fees.md) · [`docs/money/08-marketing-levy.md`](08-marketing-levy.md) · [`docs/money/09-vat.md`](09-vat.md).
- [`docs/modules/09-tenant-sales-percentage-rent.md`](../modules/09-tenant-sales-percentage-rent.md) — full module reference (UI, permissions, notifications, extension points).
- [`docs/modules/04-leases.md`](../modules/04-leases.md) — lease config + renewal preservation of percentage-rent terms.
